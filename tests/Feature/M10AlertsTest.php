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
        // 23-Sep-2026: automatic emails are OFF until approved in Notifications — switch them on
        // for the seeded company (plus the server error report at the travelled 10:00).
        $cid = Employee::first()->company_id;
        \App\Models\Setting::put('notify_prefs:company:' . $cid, json_encode([
            'device_offline' => ['on' => true, 'roles' => ['COMPANY_ADMIN']],
            'violation_spike' => ['on' => true, 'roles' => ['COMPANY_ADMIN']],
            'late_login' => ['on' => true, 'roles' => ['COMPANY_ADMIN'], 'minutes' => 15, 'hour' => 9],
        ]));
        \App\Models\Setting::put('notify_prefs', json_encode([
            'error_digest' => ['on' => true, 'roles' => ['SUPER_ADMIN', 'COMPANY_ADMIN'], 'hour' => 10],
        ]));
    }

    public function test_switched_off_alert_sends_nothing(): void
    {
        \App\Models\Setting::put('notify_prefs:company:' . Employee::first()->company_id, json_encode([]));
        $this->makeDevice('off-1', 'ONLINE', now()->subHours(2));

        $this->artisan('smartept:alerts')->assertSuccessful();

        $this->assertSame('OFFLINE', EmployeeDevice::where('device_uuid', 'off-1')->value('current_status'));
        $this->assertSame(0, MailLog::where('kind', 'device_offline')->where('status', 'sent')->count());
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

    public function test_late_login_list_sent_once_a_day_after_chosen_hour(): void
    {
        $e = Employee::first();
        \App\Models\EmployeeAttendanceLog::withoutGlobalScopes()->create([
            'company_id' => $e->company_id, 'employee_id' => $e->id, 'work_date' => now()->toDateString(),
            'source' => 'CLIENT', 'late_minutes' => 40,
        ]);

        $this->artisan('smartept:alerts')->assertSuccessful();
        $this->artisan('smartept:alerts')->assertSuccessful();

        $sent = MailLog::where('kind', 'late_login')->where('status', 'sent')->get();
        $this->assertGreaterThan(0, $sent->count());
        $this->assertSame(1, $sent->pluck('to')->countBy()->max()); // once per person per day
        $this->assertStringContainsString('late logins today', $sent->first()->subject);
    }

    /** 07-Oct-2026 (Ejaz): Popup Alert only → console popups for the ticked roles, no email. */
    public function test_popup_only_alert_creates_popups_and_no_email(): void
    {
        $cid = Employee::first()->company_id;
        \App\Models\Setting::put('notify_prefs:company:' . $cid, json_encode([
            'device_offline' => ['on' => true, 'email' => false, 'popup' => true, 'roles' => ['COMPANY_ADMIN']],
        ]));
        $this->makeDevice('POP-1', 'ONLINE', now()->subHours(2));

        $this->artisan('smartept:alerts')->assertSuccessful();

        $this->assertSame(0, MailLog::where('kind', 'device_offline')->count());
        $admin = \App\Models\User::where('email', 'admin@ametecs.io')->firstOrFail();
        $this->assertSame(1, \App\Models\AlertPopup::where('user_id', $admin->id)->count());

        $t = $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');
        $this->withToken($t)->getJson('/api/alert-popups')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($t)->getJson('/api/alert-popups')->assertOk()->assertJsonCount(0, 'data'); // shown once
    }

    /** 07-Oct-2026 (Ejaz): late login → the respective employee gets an email + an agent popup, once a day. */
    public function test_late_employee_is_told_by_email_and_agent_popup_once(): void
    {
        $e = Employee::first();
        \App\Models\Setting::put('notify_prefs:company:' . $e->company_id, json_encode([
            'late_login' => ['on' => true, 'email' => true, 'popup' => true, 'employee' => true, 'roles' => [], 'minutes' => 15, 'hour' => 23],
        ]));
        \App\Models\EmployeeAttendanceLog::withoutGlobalScopes()->create([
            'company_id' => $e->company_id, 'employee_id' => $e->id, 'work_date' => now()->toDateString(),
            'source' => 'CLIENT', 'late_minutes' => 40, 'check_in_at' => now()->subHour(),
        ]);

        $this->artisan('smartept:alerts')->assertSuccessful();
        $this->artisan('smartept:alerts')->assertSuccessful();

        $this->assertSame(1, MailLog::where('kind', 'late_login_employee')->count());
        $this->assertSame(0, MailLog::where('kind', 'late_login')->count()); // the list waits for 23:00
        $msg = \App\Models\EmployeeChatMessage::withoutGlobalScopes()->where('employee_id', $e->id)->get();
        $this->assertCount(1, $msg);
        $this->assertStringContainsString('40 minutes late', $msg->first()->body);
        $this->assertSame('SmartEPT Alert', $msg->first()->toChat()['name']);
    }
}
