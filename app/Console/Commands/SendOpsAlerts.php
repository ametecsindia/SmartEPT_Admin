<?php

namespace App\Console\Commands;

use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDevice;
use App\Models\MailLog;
use App\Services\MailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * R2-2: operational alert emails — the server tells admins about problems
 * instead of waiting for someone to notice a blind spot.
 *
 * 1. Device-offline sweep: an agent that stopped heartbeating is flipped to
 *    OFFLINE/STALE and the company admins get ONE email per sweep listing the
 *    devices that just went silent (flip-once = natural dedupe).
 * 2. Violation spike: unusually many compliance events in the last hour →
 *    one email per company per hour (deduped via mail_logs).
 *
 * Scheduled every 30 minutes; safe to run manually any time.
 */
class SendOpsAlerts extends Command
{
    protected $signature = 'smartept:alerts {--offline-minutes=} {--spike-threshold=}';

    protected $description = 'Sweep for silent agents and violation spikes, email company admins.';

    public function handle(): int
    {
        $this->sweepOfflineDevices();
        $this->checkViolationSpikes();
        $this->sendLateLogins();

        return self::SUCCESS;
    }

    private function sweepOfflineDevices(): void
    {
        // 1) Status: unchanged — a PC silent for the server's offline limit is marked OFFLINE.
        $minutes = (int) ($this->option('offline-minutes') ?: config('smartept.offline_alert_minutes', 30));

        $gone = EmployeeDevice::query()
            ->where('current_status', '!=', 'OFFLINE')
            ->where('last_heartbeat_at', '<', now()->subMinutes($minutes))
            ->get(['id', 'company_id']);

        foreach ($gone->groupBy('company_id') as $companyId => $devices) {
            EmployeeDevice::whereIn('id', $devices->pluck('id'))
                ->update(['current_status' => 'OFFLINE', 'agent_health' => 'STOPPED']);
            $this->warn("Offline sweep: {$devices->count()} device(s) flagged for company {$companyId}.");
        }
        if ($gone->isEmpty()) {
            $this->info('Offline sweep: all agents healthy.');
        }

        // 2) Email (23-Sep-2026): each company chooses AFTER how many minutes of silence it is
        //    told (Audit & Ops → Notifications). One email per silence per PC: the heartbeat
        //    time it went quiet at is remembered, so the same outage is never re-reported.
        $silent = EmployeeDevice::query()
            ->with('employee:id,first_name,last_name,employee_code')
            ->where('current_status', 'OFFLINE')
            ->where('last_heartbeat_at', '>', now()->subDays(2)) // ignore long-retired PCs
            ->get();

        foreach ($silent->groupBy('company_id') as $companyId => $devices) {
            $p = MailService::prefs((int) $companyId)['device_offline'];
            if (empty($p['on'])) {
                continue;
            }
            $alertAfter = max(1, (int) ($this->option('offline-minutes') ?: $p['minutes']));
            $due = $devices->filter(fn ($d) => $d->last_heartbeat_at->lt(now()->subMinutes($alertAfter))
                && Cache::get('smartept:offline_alerted:' . $d->id) !== $d->last_heartbeat_at->toIso8601String());
            if ($due->isEmpty()) {
                continue;
            }

            $lines = $due->map(fn ($d) => sprintf(
                '- %s (%s) — last heartbeat %s',
                $d->computer_name ?: $d->device_uuid,
                $d->employee?->fullName() ?? 'unassigned',
                optional($d->last_heartbeat_at)->format('d M Y H:i') ?? 'never'
            ))->implode("\n");
            $vars = ['count' => $due->count(), 'minutes' => $alertAfter, 'devices' => $lines];
            $t = MailService::TEMPLATES['device_offline'];

            foreach (array_keys(MailService::recipients('device_offline', (int) $companyId)) as $email) {
                MailService::send($email, MailService::render($t['subject'], $vars), MailService::render($t['body'], $vars), 'device_offline', (int) $companyId, $vars);
            }
            foreach ($due as $d) {
                Cache::put('smartept:offline_alerted:' . $d->id, $d->last_heartbeat_at->toIso8601String(), now()->addDays(3));
            }
            $this->warn("Offline alert: {$due->count()} device(s) emailed for company {$companyId}.");
        }
    }

    private function checkViolationSpikes(): void
    {
        $hourTag = now()->format('Y-m-d H:00');

        $counts = EmployeeComplianceEvent::query()
            ->where('created_at', '>=', now()->subHour())
            ->selectRaw('company_id, count(*) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        $spikes = 0;
        foreach ($counts as $companyId => $total) {
            // Each company sets its own limit in Audit & Ops → Notifications (23-Sep-2026).
            $threshold = (int) ($this->option('spike-threshold')
                ?: MailService::pref('violation_spike', 'threshold', 0, (int) $companyId)
                ?: config('smartept.violation_spike_threshold', 20));
            if ($total < $threshold) {
                continue;
            }
            $spikes++;
            $subject = "SmartEPT alert: violation spike — {$total} events in the last hour [{$hourTag}]";

            // One alert per company per hour — matched on the hour tag, because the count
            // in the subject changes as more violations arrive (23-Sep-2026).
            // One alert per company per clock hour (kind + company + hour — independent of the
            // subject, which the company may have reworded).
            if (MailLog::where('kind', 'violation_spike')->where('company_id', $companyId)
                ->where('created_at', '>=', now()->startOfHour())->exists()) {
                continue;
            }

            $vars = ['total' => $total, 'threshold' => $threshold, 'hour' => $hourTag];
            $t = MailService::TEMPLATES['violation_spike'];
            $body = MailService::render($t['body'], $vars);

            foreach (array_keys(MailService::recipients('violation_spike', (int) $companyId)) as $email) {
                MailService::send($email, $subject, $body, 'violation_spike', (int) $companyId, $vars);
            }

            $this->warn("Violation spike: {$total} events for company {$companyId}.");
        }

        if ($spikes === 0) {
            $this->info('Violation check: no spikes.');
        }
    }

    /**
     * Late logins (23-Sep-2026): ONE email per company per day, at the hour that company
     * chose, listing everyone who logged in later than their limit today. late_minutes is
     * written when the employee first logs in / punches (AttendanceDerivation) — read only.
     */
    private function sendLateLogins(): void
    {
        $today = now()->toDateString();

        $rows = EmployeeAttendanceLog::withoutGlobalScopes()
            ->with('employee:id,first_name,last_name,employee_code')
            ->whereDate('work_date', $today)
            ->where('late_minutes', '>', 0)
            ->selectRaw('company_id, employee_id, MAX(late_minutes) as late_minutes')
            ->groupBy('company_id', 'employee_id')
            ->get();

        foreach ($rows->groupBy('company_id') as $companyId => $late) {
            $p = MailService::prefs((int) $companyId)['late_login'];
            if (empty($p['on']) || (int) now()->format('G') < (int) $p['hour']) {
                continue;
            }
            $late = $late->filter(fn ($r) => $r->late_minutes >= (int) $p['minutes'])->sortByDesc('late_minutes');
            if ($late->isEmpty() || MailLog::where('kind', 'late_login')->where('company_id', $companyId)
                ->where('created_at', '>=', now()->startOfDay())->exists()) {
                continue; // one list per company per day
            }

            $vars = ['date' => $today, 'minutes' => (int) $p['minutes'], 'count' => $late->count(),
                'list' => $late->map(fn ($r) => sprintf('- %s (%s) — %d min late',
                    $r->employee?->fullName() ?? 'Employee #' . $r->employee_id, $r->employee?->employee_code ?? '-', $r->late_minutes))->implode("\n")];
            $t = MailService::TEMPLATES['late_login'];

            foreach (array_keys(MailService::recipients('late_login', (int) $companyId)) as $email) {
                MailService::send($email, MailService::render($t['subject'], $vars), MailService::render($t['body'], $vars), 'late_login', (int) $companyId, $vars);
            }
            $this->warn("Late logins: {$late->count()} employee(s) for company {$companyId}.");
        }
    }
}
