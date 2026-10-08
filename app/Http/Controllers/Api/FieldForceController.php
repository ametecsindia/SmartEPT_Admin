<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeComplianceEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Field-force location trail from the Caller mobile app (6-Oct-2026), public API v1.
 *
 *   POST /api/v1/fieldforce/locations   (api-key:ingest)  store GPS fixes
 *   GET  /api/v1/fieldforce/locations   (api-key:read)    ?employee_code=&date=
 *
 * Enforcement: when an employee's fixes cross OUT of their SmartPRS geofence a
 * GEOFENCE_EXIT compliance event is raised (category DEVICE), so it appears in the
 * existing Violations feed, reports and alerts with no new screen. Coming back
 * inside resolves that event. The verdict (geo_status) is computed by the Caller
 * server from the SmartPRS rule; SmartEPT stores it as reported.
 *
 * Company comes from the API key, never the body — same rule as ingestPunches.
 */
class FieldForceController extends Controller
{
    public const MAX_BATCH = 1000;

    public function ingest(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('api_company_id');
        $data = $request->validate([
            'locations'                 => ['required', 'array', 'min:1', 'max:' . self::MAX_BATCH],
            'locations.*.external_id'   => ['required', 'string', 'max:96'],
            'locations.*.employee_code' => ['required', 'string', 'max:64'],
            'locations.*.captured_at'   => ['required', 'date'],
            'locations.*.lat'           => ['required', 'numeric', 'between:-90,90'],
            'locations.*.lng'           => ['required', 'numeric', 'between:-180,180'],
            'locations.*.accuracy_m'    => ['nullable', 'numeric', 'min:0'],
            'locations.*.battery_pct'   => ['nullable', 'integer', 'between:0,100'],
            'locations.*.geo_status'    => ['nullable', 'in:within,outside,no-rule'],
            'locations.*.distance_km'   => ['nullable', 'numeric', 'min:0'],
            'locations.*.limit_km'      => ['nullable', 'numeric', 'min:0'],
        ]);

        $codes = collect($data['locations'])->pluck('employee_code')->map(fn ($c) => Str::upper(trim($c)))->unique()->all();
        $employees = Employee::withoutGlobalScope('company')->where('company_id', $companyId)
            ->where('employment_status', 'ACTIVE')->whereIn(DB::raw('UPPER(employee_code)'), $codes)
            ->get(['id', 'company_id', 'employee_code'])->keyBy(fn ($e) => Str::upper(trim($e->employee_code)));

        // Oldest first, so exit/return events are raised in the order they happened.
        $rows = collect($data['locations'])->sortBy(fn ($l) => Carbon::parse($l['captured_at'])->getTimestamp())->values();
        $stored = 0;
        $unknown = [];
        foreach ($rows as $l) {
            $emp = $employees[Str::upper(trim($l['employee_code']))] ?? null;
            if (! $emp) {
                $unknown[$l['employee_code']] = true;
                continue;
            }
            $at = Carbon::parse($l['captured_at']);
            $prev = DB::table('field_force_locations')->where('company_id', $companyId)->where('employee_id', $emp->id)
                ->where('captured_at', '<', $at)->orderByDesc('captured_at')->value('geo_status');

            $inserted = DB::table('field_force_locations')->insertOrIgnore([
                'company_id' => $companyId, 'employee_id' => $emp->id, 'employee_code' => $emp->employee_code,
                'external_id' => trim($l['external_id']), 'captured_at' => $at,
                'lat' => $l['lat'], 'lng' => $l['lng'], 'accuracy_m' => $l['accuracy_m'] ?? null,
                'battery_pct' => $l['battery_pct'] ?? null, 'geo_status' => $l['geo_status'] ?? null,
                'distance_km' => $l['distance_km'] ?? null, 'source' => 'CALLER',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $inserted) {
                continue;   // re-delivery
            }
            $stored++;
            $this->enforce($emp, $prev, $l, $at);
        }

        return response()->json(['ok' => true, 'received' => count($data['locations']), 'stored' => $stored,
            'unknown_employee_codes' => array_keys($unknown)], 202);
    }

    public function trail(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('api_company_id');
        $date = (string) $request->query('date', now()->toDateString());
        $rows = DB::table('field_force_locations')->where('company_id', $companyId)
            ->whereDate('captured_at', $date)
            ->when($request->query('employee_code'), fn ($q, $c) => $q->whereRaw('UPPER(employee_code) = ?', [Str::upper($c)]))
            ->orderBy('captured_at')->limit(5000)
            ->get(['employee_code', 'captured_at', 'lat', 'lng', 'accuracy_m', 'battery_pct', 'geo_status', 'distance_km']);

        return response()->json(['date' => $date, 'count' => $rows->count(), 'locations' => $rows]);
    }

    /** within → outside raises GEOFENCE_EXIT; outside → within resolves the open one. */
    private function enforce(Employee $emp, ?string $prev, array $l, Carbon $at): void
    {
        $now = $l['geo_status'] ?? null;
        if ($now === 'outside' && $prev !== 'outside') {
            EmployeeComplianceEvent::withoutGlobalScopes()->create([
                'company_id' => $emp->company_id, 'employee_id' => $emp->id, 'device_uuid' => 'CALLER',
                'event_type' => 'GEOFENCE_EXIT', 'event_category' => 'DEVICE', 'severity' => 'MEDIUM',
                'description' => 'Field employee left the allowed area (SmartPRS geofence).',
                'detected_value' => isset($l['distance_km']) ? round((float) $l['distance_km'], 2) . ' km from start point' : 'outside',
                'expected_value' => isset($l['limit_km']) ? 'within ' . round((float) $l['limit_km'], 2) . ' km' : 'within geofence',
                'action_taken' => 'Logged',
                'started_at' => $at,
                'metadata' => ['lat' => $l['lat'], 'lng' => $l['lng'], 'source' => 'CALLER'],
            ]);
        } elseif ($now === 'within' && $prev === 'outside') {
            EmployeeComplianceEvent::withoutGlobalScopes()->where('company_id', $emp->company_id)->where('employee_id', $emp->id)
                ->where('event_type', 'GEOFENCE_EXIT')->whereNull('resolved_at')->update(['resolved_at' => $at]);
        }
    }
}
