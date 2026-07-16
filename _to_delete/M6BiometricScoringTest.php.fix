<?php

namespace Tests\Feature;

use App\Models\EmployeeDailySummary;
use App\Models\EmployeeLoginSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Milestone M6: biometric ingest + mapping + mismatch report, and scoring/daily summaries.
 */
class M6BiometricScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function hr(): string
    {
        return $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');
    }

    public function test_biometric_map_ingest_and_mismatch(): void
    {
        $token = $this->hr();
        $date = now()->toDateString();

        // Map biometric id → employee 1.
        $this->withToken($token)->postJson('/api/integrations/biometric/map-employee', [
            'biometric_employee_id' => 'BIO-1001', 'employee_id' => 1,
        ])->assertCreated();

        // Biometric IN punch at 09:00.
        $this->withToken($token)->postJson('/api/integrations/biometric/logs', [
            'logs' => [['biometric_employee_id' => 'BIO-1001', 'punch_type' => 'IN', 'punched_at' => "$date 09:00:00"]],
        ])->assertStatus(202);

        // System login at 09:45 → 45-minute mismatch.
        EmployeeLoginSession::create([
            'company_id' => 1, 'employee_id' => 1, 'session_type' => 'CLIENT', 'login_at' => "$date 09:45:00",
        ]);

        $res = $this->withToken($token)->getJson('/api/reports/biometric-mismatch?date=' . $date)->assertOk();
        $row = collect($res->json('data'))->firstWhere('employee_id', 1);
        $this->assertSame(45, $row['diff_minutes']);
        $this->assertSame('MISMATCH', $row['status']);
    }

    public function test_daily_summary_command_writes_scores(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(10));
        $date = now()->toDateString();
        $employee = \App\Models\Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();

        // Some activity to score.
        \App\Models\EmployeeActivityEvent::create([
            'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'event_type' => 'ACTIVE',
            'started_at' => "$date 09:00:00", 'ended_at' => "$date 13:00:00", 'duration_seconds' => 14400,
        ]);

        $this->artisan('smartept:daily-summary', ['--date' => $date])->assertExitCode(0);

        $summary = EmployeeDailySummary::withoutGlobalScopes()->where('employee_id', $employee->id)->whereDate('work_date', $date)->first();
        $this->assertNotNull($summary);
        $this->assertGreaterThan(0, $summary->productivity_score);
        $this->assertSame(100.0, (float) $summary->compliance_score); // no violations
    }
}
