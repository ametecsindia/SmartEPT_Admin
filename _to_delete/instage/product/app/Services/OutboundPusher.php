<?php

namespace App\Services;

use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\IntegrationTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Outbound integration push (Ejaz 17-Jul): SmartEPT feeds attendance to
 * SmartPRS or any other app. The payload is signed HMAC-SHA256 over the raw
 * JSON body with the target's secret, in the X-SmartEPT-Signature header, so
 * the receiver can verify authenticity. Best-effort: a target being down never
 * throws into the caller; the outcome is recorded on the target row.
 */
class OutboundPusher
{
    /** Build the canonical daily-attendance payload for one company + date. */
    public function attendancePayload(int $companyId, string $date): array
    {
        $rows = EmployeeAttendanceLog::withoutGlobalScopes()
            ->with('employee:id,employee_code,first_name,last_name,department_id,team_id,branch_id')
            ->where('company_id', $companyId)
            ->whereDate('work_date', $date)
            ->get();

        $breaks = EmployeeBreakLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('start_at', $date)
            ->get()
            ->groupBy('employee_id');

        $records = $rows->map(function ($a) use ($breaks) {
            $in = $a->check_in_at ? Carbon::parse($a->check_in_at) : null;
            $out = $a->check_out_at ? Carbon::parse($a->check_out_at) : null;
            $bs = $breaks->get($a->employee_id, collect());
            $breakSecs = (int) $bs->sum(fn ($b) => $b->duration_seconds ?? 0);
            $worked = ($in && $out) ? max(0, $out->diffInSeconds($in) - $breakSecs) : 0;

            return [
                'employee_code' => $a->employee?->employee_code,
                'employee_name' => trim(($a->employee?->first_name . ' ' . $a->employee?->last_name)),
                'work_date'     => (string) $a->work_date,
                'status'        => $a->status,
                'first_in'      => optional($in)->toIso8601String(),
                'last_out'      => optional($out)->toIso8601String(),
                'worked_seconds' => $worked,
                'break_seconds'  => $breakSecs,
                'punch_pairs'    => $bs->count() ? null : null, // reserved for pair detail
                'source'         => $a->source,
            ];
        })->values()->all();

        return [
            'event'      => 'attendance.daily',
            'company_id' => $companyId,
            'date'       => $date,
            'generated_at' => now()->toIso8601String(),
            'count'      => count($records),
            'records'    => $records,
        ];
    }

    /** POST a signed payload to one target. Returns [ok, status]. */
    public function pushTo(IntegrationTarget $target, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig = $target->secret ? hash_hmac('sha256', $body, $target->secret) : null;

        try {
            $res = Http::withHeaders(array_filter([
                'Content-Type' => 'application/json',
                'X-SmartEPT-Signature' => $sig,
                'X-SmartEPT-Event' => $payload['event'] ?? null,
            ]))->withBody($body, 'application/json')->timeout(15)->post($target->url);

            $status = $res->status() . ' ' . ($res->successful() ? 'OK' : substr(strip_tags($res->body()), 0, 60));
            $target->forceFill(['last_pushed_at' => now(), 'last_status' => $status])->saveQuietly();

            return ['ok' => $res->successful(), 'status' => $status];
        } catch (\Throwable $e) {
            $status = 'ERROR ' . substr($e->getMessage(), 0, 80);
            $target->forceFill(['last_pushed_at' => now(), 'last_status' => $status])->saveQuietly();

            return ['ok' => false, 'status' => $status];
        }
    }

    /** Push a date's attendance to every active target subscribed to attendance.daily. */
    public function pushAttendanceForDate(int $companyId, string $date): array
    {
        $payload = $this->attendancePayload($companyId, $date);
        $out = [];
        foreach (IntegrationTarget::withoutGlobalScopes()->where('company_id', $companyId)->where('active', true)->get() as $target) {
            $events = $target->events ?: ['attendance.daily'];
            if (! in_array('attendance.daily', $events, true)) {
                continue;
            }
            $out[] = ['target' => $target->name] + $this->pushTo($target, $payload);
        }
        return $out;
    }
}
