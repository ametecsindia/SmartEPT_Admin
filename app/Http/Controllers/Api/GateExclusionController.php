<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\Team;
use App\Models\User;
use App\Services\GateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GATE EXCLUSIONS — the console's one place to see every standing exception to
 * Gate-to-PC (Ejaz, 18-Aug-2026).
 *
 * The settings themselves live on the org rows (branches / departments / teams /
 * employees / employee_devices) and are written through those resources' own endpoints,
 * which already carry the right permissions and audit trail. What was missing was the
 * READ across all five: an admin had no way to answer "who in this company can currently
 * sign in without punching, and until when?" without opening every record one by one.
 * That is exactly how a temporary exception survives for a year unnoticed.
 *
 * This controller only lists. It writes nothing.
 */
class GateExclusionController extends Controller
{
    /**
     * Tenant calendar day, not UTC (EPT-20) — SCHEDULED/ACTIVE/EXPIRED here must agree
     * with what GateService actually enforces, or the screen lies for the ~5.5h either
     * side of midnight in Asia/Kolkata.
     */
    private string $tz = 'UTC';

    /** GET /api/gate-exclusions — every level that carries a gate_mode, with live status. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // A SUPER_ADMIN has no company_id — the models' own tenant scope already unscopes
        // for them, so filtering on a null id would return nothing (and an abort_unless on
        // it would 403 the very role the route grants). They may name a tenant explicitly.
        $companyId = $user->isSuperAdmin()
            ? ($request->integer('company_id') ?: null)
            : $user->company_id;

        abort_if(! $companyId && ! $user->isSuperAdmin(), 422,
            'Sign in as a company administrator to view gate exclusions.');

        $scope = fn ($q) => $companyId ? $q->where('company_id', $companyId) : $q;

        $this->tz = ($companyId ? Company::find($companyId)?->timezone : null)
            ?: config('app.timezone', 'UTC');

        $rows = collect();

        foreach ([['BRANCH', Branch::class], ['DEPARTMENT', Department::class], ['TEAM', Team::class]] as [$level, $model]) {
            $scope($model::query()->whereNotNull('gate_mode'))->get()
                ->each(fn ($r) => $rows->push($this->row($level, $r->id, $r->name, $r)));
        }

        $scope(Employee::query()->whereNotNull('gate_mode'))->get()
            ->each(fn ($e) => $rows->push($this->row(
                'EMPLOYEE', $e->id, trim($e->first_name . ' ' . $e->last_name) . ' (' . $e->employee_code . ')', $e
            )));

        $scope(EmployeeDevice::query()->whereNotNull('gate_mode'))->get()
            ->each(fn ($d) => $rows->push($this->row(
                'DEVICE', $d->id, $d->computer_name ?: $d->device_uuid, $d
            )));

        // Who granted each one, resolved in a single query rather than per row.
        $names = User::query()->whereIn('id', $rows->pluck('granted_by_user_id')->filter()->unique())
            ->pluck('name', 'id');
        $rows = $rows->map(fn ($r) => $r + ['granted_by' => $names[$r['granted_by_user_id']] ?? null]);

        $company = $companyId ? Company::find($companyId) : null;

        return response()->json([
            'data' => $rows->sortBy([['level_order', 'asc'], ['name', 'asc']])->values(),
            'meta' => [
                // Without this the list is misleading: exclusions from a gate that isn't
                // switched on do nothing, and the screen must say so rather than imply
                // people are being let through.
                'gate_enabled' => $company ? app(GateService::class)->enabledFor($company) : false,
                'attendance_mode' => $company?->attendance_mode ?? 'BIOMETRIC',
                'biometric_gate' => $company?->biometric_gate ?? 'auto',
                'timezone' => $this->tz,
                'today' => Carbon::now($this->tz)->toDateString(),
            ],
        ]);
    }

    private function row(string $level, int $id, ?string $name, object $r): array
    {
        $from = $r->gate_mode_from ? Carbon::parse($r->gate_mode_from)->toDateString() : null;
        $until = $r->gate_mode_until ? Carbon::parse($r->gate_mode_until)->toDateString() : null;
        $today = Carbon::now($this->tz)->toDateString();

        return [
            'level' => $level,
            'level_order' => array_search($level, GateService::EXCLUSION_LEVELS, true),
            'id' => $id,
            'name' => $name,
            'gate_mode' => strtoupper((string) $r->gate_mode),
            'from' => $from,
            'until' => $until,
            'reason' => $r->gate_mode_reason,
            'granted_by_user_id' => $r->gate_mode_by_user_id,
            // SCHEDULED / EXPIRED are the two states an admin most needs to see at a
            // glance — "why isn't my exclusion working" is almost always one of them.
            'status' => match (true) {
                $from && $today < $from => 'SCHEDULED',
                $until && $today > $until => 'EXPIRED',
                default => 'ACTIVE',
            },
        ];
    }
}
