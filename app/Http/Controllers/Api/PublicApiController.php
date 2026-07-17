<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Services\OutboundPusher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * SmartEPT PUBLIC API (v1) — API-key authenticated. This is the documented
 * surface any external device or app integrates against:
 *   - ingest attendance punches INTO SmartEPT
 *   - read attendance OUT of SmartEPT (punch-pair / worked / break detail)
 * The company is resolved from the API key (never trusted from the body).
 */
class PublicApiController extends Controller
{
    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('api_company_id');
    }

    /** GET /api/v1/ping — verify a key + see which company it belongs to. */
    public function ping(Request $request): JsonResponse
    {
        $key = $request->attributes->get('api_key');
        return response()->json([
            'ok' => true,
            'app' => 'SmartEPT',
            'company_id' => $this->companyId($request),
            'key' => $key->prefix,
            'scopes' => $key->scopes,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/attendance/punches  (scope: ingest)
     * Body: { punches: [ { employee_code, punch_type: IN|OUT, punched_at, device_id?, source? } ] }
     * Folds into the day's attendance row: earliest-IN / latest-OUT wins, so a
     * gate punch marks the employee present without overwriting agent times.
     */
    public function ingestPunches(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $data = $request->validate([
            'punches'                 => ['required', 'array', 'min:1', 'max:5000'],
            'punches.*.employee_code' => ['required', 'string', 'max:64'],
            'punches.*.punch_type'    => ['required', 'in:IN,OUT'],
            'punches.*.punched_at'    => ['required', 'date'],
            'punches.*.source'        => ['nullable', 'string', 'max:32'],
        ]);

        $codes = collect($data['punches'])->pluck('employee_code')->map(fn ($c) => trim($c))->unique();
        $map = Employee::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereIn('employee_code', $codes)->pluck('id', 'employee_code');

        $byDay = [];
        $unknown = [];
        foreach ($data['punches'] as $p) {
            $eid = $map[trim($p['employee_code'])] ?? null;
            if (! $eid) { $unknown[trim($p['employee_code'])] = true; continue; }
            $at = Carbon::parse($p['punched_at']);
            $key = $eid . '|' . $at->toDateString();
            $byDay[$key]['source'] = $p['source'] ?? 'API';
            if ($p['punch_type'] === 'IN') {
                $byDay[$key]['in'] = ! isset($byDay[$key]['in']) || $at->lt($byDay[$key]['in']) ? $at : $byDay[$key]['in'];
            } else {
                $byDay[$key]['out'] = ! isset($byDay[$key]['out']) || $at->gt($byDay[$key]['out']) ? $at : $byDay[$key]['out'];
            }
        }

        $touched = 0;
        foreach ($byDay as $key => $v) {
            [$eid, $date] = explode('|', $key);
            $a = EmployeeAttendanceLog::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('employee_id', (int) $eid)
                ->whereDate('work_date', $date)->first();
            if (! $a) {
                EmployeeAttendanceLog::create([
                    'company_id' => $companyId, 'employee_id' => (int) $eid, 'work_date' => $date,
                    'source' => 'API', 'status' => 'PRESENT',
                    'check_in_at' => $v['in'] ?? null, 'check_out_at' => $v['out'] ?? null,
                ]);
                $touched++;
                continue;
            }
            $u = [];
            if (isset($v['in']) && (! $a->check_in_at || $v['in']->lt($a->check_in_at))) $u['check_in_at'] = $v['in'];
            if (isset($v['out']) && (! $a->check_out_at || $v['out']->gt($a->check_out_at))) $u['check_out_at'] = $v['out'];
            if ($u) { $a->update($u); $touched++; }
        }

        return response()->json([
            'ok' => true,
            'attendance_rows_touched' => $touched,
            'unknown_employee_codes' => array_keys($unknown),
        ], 202);
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
}
