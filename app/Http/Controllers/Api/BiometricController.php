<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Validation\Rule;
use App\Models\BiometricEmployeeMapping;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeLoginSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Biometric integration: ingest punches (middleware push or CSV import), map biometric IDs
 * to SmartEPT employees, and reconcile biometric attendance against system login.
 */
class BiometricController extends Controller
{
    /** POST /api/integrations/biometric/logs — middleware push of punch logs. */
    public function pushLogs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'logs'                       => ['required', 'array', 'min:1', 'max:2000'],
            'logs.*.biometric_employee_id' => ['required', 'string', 'max:64'],
            'logs.*.punch_type'          => ['nullable', 'in:IN,OUT,BREAK_IN,BREAK_OUT'],
            'logs.*.punched_at'          => ['required', 'date'],
            'logs.*.verification_mode'   => ['nullable', 'in:FINGERPRINT,FACE,CARD,PIN'],
            'logs.*.device_id'           => ['nullable', 'integer'],
        ]);

        $stored = $this->ingest($request, $data['logs']);
        $this->audit($request, 'CREATE', BiometricLog::class, null, ['count' => $stored]);

        return response()->json(['ok' => true, 'stored' => $stored], 202);
    }

    /** GET /api/integrations/biometric/logs — query stored punches. */
    public function getLogs(Request $request): JsonResponse
    {
        $logs = BiometricLog::with('employee:id,employee_code,first_name,last_name')
            ->when($request->date, fn ($q, $v) => $q->whereDate('punched_at', $v))
            ->when($request->employee_id, fn ($q, $v) => $q->where('employee_id', $v))
            ->latest('punched_at')
            ->paginate((int) $request->integer('per_page', 50));

        return response()->json($logs);
    }

    /** POST /api/integrations/biometric/import — CSV upload (biometric_employee_id,punch_type,punched_at). */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);

        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath()));
        $header = array_map(fn ($h) => strtolower(trim($h)), array_shift($rows) ?: []);
        $logs = [];
        foreach ($rows as $r) {
            if (count($r) < 2) continue;
            $row = array_combine($header, array_pad($r, count($header), null));
            $logs[] = [
                'biometric_employee_id' => $row['biometric_employee_id'] ?? $row['bio_id'] ?? ($r[0] ?? null),
                'punch_type'            => strtoupper($row['punch_type'] ?? 'IN'),
                'punched_at'            => $row['punched_at'] ?? ($r[2] ?? null),
                'verification_mode'     => $row['verification_mode'] ?? null,
            ];
        }
        $logs = array_values(array_filter($logs, fn ($l) => $l['biometric_employee_id'] && $l['punched_at']));

        $stored = $this->ingest($request, $logs);
        $this->audit($request, 'IMPORT', BiometricLog::class, null, ['count' => $stored]);

        return response()->json(['ok' => true, 'stored' => $stored], 202);
    }

    /** POST /api/integrations/biometric/map-employee — map biometric ID → employee. */
    public function mapEmployee(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $data = $request->validate([
            'biometric_employee_id' => ['required', 'string', 'max:64'],
            'employee_id'           => ['required', 'integer', Rule::exists('employees', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'biometric_device_id'   => ['nullable', 'integer'],
            'force'                 => ['nullable', 'boolean'],
        ]);

        // Section 9 rule: one employee holds ONE active biometric identity per company.
        // If they already own a DIFFERENT active biometric ID, warn (409) and only
        // proceed on an explicit force — then retire the old mapping.
        $conflict = BiometricEmployeeMapping::where('employee_id', $data['employee_id'])
            ->where('active', true)
            ->where('biometric_employee_id', '!=', $data['biometric_employee_id'])
            ->first();

        if ($conflict && ! $request->boolean('force')) {
            return response()->json([
                'error' => [
                    'code'    => 'EMPLOYEE_ALREADY_MAPPED',
                    'message' => "This employee is already mapped to biometric ID {$conflict->biometric_employee_id}.",
                    'existing_biometric_employee_id' => $conflict->biometric_employee_id,
                ],
            ], 409);
        }
        if ($conflict) {
            $conflict->update(['active' => false]);
        }

        // The DB unique(company_id, biometric_employee_id) guarantees a biometric ID
        // maps to exactly one row; updateOrCreate is company-scoped by the model.
        $map = BiometricEmployeeMapping::updateOrCreate(
            ['biometric_employee_id' => $data['biometric_employee_id']],
            ['employee_id' => $data['employee_id'], 'biometric_device_id' => $data['biometric_device_id'] ?? null, 'active' => true]
        );

        // Backfill any previously-unmapped logs.
        BiometricLog::withoutGlobalScopes()
            ->where('company_id', $map->company_id)
            ->where('biometric_employee_id', $data['biometric_employee_id'])
            ->whereNull('employee_id')
            ->update(['employee_id' => $data['employee_id']]);

        $this->audit($request, 'ASSIGN', BiometricEmployeeMapping::class, $map->id, $data);

        return response()->json(['ok' => true, 'mapping_id' => $map->id], 201);
    }

    /** GET /api/integrations/biometric/mappings — current active biometric↔employee links. */
    public function mappings(Request $request): JsonResponse
    {
        $rows = BiometricEmployeeMapping::with('employee:id,employee_code,first_name,last_name')
            ->where('active', true)
            ->orderBy('biometric_employee_id')
            ->get()
            ->map(fn ($m) => [
                'id'                    => $m->id,
                'biometric_employee_id' => $m->biometric_employee_id,
                'employee_id'           => $m->employee_id,
                'employee_name'         => $m->employee?->fullName(),
                'biometric_device_id'   => $m->biometric_device_id,
            ]);

        return response()->json(['data' => $rows]);
    }

    /** GET /api/integrations/biometric/unmapped — distinct biometric IDs seen in punches but not linked. */
    public function unmapped(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $rows = BiometricLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('employee_id')
            ->selectRaw('biometric_employee_id, COUNT(*) as punches, MAX(punched_at) as last_seen')
            ->groupBy('biometric_employee_id')
            ->orderByDesc('punches')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'biometric_employee_id' => $r->biometric_employee_id,
                'punches'               => (int) $r->punches,
                'last_seen'             => $r->last_seen,
            ]);

        return response()->json(['data' => $rows]);
    }

    /** PUT /api/integrations/biometric/mappings/{mapping} — re-point a mapping to another employee. */
    public function updateMapping(Request $request, BiometricEmployeeMapping $mapping): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'force'       => ['nullable', 'boolean'],
        ]);

        // Guard the one-employee-one-biometric rule here too (skip this same row).
        $conflict = BiometricEmployeeMapping::where('employee_id', $data['employee_id'])
            ->where('active', true)
            ->where('id', '!=', $mapping->id)
            ->first();
        if ($conflict && ! $request->boolean('force')) {
            return response()->json([
                'error' => [
                    'code'    => 'EMPLOYEE_ALREADY_MAPPED',
                    'message' => "This employee is already mapped to biometric ID {$conflict->biometric_employee_id}.",
                ],
            ], 409);
        }
        if ($conflict) {
            $conflict->update(['active' => false]);
        }

        $mapping->update(['employee_id' => $data['employee_id'], 'active' => true]);

        // Re-point existing punches for this biometric ID to the new employee.
        BiometricLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('biometric_employee_id', $mapping->biometric_employee_id)
            ->update(['employee_id' => $data['employee_id']]);

        $this->audit($request, 'UPDATE', BiometricEmployeeMapping::class, $mapping->id, $data);

        return response()->json(['ok' => true]);
    }

    /** DELETE /api/integrations/biometric/mappings/{mapping} — remove a mapping (punch history kept). */
    public function deleteMapping(Request $request, BiometricEmployeeMapping $mapping): JsonResponse
    {
        $this->audit($request, 'DELETE', BiometricEmployeeMapping::class, $mapping->id, [
            'biometric_employee_id' => $mapping->biometric_employee_id,
        ]);
        $mapping->delete();

        return response()->json(null, 204);
    }

    /**
     * GET /api/reports/biometric-mismatch?date=
     * Compares the first biometric IN punch with the first system login per employee.
     */
    public function mismatch(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        $punches = BiometricLog::where('punch_type', 'IN')->whereDate('punched_at', $date)
            ->whereNotNull('employee_id')
            ->selectRaw('employee_id, MIN(punched_at) as first_punch')
            ->groupBy('employee_id')->pluck('first_punch', 'employee_id');

        $logins = EmployeeLoginSession::whereDate('login_at', $date)
            ->selectRaw('employee_id, MIN(login_at) as first_login')
            ->groupBy('employee_id')->pluck('first_login', 'employee_id');

        $ids = collect($punches->keys())->merge($logins->keys())->unique();
        $names = Employee::whereIn('id', $ids)->get()->keyBy('id');

        $rows = $ids->map(function ($id) use ($punches, $logins, $names) {
            $punch = isset($punches[$id]) ? Carbon::parse($punches[$id]) : null;
            $login = isset($logins[$id]) ? Carbon::parse($logins[$id]) : null;
            $diff = ($punch && $login) ? (int) $punch->diffInMinutes($login) : null;

            return [
                'employee_id'  => $id,
                'name'         => $names[$id]?->fullName(),
                'biometric_in' => $punch?->toDateTimeString(),
                'system_login' => $login?->toDateTimeString(),
                'diff_minutes' => $diff,
                'status'       => $this->mismatchStatus($punch, $login, $diff),
            ];
        })->values();

        return response()->json(['date' => $date, 'data' => $rows]);
    }

    private function ingest(Request $request, array $logs): int
    {
        $companyId = $request->user()->company_id;
        $maps = BiometricEmployeeMapping::withoutGlobalScopes()->where('company_id', $companyId)->where('active', true)
            ->pluck('employee_id', 'biometric_employee_id');

        $now = now();
        $rows = [];
        foreach ($logs as $l) {
            $rows[] = [
                'company_id'           => $companyId,
                'biometric_device_id'  => $l['device_id'] ?? null,
                'biometric_employee_id' => (string) $l['biometric_employee_id'],
                'employee_id'          => $maps[$l['biometric_employee_id']] ?? null,
                'punch_type'           => $l['punch_type'] ?? 'IN',
                'punched_at'           => Carbon::parse($l['punched_at']),
                'verification_mode'    => $l['verification_mode'] ?? null,
                'processed'            => true,
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }
        if ($rows) {
            BiometricLog::insert($rows);
            self::mergeIntoAttendance($companyId, $rows);

            // Biometric Gate (Doc 11 v1.1): every mapped punch drives the gate engine
            // (auto-break on mid-day OUT, close/merge/flag on return IN). Punches are
            // processed in time order so out→in pairs resolve correctly.
            self::processGatePunches($companyId, $rows);
        }
        return count($rows);
    }

    /**
     * Biometric Gate v1.1 fan-out: feed mapped punches (any ingest path — push API,
     * CSV import, cloud sync) through GateService in time order. Public static so
     * BiometricCloudSync uses the exact same path as the direct integrations.
     */
    public static function processGatePunches(int $companyId, array $rows): void
    {
        $gate = app(\App\Services\GateService::class);
        $mapped = array_filter($rows, fn ($r) => ($r['employee_id'] ?? null) !== null);
        usort($mapped, fn ($a, $b) => $a['punched_at'] <=> $b['punched_at']);

        foreach ($mapped as $r) {
            $gate->processPunch($companyId, (int) $r['employee_id'], $r['punch_type'], \Illuminate\Support\Carbon::parse($r['punched_at']));
        }
    }

    /**
     * Fold mapped punches into the day's attendance row so a punch at the gate marks
     * the employee PRESENT even without the desktop agent. Merge rule: earliest-in /
     * latest-out wins — a biometric punch never overwrites an EARLIER agent check-in
     * (the employee was already working) nor a LATER agent check-out.
     * Public static: BiometricCloudSync feeds cloud-imported punches through the
     * exact same merge, so every ingest path behaves identically.
     */
    public static function mergeIntoAttendance(int $companyId, array $rows): void
    {
        $byDay = [];
        foreach ($rows as $r) {
            if (! $r['employee_id']) {
                continue; // unmapped punches are reconciled later via map-employee backfill
            }
            $key = $r['employee_id'] . '|' . $r['punched_at']->toDateString();
            if ($r['punch_type'] === 'IN') {
                $in = $byDay[$key]['in'] ?? null;
                $byDay[$key]['in'] = (! $in || $r['punched_at']->lessThan($in)) ? $r['punched_at'] : $in;
            } elseif ($r['punch_type'] === 'OUT') {
                $out = $byDay[$key]['out'] ?? null;
                $byDay[$key]['out'] = (! $out || $r['punched_at']->greaterThan($out)) ? $r['punched_at'] : $out;
            }
        }

        foreach ($byDay as $key => $punches) {
            [$employeeId, $date] = explode('|', $key);
            $firstIn = $punches['in'] ?? null;
            $lastOut = $punches['out'] ?? null;

            $attendance = EmployeeAttendanceLog::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('employee_id', (int) $employeeId)
                ->whereDate('work_date', $date)
                ->first();

            if (! $attendance) {
                EmployeeAttendanceLog::create([
                    'company_id'   => $companyId,
                    'employee_id'  => (int) $employeeId,
                    'work_date'    => $date,
                    'source'       => 'BIOMETRIC',
                    'status'       => 'PRESENT',
                    'check_in_at'  => $firstIn,
                    'check_out_at' => $lastOut,
                ]);
                continue;
            }

            $updates = [];
            if ($firstIn && (! $attendance->check_in_at || $firstIn->lessThan($attendance->check_in_at))) {
                $updates['check_in_at'] = $firstIn;
            }
            if ($lastOut && (! $attendance->check_out_at || $lastOut->greaterThan($attendance->check_out_at))) {
                $updates['check_out_at'] = $lastOut;
            }
            if ($updates) {
                $attendance->update($updates);
            }
        }
    }

    private function mismatchStatus($punch, $login, $diff): string
    {
        if (! $punch && $login) return 'NO_BIOMETRIC';
        if ($punch && ! $login) return 'NO_SYSTEM_LOGIN';
        if ($diff === null) return 'UNKNOWN';
        return abs($diff) > 15 ? 'MISMATCH' : 'OK';
    }
}
