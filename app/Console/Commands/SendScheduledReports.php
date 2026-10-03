<?php

namespace App\Console\Commands;

use App\Services\ScheduledReports;
use Illuminate\Console\Command;

/** 30-Sep-2026: Reports → Schedule Report — send every schedule that is due now (runs every minute). */
class SendScheduledReports extends Command
{
    protected $signature = 'smartept:scheduled-reports';

    protected $description = 'Email the scheduled Productivity reports that are due (company clock)';

    public function handle(ScheduledReports $reports): int
    {
        $n = $reports->runDue();
        $this->info($n . ' schedule(s) sent.');

        return self::SUCCESS;
    }
}
