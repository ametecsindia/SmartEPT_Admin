<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeLoginSession;
use App\Models\MailLog;
use App\Models\ReportSchedule;
use App\Services\ScheduledReports;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 30-Sep-2026 (Ejaz): Reports → Schedule Report — automatic Productivity report emails. */
class ScheduledReportsTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-07'; // a Tuesday; the report is sent the next morning

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
    }

    private function token(string $email = 'admin@ametecs.io'): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->json('token');
    }

    /** Priya worked on DAY; Dev and Arjun did not. */
    private function workDay(): Employee
    {
        $d = self::DAY;
        $this->travelTo(Carbon::parse("$d 18:00:00"));
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $base = ['company_id' => 1, 'employee_id' => $e->id];
        EmployeeAttendanceLog::create($base + ['work_date' => $d, 'source' => 'CLIENT', 'status' => 'PRESENT', 'check_in_at' => "$d 09:00:00"]);
        EmployeeLoginSession::create($base + ['device_uuid' => 'DEV-T', 'session_type' => 'CLIENT', 'login_at' => "$d 09:00:00", 'logout_at' => "$d 17:00:00", 'logout_reason' => 'USER']);
        EmployeeActivityEvent::create($base + ['event_type' => 'ACTIVE', 'started_at' => "$d 09:00:00", 'ended_at' => "$d 12:00:00", 'duration_seconds' => 10800]);
        EmployeeActivityEvent::create($base + ['event_type' => 'IDLE', 'started_at' => "$d 12:00:00", 'ended_at' => "$d 12:20:00", 'duration_seconds' => 1200]);
        EmployeeActivityEvent::create($base + ['event_type' => 'ACTIVE', 'started_at' => "$d 12:20:00", 'ended_at' => "$d 17:00:00", 'duration_seconds' => 16800]);

        return $e;
    }

    /**
     * Build DAY's summary now (as the nightly job would). SQLite stores the date-cast work_date
     * as "Y-m-d 00:00:00" (MySQL's DATE column does not), so normalise it for the report query.
     */
    private function summarise(string $token): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 00:45:00'));
        $this->withToken($token)->postJson('/api/reports/productivity/rebuild?from=' . self::DAY . '&to=' . self::DAY)->assertOk();
        \Illuminate\Support\Facades\DB::table('employee_daily_summaries')->update(['work_date' => \Illuminate\Support\Facades\DB::raw('substr(work_date, 1, 10)')]);
    }

    private function payload(array $over = []): array
    {
        $ids = Employee::withoutGlobalScopes()->pluck('id', 'employee_code');

        return array_merge([
            'name' => 'Daily productivity', 'enabled' => true, 'frequency' => 'DAILY', 'send_time' => '09:00',
            'days' => [1, 2, 3, 4, 5, 6, 7], 'all_employees_own' => false,
            'recipients' => [
                ['employee_id' => $ids['E-1001'], 'email' => 'priya.personal@example.com', 'mode' => 'OWN'], // edited email
                ['employee_id' => $ids['E-1002'], 'email' => null, 'mode' => 'OWN'],                           // no activity → skipped
                ['employee_id' => $ids['E-1003'], 'email' => null, 'mode' => 'FULL'],
            ],
            'extra_emails' => ['boss@example.com'], 'skip_empty' => true, 'skip_holidays' => true, 'attach_excel' => true,
            'message' => "Please review your day.\nHi <Employee Full Name>\nHere is your day's Productivity Report for <date>",
            'subject' => 'SmartEPT - Daily Report {date}',
        ], $over);
    }

    public function test_placeholders_in_either_bracket_style(): void
    {
        $f = fn ($t) => ScheduledReports::fill($t, 'Priya Raman', 'Tue, 07 Jul 2026', 'Ametecs', 'Daily');
        $this->assertSame('Hi Priya Raman, report for Tue, 07 Jul 2026', $f('Hi <Employee Full Name>, report for <date>'));
        $this->assertSame('Hi Priya — Ametecs · Daily · Tue, 07 Jul 2026', $f('Hi {first_name} — {company} · {schedule} · {PERIOD}'));
        $this->assertSame('Keep <this> and {that}', $f('Keep <this> and {that}'));
    }

    public function test_due_and_period_rules(): void
    {
        $s = new ReportSchedule(['enabled' => true, 'frequency' => 'DAILY', 'send_time' => '09:00', 'days' => [1, 2, 3, 4, 5]]);
        $wed = Carbon::parse('2026-07-08 09:00:00');
        $this->assertTrue(ScheduledReports::isDue($s, $wed));
        $this->assertFalse(ScheduledReports::isDue($s, Carbon::parse('2026-07-08 08:59:00')));   // not yet
        $this->assertFalse(ScheduledReports::isDue($s, Carbon::parse('2026-07-11 10:00:00')));   // Saturday not ticked
        $s->last_period = '2026-07-08';
        $this->assertFalse(ScheduledReports::isDue($s, Carbon::parse('2026-07-08 15:00:00')));   // once a day
        $this->assertSame(['2026-07-07', '2026-07-07'], ScheduledReports::period($s, $wed));

        $w = new ReportSchedule(['enabled' => true, 'frequency' => 'WEEKLY', 'send_time' => '08:00', 'days' => [1]]);
        $mon = Carbon::parse('2026-07-13 08:30:00');
        $this->assertTrue(ScheduledReports::isDue($w, $mon));
        $this->assertSame(['2026-07-06', '2026-07-12'], ScheduledReports::period($w, $mon));

        $m = new ReportSchedule(['enabled' => true, 'frequency' => 'MONTHLY', 'send_time' => '07:00', 'day_of_month' => 1]);
        $first = Carbon::parse('2026-08-01 07:00:00');
        $this->assertTrue(ScheduledReports::isDue($m, $first));
        $this->assertSame(['2026-07-01', '2026-07-31'], ScheduledReports::period($m, $first));
    }

    public function test_the_schedule_sends_each_report_once_at_its_time(): void
    {
        $this->workDay();
        $t = $this->token();
        $id = $this->withToken($t)->postJson('/api/report-schedules', $this->payload())->assertCreated()->json('data.id');
        $this->assertCount(1, $this->withToken($t)->getJson('/api/report-schedules')->assertOk()->json('data'));
        $this->summarise($t);

        $this->travelTo(Carbon::parse('2026-07-08 08:59:00'));
        $this->artisan('smartept:scheduled-reports')->assertSuccessful();
        $this->assertSame(0, MailLog::where('kind', ScheduledReports::KIND)->count(), 'not before 09:00');

        $this->travelTo(Carbon::parse('2026-07-08 09:01:00'));
        $this->artisan('smartept:scheduled-reports')->assertSuccessful();
        $logs = MailLog::where('kind', ScheduledReports::KIND)->where('status', 'sent')->pluck('subject', 'to')->all();
        $this->assertEqualsCanonicalizing(['priya.personal@example.com', 'arjun.mehta@ametecs.io', 'boss@example.com'], array_keys($logs));
        $this->assertSame('SmartEPT - Daily Report Tue, 07 Jul 2026', $logs['boss@example.com']); // custom subject, {date} filled

        $msgs = collect(app('mailer')->getSymfonyTransport()->messages());
        $priya = $msgs->first(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress() === 'priya.personal@example.com')->getOriginalMessage();
        $this->assertStringContainsString('Your day, event by event', $priya->getHtmlBody());
        $this->assertStringContainsString('Please review your day.', $priya->getHtmlBody());
        // 30-Sep-2026 (Ejaz): placeholders are filled per recipient at send time.
        $this->assertStringContainsString('Hi Priya Raman', $priya->getHtmlBody());
        $this->assertStringContainsString('Productivity Report for Tue, 07 Jul 2026', $priya->getHtmlBody());
        $this->assertStringNotContainsString('Employee Full Name', $priya->getHtmlBody());
        $this->assertSame('SmartEPT - Daily Report Tue, 07 Jul 2026', $priya->getSubject());
        $arjun = $msgs->first(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress() === 'arjun.mehta@ametecs.io')->getOriginalMessage();
        $this->assertStringContainsString('Hi Arjun Mehta', $arjun->getHtmlBody());
        $boss = $msgs->first(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress() === 'boss@example.com')->getOriginalMessage();
        $this->assertStringContainsString('Hi Team', $boss->getHtmlBody());
        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->assertCount(1, $priya->getAttachments());
        }

        $s = ReportSchedule::withoutGlobalScopes()->find($id);
        $this->assertSame('2026-07-08', $s->last_period);
        $this->assertStringContainsString('3 sent', $s->last_status);
        $this->assertStringContainsString('1 skipped', $s->last_status); // Dev had no activity

        $this->travelTo(Carbon::parse('2026-07-08 15:00:00'));
        $this->artisan('smartept:scheduled-reports')->assertSuccessful();
        $this->assertSame(3, MailLog::where('kind', ScheduledReports::KIND)->where('status', 'sent')->count(), 'never twice a day');
    }

    public function test_send_test_goes_only_to_me_and_records_nothing(): void
    {
        $this->workDay();
        $t = $this->token();
        $id = $this->withToken($t)->postJson('/api/report-schedules', $this->payload())->assertCreated()->json('data.id');
        $this->summarise($t);
        $this->travelTo(Carbon::parse('2026-07-08 07:00:00'));

        $r = $this->withToken($t)->postJson("/api/report-schedules/{$id}/run?test=1")->assertOk()->json('data');
        $this->assertSame(2, $r['sent']); // the full report + one sample employee report
        $this->assertSame(['admin@ametecs.io'], MailLog::where('kind', ScheduledReports::KIND)->distinct()->pluck('to')->all());
        $this->assertNull(ReportSchedule::withoutGlobalScopes()->find($id)->last_period);
    }

    public function test_all_employees_option_and_holiday_skip(): void
    {
        $this->workDay();
        $t = $this->token();
        $id = $this->withToken($t)->postJson('/api/report-schedules', $this->payload([
            'recipients' => [], 'extra_emails' => [], 'all_employees_own' => true,
        ]))->assertCreated()->json('data.id');
        $this->summarise($t);

        \App\Models\Holiday::create(['company_id' => 1, 'holiday_date' => self::DAY, 'name' => 'Test holiday', 'type' => 'PUBLIC']);
        $this->travelTo(Carbon::parse('2026-07-08 09:05:00'));
        $this->artisan('smartept:scheduled-reports')->assertSuccessful();
        $this->assertSame(0, MailLog::where('kind', ScheduledReports::KIND)->count());
        $this->assertStringContainsString('holiday', ReportSchedule::withoutGlobalScopes()->find($id)->last_status);

        \App\Models\Holiday::query()->delete();
        $this->withToken($t)->postJson("/api/report-schedules/{$id}/run")->assertOk();
        // Only Priya worked, so only Priya (at her own address) gets a report.
        $this->assertSame(['priya.raman@ametecs.io'], MailLog::where('kind', ScheduledReports::KIND)->where('status', 'sent')->pluck('to')->all());
    }

    /** 30-Sep-2026 (Ejaz): the full-report snapshot image on WhatsApp — Company Admin(s) + typed numbers. */
    public function test_whatsapp_snapshot_goes_to_admins_and_typed_numbers(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*/media' => \Illuminate\Support\Facades\Http::response(['id' => 'MEDIA-1']),
            'graph.facebook.com/*/messages' => \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.1']]]),
        ]);
        $this->workDay();
        \App\Models\User::where('email', 'admin@ametecs.io')->update(['phone' => '+91 90000 11111']);
        $t = $this->token();

        $this->withToken($t)->putJson('/api/whatsapp-config', ['phone_number_id' => '1234567890', 'token' => 'EAAG-secret'])->assertOk()
            ->assertJsonPath('data.connected', true)->assertJsonMissing(['token' => 'EAAG-secret']);
        $this->assertStringNotContainsString('EAAG-secret', (string) \App\Models\Setting::get('whatsapp:company:1'), 'token stored encrypted');

        $this->withToken($t)->postJson('/api/report-schedules', $this->payload(['whatsapp_enabled' => true, 'whatsapp_numbers' => []]))->assertStatus(422);
        $this->withToken($t)->postJson('/api/report-schedules', $this->payload(['whatsapp_enabled' => true, 'whatsapp_numbers' => ['12345']]))->assertStatus(422);
        $id = $this->withToken($t)->postJson('/api/report-schedules', $this->payload([
            'recipients' => [], 'extra_emails' => ['boss@example.com'],
            'whatsapp_enabled' => true, 'whatsapp_admins' => true, 'whatsapp_numbers' => ['098765 43210'],
        ]))->assertCreated()->assertJsonPath('data.whatsapp_numbers', ['919876543210'])->json('data.id');

        $this->summarise($t);
        $this->travelTo(Carbon::parse('2026-07-08 09:02:00'));
        $this->withToken($t)->get("/api/report-schedules/{$id}/snapshot")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->artisan('smartept:scheduled-reports')->assertSuccessful();

        $sent = \App\Models\MailLog::where('kind', \App\Services\WhatsAppSender::KIND)->where('status', 'sent')->pluck('to')->sort()->values()->all();
        $this->assertSame(['919000011111', '919876543210'], $sent);
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_ends_with($r->url(), '/1234567890/messages')
            && $r['to'] === '919876543210' && $r['template']['name'] === 'smartept_daily_report'
            && $r['template']['components'][0]['parameters'][0]['image']['id'] === 'MEDIA-1'
            && $r->hasHeader('Authorization', 'Bearer EAAG-secret'));
        $this->assertStringContainsString('WhatsApp: 2 sent', ReportSchedule::withoutGlobalScopes()->find($id)->last_status);
    }

    public function test_whatsapp_not_connected_is_reported_not_silent(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $this->workDay();
        $t = $this->token();
        $id = $this->withToken($t)->postJson('/api/report-schedules', $this->payload(['whatsapp_enabled' => true, 'whatsapp_numbers' => ['9876543210']]))
            ->assertCreated()->json('data.id');
        $this->summarise($t);
        $this->travelTo(Carbon::parse('2026-07-08 09:02:00'));
        $this->artisan('smartept:scheduled-reports')->assertSuccessful();
        $this->assertStringContainsString('WhatsApp: not connected', ReportSchedule::withoutGlobalScopes()->find($id)->last_status);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_validation_and_employee_logins_are_refused(): void
    {
        $t = $this->token();
        $this->withToken($t)->postJson('/api/report-schedules', $this->payload(['frequency' => 'WEEKLY', 'days' => [1, 2]]))->assertStatus(422);
        $this->withToken($t)->postJson('/api/report-schedules', $this->payload(['extra_emails' => ['not-an-email']]))->assertStatus(422);
        $this->withToken($t)->postJson('/api/report-schedules', $this->payload(['recipients' => [['employee_id' => 99999, 'mode' => 'OWN']]]))->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token('priya.raman@ametecs.io'))->getJson('/api/report-schedules')->assertForbidden();
    }

    /** 07-Oct-2026 (Ejaz): a reporting manager gets only their reporting employees; admins get the whole company. */
    public function test_reporting_team_report_goes_to_each_manager_and_admins(): void
    {
        $this->workDay();
        $t = $this->token();
        $roleId = \App\Models\Role::withoutGlobalScopes()->where('slug', 'MANAGER')->value('id');
        if (! $roleId) {
            $this->markTestSkipped('No MANAGER role seeded.');
        }
        $admin = \App\Models\User::withoutGlobalScopes()->where('email', 'admin@ametecs.io')->firstOrFail();
        $lead = $admin->replicate();
        $lead->forceFill(['name' => 'Team Lead', 'email' => 'lead@example.com', 'role_id' => $roleId, 'phone' => null])->save();
        Employee::withoutGlobalScopes()->whereIn('employee_code', ['E-1001', 'E-1002'])->update(['reporting_manager_user_id' => $lead->id]);
        Employee::withoutGlobalScopes()->where('employee_code', 'E-1003')->update(['reporting_manager_user_id' => null]);

        $this->withToken($t)->postJson('/api/report-schedules', $this->payload([
            'recipients' => [], 'extra_emails' => [], 'team_reports' => true, 'subject' => null, 'message' => null,
        ]))->assertCreated();
        $this->summarise($t);
        $this->travelTo(Carbon::parse('2026-07-08 09:01:00'));
        $this->artisan('smartept:scheduled-reports')->assertSuccessful();

        $msgs = collect(app('mailer')->getSymfonyTransport()->messages())->map->getOriginalMessage()
            ->keyBy(fn ($m) => $m->getTo()[0]->getAddress());
        $this->assertTrue($msgs->has('lead@example.com'), 'manager gets the team report');
        $this->assertTrue($msgs->has('admin@ametecs.io'), 'company admin gets the whole company');

        $team = $msgs['lead@example.com'];
        $this->assertStringStartsWith("Reporting team's productivity", $team->getSubject());
        $this->assertStringContainsString('Priya Raman', $team->getHtmlBody());
        $this->assertStringNotContainsString('Arjun Mehta', $team->getHtmlBody());   // not in this team

        $all = $msgs['admin@ametecs.io']->getHtmlBody();
        $this->assertStringContainsString('Priya Raman', $all);
        $this->assertStringContainsString('Arjun Mehta', $all);
        $this->assertStringContainsString('reporting-team', ReportSchedule::first()->last_status);
    }
}
