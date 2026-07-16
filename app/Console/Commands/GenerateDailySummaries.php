<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Services\ScoringService;
use App\Services\WorkCalendar;
use Illuminate\Console\Command;

class GenerateDailySummaries extends Command
{
    protected $signature = 'smartept:daily-summary {--date= : YYYY-MM-DD (defaults to yesterday)}';
    protected $description = 'Compute productivity + compliance scores and daily summaries for all employees';

    public function handle(ScoringService $scoring, WorkCalendar $calendar): int
    {
        $date = $this->option('date') ?: now()->subDay()->toDateString();
        $this->info("Building daily summaries for {$date}...");

        $count = 0;
        Employee::withoutGlobalScopes()->where('employment_status', 'ACTIVE')
            ->chunkById(200, function ($employees) use ($scoring, $calendar, $date, &$count) {
                foreach ($employees as $employee) {
                    // Non-working days (weekly off / holiday) get no score rows — a 0-score
                    // Sunday would wreck monthly averages. Exception: an attendance row means
                    // the employee actually worked that day, so it still deserves a summary.
                    if (! $calendar->isWorkingDay($employee, $date)
                        && ! EmployeeAttendanceLog::withoutGlobalScopes()
                            ->where('employee_id', $employee->id)->where('work_date', $date)->exists()) {
                        continue;
                    }
                    $scoring->buildSummary($employee, $date);
                    $count++;
                }
            });

        $this->info("Done. {$count} employee summaries written for {$date}.");
        return self::SUCCESS;
    }
}
