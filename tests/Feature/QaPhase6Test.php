<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QA Phase 6 — audit explicit date-range (B13) + full filtered audit export with
 * formula-injection guard + permission (B14) + no future manual checkout (B9).
 */
class QaPhase6Test extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Fixed "now" so the future-checkout + range boundaries are deterministic.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-23 15:00:00'));

        $this->adminToken = $this->login('admin@ametecs.io');
        $this->companyId = Employee::where('employee_code', 'E-1001')->firstOrFail()->company_id;
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    private function seedAudit(): void
    {
        AuditLog::insert([
            ['company_id' => $this->companyId, 'user_id' => null, 'action' => 'OLD_ACTION',
             'ip' => '10.0.0.1', 'created_at' => '2026-07-20 10:00:00', 'updated_at' => '2026-07-20 10:00:00'],
            ['company_id' => $this->companyId, 'user_id' => null, 'action' => 'MID_ACTION_A',
             'ip' => '10.0.0.2', 'created_at' => '2026-07-23 14:10:00', 'updated_at' => '2026-07-23 14:10:00'],
            ['company_id' => $this->companyId, 'user_id' => null, 'action' => 'MID_ACTION_B',
             'ip' => '10.0.0.3', 'created_at' => '2026-07-23 14:20:00', 'updated_at' => '2026-07-23 14:20:00'],
        ]);
    }

    // ---- B13: an explicit datetime range is honoured to the minute ----

    public function test_explicit_range_overrides_and_is_precise(): void
    {
        $this->seedAudit();

        $res = $this->withToken($this->adminToken)
            ->getJson('/api/audit-logs?from=2026-07-23 14:00:00&to=2026-07-23 14:15:00')->assertOk();

        $actions = collect($res->json('data'))->pluck('action');
        $this->assertContains('MID_ACTION_A', $actions);           // 14:10 in range
        $this->assertNotContains('MID_ACTION_B', $actions);         // 14:20 out of range
        $this->assertNotContains('OLD_ACTION', $actions);           // 20-Jul out of range
    }

    public function test_pagination_preserves_the_range_filter(): void
    {
        $this->seedAudit();

        $res = $this->withToken($this->adminToken)
            ->getJson('/api/audit-logs?from=2026-07-23 14:00:00&to=2026-07-23 14:59:00&per_page=1')->assertOk();

        $this->assertNotNull($res->json('next_page_url'));
        $this->assertStringContainsString('from=', $res->json('next_page_url'),
            'the page link must carry the filters forward');
    }

    // ---- B14: audit export — full, filtered, formula-safe, permissioned ----

    public function test_audit_export_is_filtered_formula_safe_and_permissioned(): void
    {
        // A malicious-looking action that must be neutralised in the CSV.
        AuditLog::insert([[
            'company_id' => $this->companyId, 'user_id' => null, 'action' => '=cmd()!A1',
            'ip' => '10.0.0.9', 'created_at' => '2026-07-23 14:30:00', 'updated_at' => '2026-07-23 14:30:00',
        ]]);

        $res = $this->withToken($this->adminToken)
            ->get('/api/export/audit-logs?from=2026-07-23 14:00:00&to=2026-07-23 15:00:00')->assertOk();

        $csv = $res->streamedContent();
        // The leading = is escaped with a ' so Excel/Sheets can't execute it.
        $this->assertStringContainsString("'=cmd()!A1", $csv);
        $this->assertStringContainsString('Timezone', $csv); // header includes the tz column

        // A role without audit.export cannot export.
        $manager = $this->login('manager@ametecs.io');
        $this->withToken($manager)->get('/api/export/audit-logs')->assertStatus(403);
    }

    // ---- B9: a manual check-out can never be in the future ----

    public function test_future_manual_checkout_is_rejected(): void
    {
        $emp = Employee::where('employee_code', 'E-1002')->firstOrFail();

        $this->withToken($this->adminToken)->postJson('/api/attendance', [
            'employee_id'  => $emp->id,
            'work_date'    => '2026-07-23',
            'status'       => 'PRESENT',
            'check_in_at'  => '2026-07-23 09:00:00',
            'check_out_at' => '2026-07-23 20:00:00', // 20:00 > now (15:00) → future
            'reason'       => 'Manual fix',
        ])->assertStatus(422)->assertJsonValidationErrors('check_out_at');
    }

    public function test_overnight_checkout_in_the_past_is_accepted(): void
    {
        $emp = Employee::where('employee_code', 'E-1001')->firstOrFail();

        // Night shift: in 22:00 yesterday, out 06:00 today — both before now, valid.
        $this->withToken($this->adminToken)->postJson('/api/attendance', [
            'employee_id'  => $emp->id,
            'work_date'    => '2026-07-22',
            'status'       => 'PRESENT',
            'check_in_at'  => '2026-07-22 22:00:00',
            'check_out_at' => '2026-07-23 06:00:00',
            'reason'       => 'Overnight shift regularization',
        ])->assertCreated();
    }
}
