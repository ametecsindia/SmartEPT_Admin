<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDevice;
use App\Models\MailLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R2-2 ops alerts: offline sweep flips silent agents + emails admins once;
 * violation spike emails once per company per hour; error digest scans the log.
 */
class M10AlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(10)); // avoid midnight flake
    }

    private function makeDevice(string $uuid, string $status, $lastBeat): EmployeeDevice
    {
        $employee = Employee::first();

        return EmployeeDevice::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'device_uuid' => $uuid,
            'computer_name' => 'PC-' . $uuid,
            'current_status' => $status,
            'agent_health' => 'HEALTHY',
            'registered_at' => now()->subDay(),
            'last_heartbeat_at' => $lastBeat,
        ]);
    }

    public function test_offline_sweep_flips_silent_devices_and_mails_admin_once(): void
    {
        $silent = $this->makeDevice('ALERT-DEV-1', 'ONLINE', now()->subHours(2));
        $healthy = $this->makeDevice('ALERT-DEV-2', 'ONLINE', now()->subMinutes(5));

        $this->artisan('smartept:alerts')->assertSuccessful();

        $this->assertSame('OFFLINE', $silent->fresh()->current_status);
        $this->assertSame('STOPPED', $silent->fresh()->agent_health);
        $this->assertSame('ONLINE', $healthy->fresh()->current_status);

        $mails = MailLog::where('kind', 'device_offline')->get();
        $this->assertNotEmpty($mails);
        $this->assertStringContainsString('went offline', $mails->first()->subject); // product mail_logs stores subject, not body

        // Second sweep: the device is already OFFLINE → no second alert.
        $count = MailLog::where('kind', 'device_offline')->count();
        $this->artisan('smartept:alerts')->assertSuccessful();
        $this->assertSame($count, MailLog::where('kind', 'device_offline')->count());
    }

    public function test_violation_spike_alert_is_sent_once_per_hour(): void
    {
        $employee = Employee::first();

        for ($i = 0; $i < 25; $i++) {
            EmployeeComplianceEvent::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'event_type' => 'BLOCKED_APP',
                'severity' => 'HIGH',
                'started_at' => now()->subMinutes(10),
            ]);
        }

        $this->artisan('smartept:alerts')->assertSuccessful();
        $first = MailLog::where('kind', 'violation_spike')->count();
        $this->assertGreaterThan(0, $first);

        // Same hour, run again → deduped.
        $this->artisan('smartept:alerts')->assertSuccessful();
        $this->assertSame($first, MailLog::where('kind', 'violation_spike')->count());
    }

    public function test_no_spike_alert_below_threshold(): void
    {
        $employee = Employee::first();

        EmployeeComplianceEvent::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'event_type' => 'BLOCKED_APP',
            'severity' => 'LOW',
            'started_at' => now()->subMinutes(5),
        ]);

        $this->artisan('smartept:alerts')->assertSuccessful();
        $this->assertSame(0, MailLog::where('kind', 'violation_spike')->count());
    }

    public function test_error_digest_scans_log_and_mails_admins(): void
    {
        $log = storage_path('logs/laravel.log');
        @mkdir(dirname($log), 0777, true);
        $stamp = now()->subHour()->format('Y-m-d H:i:s');
        file_put_contents($log,
            "[{$stamp}] testing.ERROR: Something broke in the sync pipeline\n" .
            "[{$stamp}] testing.INFO: this line must be ignored\n"
        );

        $this->artisan('smartept:error-digest')->assertSuccessful();

        $mail = MailLog::where('kind', 'error_digest')->first();
        $this->assertNotNull($mail);
        $this->assertStringContainsString('error', strtolower($mail->subject));

        @unlink($log);
    }
}
