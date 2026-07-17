<?php

namespace App\Console\Commands;

use App\Models\IntegrationTarget;
use App\Services\OutboundPusher;
use Illuminate\Console\Command;

/**
 * Nightly outbound push (Ejaz 17-Jul): sends each company's attendance for a
 * date to every active integration target. Default date = yesterday, so a
 * 02:00 run ships the completed previous day. Best-effort per target.
 */
class PushIntegrations extends Command
{
    protected $signature = 'smartept:push-integrations {--date= : YYYY-MM-DD (default yesterday)}';
    protected $description = 'Push attendance to active outbound integration targets (e.g. SmartPRS)';

    public function handle(OutboundPusher $pusher): int
    {
        $date = $this->option('date') ?: now()->subDay()->toDateString();

        $companyIds = IntegrationTarget::withoutGlobalScopes()->where('active', true)
            ->distinct()->pluck('company_id');

        $total = 0;
        foreach ($companyIds as $companyId) {
            $results = $pusher->pushAttendanceForDate($companyId, $date);
            foreach ($results as $r) {
                $total++;
                $this->line(sprintf('company %d → %s : %s', $companyId, $r['target'], $r['status']));
            }
        }

        $this->info("Pushed to {$total} target(s) for {$date}.");
        return self::SUCCESS;
    }
}
