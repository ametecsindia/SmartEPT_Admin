<?php

namespace App\Console\Commands;

use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDevice;
use App\Models\MailLog;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Console\Command;

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

        return self::SUCCESS;
    }

    private function sweepOfflineDevices(): void
    {
        $minutes = (int) ($this->option('offline-minutes') ?: config('smartept.offline_alert_minutes', 30));

        $gone = EmployeeDevice::query()
            ->with('employee:id,first_name,last_name,employee_code')
            ->where('current_status', '!=', 'OFFLINE')
            ->where('last_heartbeat_at', '<', now()->subMinutes($minutes))
            ->get();

        if ($gone->isEmpty()) {
            $this->info('Offline sweep: all agents healthy.');

            return;
        }

        foreach ($gone->groupBy('company_id') as $companyId => $devices) {
            EmployeeDevice::whereIn('id', $devices->pluck('id'))
                ->update(['current_status' => 'OFFLINE', 'agent_health' => 'STOPPED']);

            $lines = $devices->map(fn ($d) => sprintf(
                '- %s (%s) — last heartbeat %s',
                $d->computer_name ?: $d->device_uuid,
                $d->employee?->fullName() ?? 'unassigned',
                optional($d->last_heartbeat_at)->format('d M Y H:i') ?? 'never'
            ))->implode("\n");

            $body = "The following monitored PCs stopped reporting more than {$minutes} minutes ago and are now marked OFFLINE:\n\n"
                . $lines
                . "\n\nIf the PC is on and in use, the SmartEPT agent may have been stopped — ask IT to check it. "
                . "Data recorded while offline syncs automatically when the agent returns.\n\n— SmartEPT";

            foreach ($this->companyAdmins((int) $companyId) as $admin) {
                MailService::send($admin->email, 'SmartEPT alert: ' . $devices->count() . ' device(s) went offline', $body, 'device_offline', (int) $companyId);
            }

            $this->warn("Offline sweep: {$devices->count()} device(s) flagged for company {$companyId}.");
        }
    }

    private function checkViolationSpikes(): void
    {
        $threshold = (int) ($this->option('spike-threshold') ?: config('smartept.violation_spike_threshold', 20));
        $hourTag = now()->format('Y-m-d H:00');

        $counts = EmployeeComplianceEvent::query()
            ->where('created_at', '>=', now()->subHour())
            ->selectRaw('company_id, count(*) as total')
            ->groupBy('company_id')
            ->having('total', '>=', $threshold)
            ->pluck('total', 'company_id');

        foreach ($counts as $companyId => $total) {
            $subject = "SmartEPT alert: violation spike — {$total} events in the last hour [{$hourTag}]";

            // One alert per company per hour (subject carries the hour tag).
            if (MailLog::where('kind', 'violation_spike')->where('company_id', $companyId)
                ->where('subject', $subject)->exists()) {
                continue;
            }

            $body = "SmartEPT recorded {$total} compliance violations in the last hour — well above the alert threshold of {$threshold}.\n\n"
                . "A spike usually means a policy change that is too strict, one team testing limits, or a misconfigured application/website rule. "
                . "Open the console → Violations to see who and what, and → Usage & Compliance for the day's detail.\n\n— SmartEPT";

            foreach ($this->companyAdmins((int) $companyId) as $admin) {
                MailService::send($admin->email, $subject, $body, 'violation_spike', (int) $companyId);
            }

            $this->warn("Violation spike: {$total} events for company {$companyId}.");
        }

        if ($counts->isEmpty()) {
            $this->info('Violation check: no spikes.');
        }
    }

    /** Active admin accounts for a company (plus super admins, who see everything). */
    private function companyAdmins(int $companyId)
    {
        return User::query()
            ->where('status', 'ACTIVE')
            ->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->orWhereNull('company_id'); // super admins
            })
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['SUPER_ADMIN', 'COMPANY_ADMIN']))
            ->get(['id', 'email', 'company_id']);
    }
}
