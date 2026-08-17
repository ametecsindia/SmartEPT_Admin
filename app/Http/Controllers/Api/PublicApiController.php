<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\InstallationLicense;
use App\Services\OutboundPusher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SmartEPT PUBLIC API (v1) — API-key authenticated. This is the documented
 * surface any external device or app integrates against:
 *   - ingest attendance punches INTO SmartEPT
 *   - read attendance OUT of SmartEPT (punch-pair / worked / break detail)
 *   - read the employee roster so a bridge can map codes without a human
 * The company is resolved from the API key (never trusted from the body).
 *
 * Primary caller: Smart Biometric Bridge (SBB), an on-premise Windows service
 * that forwards punches from any vendor's reader. SBB is AT-LEAST-ONCE, so
 * ingest is idempotent on a caller-supplied external_id.
 *
 * 16-Aug-2026 rewrite. The old version wrote ONLY employee_attendance_logs, with
 * source 'API' — a value the enum does not contain, so every punch for an
 * employee with no existing row 500'd and lost the batch (it passed every demo,
 * where the agent had already created the row). And because it never wrote
 * biometric_logs, Gate-to-PC could not see the punch and locked out employees
 * who had genuinely punched. It now stores the raw punch FIRST and fans out
 * through the same three helpers the push API, CSV import and cloud sync use —
 * mergeIntoAttendance / processGatePunches / deriveAttendance — so all four
 * ingest paths behave identically and QA Phase 3 derivation applies here too.
 */
class PublicApiController extends Controller
{
    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('api_company_id');
    }

    /** GET /api/v1/ping — verify a key, the customer it points at, and the clock. */
    public function ping(Request $request): JsonResponse
    {
        $key = $request->attributes->get('api_key');
        $companyId = $this->companyId($request);
        $company = Company::find($companyId);

        return response()->json([
            'ok' => true,
            'app' => 'SmartEPT',
            'version' => config('smartept.version', '1.0'),
            'company_id' => $companyId,
            // An installer that only sees "company_id: 7" cannot confirm they
            // pointed the bridge at the right customer.
            'company_name' => $company?->name,
            // Lets the bridge detect a clock/zone mismatch BEFORE it corrupts a
            // month of attendance.
            'timezone' => $company?->timezone ?: config('app.timezone'),
            'key' => $key->prefix,
            'scopes' => $key->scopes,
            'key_expires_at' => optional($key->expires_at)->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'licence' => $this->licence($company),
        ]);
    }

    /**
     * POST /api/v1/attendance/punches  (scope: ingest)
     *
     * Body: { punches: [ {
     *   employee_code, punch_type: IN|OUT|BREAK_IN|BREAK_OUT, punched_at,
     *   external_id?, source?, device_id?, verification_mode?,
     *   direction_confidence?, device_status_raw?
     * } ] }
     */
    public function ingestPunches(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        $data = $request->validate([
            'punches'                        => ['required', 'array', 'min:1', 'max:5000'],
            'punches.*.employee_code'        => ['required', 'string', 'max:64'],
            // Real readers emit four directions, and biometric_logs stores all
            // four — the public contract now matches the internal one.
            'punches.*.punch_type'           => ['required', 'in:IN,OUT,BREAK_IN,BREAK_OUT'],
            'punches.*.punched_at'           => ['required', 'date'],
            // Deterministic per-punch fingerprint: the same punch re-delivered
            // yields the same value, which is how a re-delivery is told apart
            // from a genuine second punch.
            'punches.*.external_id'          => ['nullable', 'string', 'max:96'],
            'punches.*.source'               => ['nullable', 'string', 'max:32'],
            'punches.*.device_id'            => ['nullable', 'integer'],
            'punches.*.verification_mode'    => ['nullable', 'in:FINGERPRINT,FACE,CARD,PIN'],
            // A face terminal that reports status 255 does not know whether the
            // punch was an entry or an exit; the bridge must be able to say so.
            'punches.*.direction_confidence' => ['nullable', 'in:HIGH,MEDIUM,NONE'],
            'punches.*.device_status_raw'    => ['nullable', 'string', 'max:64'],
        ]);

        $punches = $data['punches'];

        // ---- employee resolution -------------------------------------------
        // withoutGlobalScope('company') and NOT withoutGlobalScopes(): the latter
        // also strips SoftDeletingScope, which resurrected deleted employees.
        // employment_status filters relieved staff. Codes are matched
        // case-insensitively on both sides, like BiometricCloudSync does.
        $codes = collect($punches)->pluck('employee_code')
            ->map(fn ($c) => Str::upper(trim((string) $c)))->unique()->values();

        $employees = Employee::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('employment_status', 'ACTIVE')
            ->whereIn(DB::raw('UPPER(employee_code)'), $codes->all())
            ->get(['id', 'employee_code', 'biometric_id']);

        $byCode = $employees->keyBy(fn ($e) => Str::upper(trim((string) $e->employee_code)));

        // device_id is only honoured when it is one of THIS company's devices —
        // otherwise the FK would blow up the batch or leak a cross-tenant id.
        $deviceIds = array_map('intval', BiometricDevice::withoutGlobalScope('company')
            ->where('company_id', $companyId)->pluck('id')->all());

        // ---- duplicate detection -------------------------------------------
        $offered = collect($punches)->pluck('external_id')
            ->map(fn ($v) => is_string($v) ? trim($v) : null)
            ->filter()->unique()->values()->all();

        $already = $offered
            ? array_flip(BiometricLog::withoutGlobalScopes()->where('company_id', $companyId)
                ->whereIn('external_id', $offered)->pluck('external_id')->all())
            : [];
        $seen = [];

        $rows = [];
        $results = [];
        $unknown = [];
        $duplicates = 0;
        $accepted = 0;
        $now = now();

        foreach ($punches as $i => $p) {
            $code = Str::upper(trim((string) $p['employee_code']));
            $employee = $byCode[$code] ?? null;
            $external = isset($p['external_id']) && trim((string) $p['external_id']) !== ''
                ? trim((string) $p['external_id'])
                : null;

            if ($external !== null && (isset($already[$external]) || isset($seen[$external]))) {
                $duplicates++;
                $results[] = ['index' => $i, 'external_id' => $external, 'status' => 'duplicate'];
                continue; // a re-delivery is not an error
            }
            if ($external !== null) {
                $seen[$external] = true;
            }

            if (! $employee) {
                $unknown[trim((string) $p['employee_code'])] = true;
                Log::warning('SBB punch rejected: unknown or inactive employee code', [
                    'company_id' => $companyId, 'api_key' => $key->prefix,
                    'employee_code' => $p['employee_code'], 'punched_at' => $p['punched_at'],
                ]);
            } else {
                $accepted++;
            }

            $deviceId = isset($p['device_id']) && in_array((int) $p['device_id'], $deviceIds, true)
                ? (int) $p['device_id']
                : null;

            if (isset($p['device_id']) && $deviceId === null) {
                Log::warning('SBB punch: unknown device_id ignored', [
                    'company_id' => $companyId, 'device_id' => $p['device_id'],
                ]);
            }

            $metadata = array_filter([
                // The caller's own source string (device serial) survives here —
                // employee_attendance_logs.source is a closed enum and cannot
                // carry it.
                'source'               => $p['source'] ?? null,
                'direction_confidence' => $p['direction_confidence'] ?? null,
                'device_status_raw'    => $p['device_status_raw'] ?? null,
                'ingested_via'         => 'PUBLIC_API_V1',
                'api_key_prefix'       => $key->prefix,
            ], fn ($v) => $v !== null && $v !== '');

            $rows[] = [
                'company_id'            => $companyId,
                'biometric_device_id'   => $deviceId,
                'biometric_employee_id' => (string) ($employee?->biometric_id ?: ($employee?->employee_code ?? trim((string) $p['employee_code']))),
                'employee_id'           => $employee?->id,
                'punch_type'            => $p['punch_type'],
                'punched_at'            => Carbon::parse($p['punched_at']),
                'verification_mode'     => $p['verification_mode'] ?? null,
                'external_id'           => $external,
                'metadata'              => $metadata ? json_encode($metadata) : null,
                'processed'             => true,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];

            $results[] = [
                'index' => $i,
                'external_id' => $external,
                'status' => $employee ? 'accepted' : 'unknown_employee',
            ];
        }

        // ---- store, then fan out through the shared ingest path --------------
        $skipped = [];

        if ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                // insertOrIgnore + unique(company_id, external_id) closes the
                // race two concurrent re-deliveries would otherwise win.
                BiometricLog::insertOrIgnore($chunk);
            }

            $skipped = BiometricController::mergeIntoAttendance($companyId, $rows);
            BiometricController::processGatePunches($companyId, $rows);
            // QA Phase 3 (B7/B8): shift-aware checkout + configurable late. Without
            // this call an SBB-fed customer got PRESENT/ABSENT only.
            BiometricController::deriveAttendance($companyId, $rows);
        }

        $skippedManual = collect($skipped)->map(function ($s) use ($employees) {
            $emp = $employees->firstWhere('id', $s['employee_id']);

            return [
                'employee_code'   => $emp?->employee_code,
                'date'            => $s['date'],
                'existing_source' => $s['source'],
                'existing_status' => $s['status'],
                'reason'          => 'HR owns this day — machine punches do not modify it.',
            ];
        })->values()->all();

        $touched = $this->touchedRows($companyId, $rows);

        Log::info('SBB punch batch ingested', [
            'company_id' => $companyId,
            'api_key' => $key->prefix,
            'offered' => count($punches),
            'stored' => count($rows),
            'duplicates' => $duplicates,
            'attendance_rows_touched' => $touched,
            'skipped_manual' => count($skippedManual),
            'unknown_employee_codes' => array_keys($unknown),
            'ip' => $request->ip(),
        ]);

        // Controller::audit() reads $request->user(), which is null under key
        // auth — the row would have had no company. Written directly instead.
        AuditLog::create([
            'company_id'   => $companyId,
            'user_id'      => null,
            'action'       => 'INGEST',
            'subject_type' => BiometricLog::class,
            'subject_id'   => null,
            'changes'      => [
                'api_key' => $key->prefix,
                'offered' => count($punches),
                'stored' => count($rows),
                'duplicates' => $duplicates,
                'attendance_rows_touched' => $touched,
                'skipped_manual' => count($skippedManual),
                'unknown_employee_codes' => array_keys($unknown),
            ],
            'ip'           => $request->ip(),
            'user_agent'   => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json([
            'ok' => true,
            'received' => count($punches),
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'attendance_rows_touched' => $touched,
            'unknown_employee_codes' => array_keys($unknown),
            'skipped_manual' => $skippedManual,
            'punches' => $results,
            'licence' => $this->licence(Company::find($companyId)),
        ], 202);
    }

    /** How many attendance rows now exist for the employee-days this batch touched. */
    private function touchedRows(int $companyId, array $rows): int
    {
        $pairs = [];
        foreach ($rows as $r) {
            if (($r['employee_id'] ?? null) === null) {
                continue;
            }
            $pairs[$r['employee_id'] . '|' . $r['punched_at']->toDateString()] = [
                (int) $r['employee_id'], $r['punched_at']->toDateString(),
            ];
        }
        if (! $pairs) {
            return 0;
        }

        $count = 0;
        foreach ($pairs as [$employeeId, $date]) {
            $count += EmployeeAttendanceLog::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->whereDate('work_date', $date)
                ->where('source', '!=', 'MANUAL')
                ->count() > 0 ? 1 : 0;
        }

        return $count;
    }

    /**
     * GET /api/v1/attendance?date=YYYY-MM-DD[&employee_code=]  (scope: read)
     * Punch-pair / worked / break detail for outbound consumers to PULL.
     */
    public function readAttendance(Request $request, OutboundPusher $pusher): JsonResponse
    {
        $companyId = $this->companyId($request);
        $date = $request->query('date', now()->toDateString());

        $payload = $pusher->attendancePayload($companyId, $date);
        if ($code = $request->query('employee_code')) {
            $payload['records'] = array_values(array_filter($payload['records'], fn ($r) => $r['employee_code'] === $code));
            $payload['count'] = count($payload['records']);
        }

        return response()->json($payload);
    }

    /**
     * GET /api/v1/employees  (scope: read)
     *
     * The roster a bridge needs to map reader ids to SmartEPT codes on its own.
     * Without it every installation needed a human in the console mapping people
     * by hand — the admin mapping endpoints sit behind auth:sanctum plus a role
     * gate, and 'unmapped' reads $request->user()->company_id, which is null
     * under API-key auth, so it could never have served a bridge.
     */
    public function employees(Request $request): JsonResponse
    {
        $rows = Employee::withoutGlobalScope('company')
            ->where('company_id', $this->companyId($request))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('employment_status', 'ACTIVE'))
            ->orderBy('employee_code')
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'biometric_id', 'employment_status'])
            ->map(fn ($e) => [
                'employee_code' => $e->employee_code,
                'name'          => $e->fullName(),
                'biometric_id'  => $e->biometric_id,
                'status'        => $e->employment_status,
            ])->values();

        return response()->json(['count' => $rows->count(), 'data' => $rows]);
    }

    /**
     * The licence verdict, reported (never enforced) on the public API. SBB is a
     * free bridge and must not become a licence enforcer, so punches keep being
     * accepted. But a customer whose licence lapsed was previously told
     * 202 {"ok":true} while every screen that could show the data was blocked —
     * the integration reported success into a black hole.
     *
     * Uses the per-tenant governing licence and reads the cached record only:
     * no call to Central on the ingest path.
     */
    private function licence(?Company $company): array
    {
        $l = InstallationLicense::governing($company);

        if (! $l->configured()) {
            $left = $l->evaluationDaysLeft();

            return $left > 0
                ? ['state' => 'evaluation', 'since' => null,
                    'message' => "Evaluation licence — {$left} day(s) left. Punches are accepted."]
                : ['state' => 'evaluation_expired', 'since' => $l->evaluationEndsAt()->toDateString(),
                    'message' => 'The evaluation period has ended. Punches are accepted but reports are locked until a licence key is entered.'];
        }

        $expires = $l->expiresAt();
        $status = (string) ($l->status ?: 'unknown');

        if ($status === 'active' && $l->operational()) {
            return ['state' => 'active', 'since' => $expires?->toDateString(),
                'message' => 'Licence active.'];
        }

        if ($status === 'expired') {
            return ['state' => 'expired', 'since' => $expires?->toDateString(),
                'message' => $l->withinGrace()
                    ? 'The licence has expired and is inside its grace window. Punches are accepted; renew before the grace window closes.'
                    : 'Punches are accepted but reports are locked.'];
        }

        return ['state' => $status, 'since' => $expires?->toDateString(),
            'message' => 'Punches are accepted but reports are locked (licence ' . $status . ').'];
    }
}
