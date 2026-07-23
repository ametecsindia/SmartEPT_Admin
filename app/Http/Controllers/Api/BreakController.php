<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EmployeeBreakLog;
use App\Services\ConflictingStatusException;
use App\Services\StatusService;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class BreakController extends Controller
{
    use ResolvesAgentContext;

    /**
     * POST /api/agent/break-event
     *
     * START opens a break; END closes it. Hardened 17-Jul after the first live
     * agent run (Ejaz clicked Tea repeatedly with no UI feedback and 15
     * duplicate opens piled up):
     * - START is IDEMPOTENT: an open break of the same type is returned as-is,
     *   never duplicated. Starting a DIFFERENT type first closes the open one
     *   (you are on exactly one break at a time).
     * - END closes EVERY open break for the employee, whatever its type — the
     *   agent's "End current break" button must always work, even for TEA.
     */
    public function store(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid'  => ['required', 'string'],
            'action'       => ['required', 'in:START,END'],
            'break_type'   => ['nullable', 'in:TEA,LUNCH,BIO,MEETING,TRAINING,PRAYER,CUSTOM'],
            'source'       => ['nullable', 'in:MANUAL,AUTO_IDLE,BIOMETRIC'],
            'occurred_at'  => ['nullable', 'date'],
            // Section 3: the agent sends the mandatory reason when the break ran over.
            'delay_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->agentDevice($request, $employee);
        $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
        $type = $data['break_type'] ?? 'CUSTOM';

        if ($data['action'] === 'START') {
            $open = EmployeeBreakLog::where('employee_id', $employee->id)
                ->whereNull('end_at')->latest('start_at')->get();

            // Same-type break already running → this click is a duplicate.
            $same = $open->firstWhere('break_type', $type);
            if ($same) {
                return response()->json(['ok' => true, 'break_id' => $same->id, 'deduped' => true], 200);
            }

            // QA Phase 1 (D1): the status timeline is the exclusivity authority. A MANUAL
            // break while a DIFFERENT break/meeting is open is REJECTED (409) — nothing is
            // written until the employee ends the current one. Non-manual sources
            // (AUTO_IDLE / BIOMETRIC) may still switch. Do this BEFORE any legacy write so
            // a rejected click leaves no partial state.
            $manual = ($data['source'] ?? 'MANUAL') === 'MANUAL';
            $timelineState = match ($type) {
                'TEA'   => 'TEA_BREAK',
                'LUNCH' => 'LUNCH_BREAK',
                default => 'OTHER_BREAK',
            };
            try {
                app(StatusService::class)->transition($employee, $timelineState, $at, [
                    'device_uuid' => $data['device_uuid'],
                    'manual'      => $manual,
                    'source'      => $manual ? 'AGENT' : 'BIOMETRIC',
                ]);
            } catch (ConflictingStatusException $c) {
                return response()->json(['error' => [
                    'code'    => 'STATUS_CONFLICT',
                    'message' => 'End your current break or meeting first.',
                    'active'  => $c->activePayload(),
                ]], 409);
            }

            // Switching break types: close whatever is open first (one break at a time).
            foreach ($open as $o) {
                $o->update([
                    'end_at' => $at,
                    'duration_seconds' => $o->start_at ? (int) $at->diffInSeconds($o->start_at, true) : null,
                ]);
            }

            $break = EmployeeBreakLog::create([
                'company_id'  => $employee->company_id,
                'employee_id' => $employee->id,
                'device_uuid' => $data['device_uuid'],
                'break_type'  => $type,
                'source'      => $data['source'] ?? 'MANUAL',
                'start_at'    => $at,
                'approval_status' => 'NOT_REQUIRED',
            ]);

            return response()->json(['ok' => true, 'break_id' => $break->id], 201);
        }

        // END: close ALL open breaks regardless of the type the agent sent. The server
        // recomputes the permitted vs actual duration authoritatively (Section 3 — never
        // trust the UI alone) and records the excess + the reason. A break that ran over
        // its limit with NO reason is flagged PENDING for the admin to chase.
        $company = Company::withoutGlobalScopes()->find($employee->company_id);
        $reason = trim((string) ($data['delay_reason'] ?? ''));
        $overWithoutReason = [];

        $closed = 0;
        EmployeeBreakLog::where('employee_id', $employee->id)
            ->whereNull('end_at')->get()
            ->each(function ($o) use ($at, &$closed, $company, $reason, &$overWithoutReason) {
                $dur = $o->start_at ? (int) $at->diffInSeconds($o->start_at, true) : null;
                $permitted = $this->permittedSecondsFor($company, $o->break_type);
                $excess = ($permitted && $dur !== null) ? max(0, $dur - $permitted) : 0;

                $update = [
                    'end_at'            => $at,
                    'duration_seconds'  => $dur,
                    'permitted_seconds' => $permitted,
                    'excess_seconds'    => $excess,
                ];
                if ($excess > 0) {
                    if ($reason !== '') {
                        $update['delay_reason'] = $reason;
                    }
                    // Reason present → still surfaced for optional review; missing → chase it.
                    $update['review_status'] = 'PENDING';
                    if ($reason === '') {
                        $overWithoutReason[] = $o->break_type;
                    }
                }

                $o->update($update);
                $closed++;
            });

        // QA Phase 1 (dual-write): ending a break returns the employee to ACTIVE in the
        // timeline — but only if they were actually on a break, so a stray END never ends
        // an open meeting.
        try {
            $status = app(StatusService::class);
            if (in_array($status->currentState($employee), StatusService::BREAK_STATES, true)) {
                $status->resumeActive($employee, $at, ['device_uuid' => $data['device_uuid']]);
            }
        } catch (\Throwable $e) {
            Log::warning('StatusService mirror failed on break END', ['e' => $e->getMessage()]);
        }

        return response()->json([
            'ok'      => true,
            'closed'  => $closed,
            // If any closed break was over-limit and no reason came with it, tell the
            // agent so it can prompt (belt-and-braces; the agent normally prompts first).
            'reason_required' => ! empty($overWithoutReason),
            'over_types'      => $overWithoutReason,
        ], 200);
    }

    /** Section 3: permitted seconds for a break type, from this company's limits. */
    private function permittedSecondsFor(?Company $company, ?string $type): ?int
    {
        if (! $company) {
            return null;
        }
        // "Other" is stored as CUSTOM. BIO/TRAINING/PRAYER/MEETING have no break limit.
        $min = match ($type) {
            'LUNCH'  => $company->break_limit_lunch_min ?? 30,
            'TEA'    => $company->break_limit_tea_min ?? 10,
            'CUSTOM' => $company->break_limit_other_min ?? 10,
            default  => null,
        };

        return $min !== null ? (int) $min * 60 : null;
    }
}
