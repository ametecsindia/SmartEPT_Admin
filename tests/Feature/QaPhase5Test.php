<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeComplianceEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * QA Phase 5 — screenshot cadence in the agent bundle (B11/D5) and violation → evidence
 * linkage (B10): the Violations screen shows ONLY that violation's shots, tenant-safe,
 * with an EXPIRED signal once retention has purged the image.
 */
class QaPhase5Test extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private string $deviceToken;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(10, 0));

        $this->adminToken = $this->login('admin@ametecs.io');
        $userToken = $this->login('priya.raman@ametecs.io');

        $this->deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'QA5-DEV', 'computer_name' => 'QA5-PC',
        ])->assertCreated()->json('device_token');

        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => 'QA5-DEV', 'acknowledged' => true,
        ])->assertCreated();

        $this->employee = Employee::where('employee_code', 'E-1001')->firstOrFail();
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    // ---- B11/D5: the resolved screenshot cadence is in the bundle ----

    public function test_bundle_exposes_effective_interval_and_version(): void
    {
        // Seeded "Standard Screenshots" policy = 10-minute interval, version 1.
        $bundle = $this->withToken($this->deviceToken)
            ->getJson('/api/agent/policy?device_uuid=QA5-DEV')->assertOk();

        $this->assertSame(600, $bundle->json('policies.screenshot.effective_interval_seconds'),
            'a 10-minute policy must resolve to 600s (not per-minute)');
        $this->assertSame(1, $bundle->json('policies.screenshot.policy_version'));
        $this->assertNotNull($bundle->json('policies.screenshot.policy_id'));
    }

    // ---- B10: evidence endpoint returns only the violation's linked shots ----

    public function test_violation_evidence_returns_only_linked_shots(): void
    {
        $uuid = 'VIO-CORR-1';

        $eventId = $this->withToken($this->deviceToken)->postJson('/api/agent/compliance-event', [
            'device_uuid' => 'QA5-DEV', 'event_type' => 'BLOCKED_APP_OPENED', 'event_category' => 'APP',
            'severity' => 'HIGH', 'detected_value' => 'steam.exe', 'screenshot_captured' => true,
            'started_at' => now()->toDateTimeString(), 'client_event_uuid' => $uuid,
        ])->assertCreated()->json('compliance_event_id');

        // Evidence shot sharing the correlation id → linked to the violation.
        $this->withToken($this->deviceToken)->post('/api/agent/screenshot-upload', [
            'device_uuid' => 'QA5-DEV', 'trigger_reason' => 'BLOCKED_APP', 'client_event_uuid' => $uuid,
            'active_app' => 'steam.exe', 'image' => UploadedFile::fake()->image('v.jpg', 400, 300),
        ])->assertCreated();

        // Unrelated interval shot → must NOT show up as this violation's evidence.
        $this->withToken($this->deviceToken)->post('/api/agent/screenshot-upload', [
            'device_uuid' => 'QA5-DEV', 'trigger_reason' => 'INTERVAL',
            'image' => UploadedFile::fake()->image('i.jpg', 400, 300),
        ])->assertCreated();

        $res = $this->withToken($this->adminToken)->getJson("/api/violations/{$eventId}/evidence")->assertOk();
        $this->assertTrue($res->json('data.available'));
        $this->assertCount(1, $res->json('data.evidence'));
        $this->assertSame('steam.exe', $res->json('data.evidence.0.active_app'));
    }

    // ---- B10: cross-tenant evidence is refused ----

    public function test_cross_tenant_evidence_is_forbidden(): void
    {
        $companyB = Company::create([
            'code' => 'GAMMA', 'name' => 'Gamma Co', 'timezone' => 'Asia/Kolkata',
            'deployment_model' => 'LAN', 'storage_driver' => 'LOCAL', 'data_retention_days' => 90, 'status' => 'ACTIVE',
        ]);
        $empB = Employee::create([
            'company_id' => $companyB->id, 'employee_code' => 'G-1', 'first_name' => 'Gita', 'last_name' => 'B',
            'employment_status' => 'ACTIVE',
        ]);
        $evB = EmployeeComplianceEvent::create([
            'company_id' => $companyB->id, 'employee_id' => $empB->id, 'event_type' => 'BLOCKED_APP_OPENED',
            'event_category' => 'APP', 'severity' => 'HIGH', 'screenshot_captured' => true, 'started_at' => now(),
        ]);

        // Company A admin (has evidence.view) must not read company B's evidence.
        $this->withToken($this->adminToken)->getJson("/api/violations/{$evB->id}/evidence")->assertStatus(403);
    }

    // ---- B10: purged evidence reports EXPIRED, not a broken link ----

    public function test_purged_evidence_reports_expired(): void
    {
        // A violation that captured a shot, but no screenshot rows survive (retention purged).
        $ev = EmployeeComplianceEvent::create([
            'company_id' => $this->employee->company_id, 'employee_id' => $this->employee->id,
            'event_type' => 'BLOCKED_APP_OPENED', 'event_category' => 'APP', 'severity' => 'HIGH',
            'screenshot_captured' => true, 'started_at' => now(),
        ]);

        $res = $this->withToken($this->adminToken)->getJson("/api/violations/{$ev->id}/evidence")->assertOk();
        $this->assertFalse($res->json('data.available'));
        $this->assertSame('EXPIRED', $res->json('data.reason'));
    }
}
