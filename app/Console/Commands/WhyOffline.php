<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use App\Models\EmployeeScreenshotLog;
use App\Support\ResolvesLocalNow;
use Illuminate\Console\Command;

/**
 * "The employee is signed in, so why does the Live Dashboard say OFFLINE?"
 *
 * Prints, per employee, exactly the inputs the dashboard uses and the verdict it reaches, so
 * the answer is read off one screen instead of inferred. Read-only: it changes nothing.
 *
 * The dashboard's rule (DashboardController::liveStatus) is:
 *     fresh  = last_heartbeat_at >= now() - 3 minutes
 *     status = (!fresh || current_status === 'OFFLINE') ? 'OFFLINE' : current_status
 * so an employee reads OFFLINE for exactly two reasons: no heartbeat in the last 3 minutes, or
 * the column literally says OFFLINE. This names which.
 */
class WhyOffline extends Command
{
    use ResolvesLocalNow;

    protected $signature = 'smartept:why-offline';
    protected $description = 'Explain, per employee, why the Live Dashboard shows them as it does';

    public function handle(): int
    {
        $now = now();
        $this->line('');
        $this->line('  app timezone      : ' . config('app.timezone') . '   now = ' . $now->toDateTimeString());
        $this->line('  organisation tz   : ' . $this->companyTz(Employee::withoutGlobalScopes()->value('company_id')));
        $this->line('  dashboard window  : heartbeat must be at or after ' . $now->copy()->subMinutes(3)->toDateTimeString());
        $this->line('');

        $devices = EmployeeDevice::withoutGlobalScopes()->get()->groupBy('employee_id');
        $employees = Employee::withoutGlobalScopes()->where('employment_status', 'ACTIVE')->get();

        $rows = [];
        foreach ($employees as $e) {
            foreach (($devices[$e->id] ?? collect([null])) as $d) {
                if (! $d) {
                    $rows[] = [$e->employee_code, '— no device —', '', '', '', '', 'OFFLINE (never registered)'];
                    continue;
                }

                $age = $d->last_heartbeat_at ? (int) $d->last_heartbeat_at->diffInSeconds($now, true) : null;
                $fresh = $age !== null && $age <= 180;

                $verdict = match (true) {
                    (bool) $d->unbound_at             => 'OFFLINE — device unbound',
                    $d->session_status !== 'ACTIVE'   => 'OFFLINE — session ' . $d->session_status . ' (heartbeat is being 401d; agent must sign in again)',
                    ! $d->last_heartbeat_at           => 'OFFLINE — never heartbeat',
                    ! $fresh                          => 'OFFLINE — last heartbeat ' . $age . 's ago, window is 180s',
                    $d->current_status === 'OFFLINE'  => 'OFFLINE — column says OFFLINE despite a fresh heartbeat',
                    default                           => strtoupper((string) $d->current_status ?: 'ONLINE') . ' — live',
                };

                $rows[] = [
                    $e->employee_code,
                    substr((string) ($d->computer_name ?: $d->device_uuid), 0, 18),
                    $d->session_status,
                    $d->current_status,
                    $d->last_heartbeat_at?->toDateTimeString() ?? '—',
                    $age === null ? '—' : $age . 's',
                    $verdict,
                ];
            }
        }

        $this->table(['Emp', 'Device', 'session', 'status', 'last heartbeat', 'age', 'dashboard verdict'], $rows);

        $this->line('  open login sessions : ' . EmployeeLoginSession::withoutGlobalScopes()->whereNull('logout_at')->count());
        $this->line('  live agent tokens   : ' . \Laravel\Sanctum\PersonalAccessToken::where('name', 'like', 'device:%')->count());
        $this->line('  newest violation    : ' . (EmployeeComplianceEvent::withoutGlobalScopes()->latest('id')->value('started_at') ?: '—'));
        $this->line('  newest screenshot   : ' . (EmployeeScreenshotLog::withoutGlobalScopes()->latest('id')->value('captured_at') ?: '—'));
        $this->line('');
        $this->comment('  A verdict of "session <X>" means the agent is being refused and must sign in again.');
        $this->comment('  If sign-in is refused too, check the shift window: Organisation -> Shifts.');
        $this->line('');

        return self::SUCCESS;
    }
}
