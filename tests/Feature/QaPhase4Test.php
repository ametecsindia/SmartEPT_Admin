<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * QA Phase 4 (B5) — granular permissions on the meeting routes + role-default seeding.
 * Meeting scheduling is now permission-gated (not role-gated); tenant isolation still
 * comes from the Meeting model's company scope.
 */
class QaPhase4Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // A weekday well inside the General shift so nothing about the day is unusual.
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(10, 0));
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    private function meetingBody(int $participantId): array
    {
        return [
            'title'           => 'Sprint planning',
            'start_at'        => now()->addDay()->setTime(11, 0)->toDateTimeString(),
            'end_at'          => now()->addDay()->setTime(12, 0)->toDateTimeString(),
            'participant_ids' => [$participantId],
        ];
    }

    // ---- B5: meeting scheduling is gated on meeting.schedule ----

    public function test_manager_with_permission_can_schedule(): void
    {
        $token = $this->login('manager@ametecs.io');           // MANAGER holds meeting.schedule
        $emp = Employee::where('employee_code', 'E-1001')->first();

        $this->withToken($token)->postJson('/api/meetings', $this->meetingBody($emp->id))
            ->assertCreated();
    }

    public function test_auditor_without_permission_cannot_schedule(): void
    {
        $token = $this->login('auditor@ametecs.io');           // AUDITOR: no meeting.schedule
        $emp = Employee::where('employee_code', 'E-1001')->first();

        $this->withToken($token)->postJson('/api/meetings', $this->meetingBody($emp->id))
            ->assertStatus(403);
    }

    public function test_employee_cannot_view_or_schedule_meetings(): void
    {
        $token = $this->login('priya.raman@ametecs.io');       // EMPLOYEE role: no meeting perms
        $emp = Employee::where('employee_code', 'E-1001')->first();

        $this->withToken($token)->getJson('/api/meetings')->assertStatus(403);
        $this->withToken($token)->postJson('/api/meetings', $this->meetingBody($emp->id))->assertStatus(403);
    }

    // ---- Tenant isolation survives the switch to permissions ----

    public function test_cross_tenant_meeting_is_not_visible(): void
    {
        // A second tenant with its own meeting (created unauthenticated → explicit company_id).
        $companyB = Company::create([
            'code' => 'BETA', 'name' => 'Beta Co', 'timezone' => 'Asia/Kolkata',
            'deployment_model' => 'LAN', 'storage_driver' => 'LOCAL', 'data_retention_days' => 90, 'status' => 'ACTIVE',
        ]);
        $adminRole = Role::whereNull('company_id')->where('slug', 'COMPANY_ADMIN')->first();
        $userB = User::create([
            'name' => 'Beta Admin', 'email' => 'admin@beta.io', 'password' => Hash::make('password'),
            'company_id' => $companyB->id, 'role_id' => $adminRole->id, 'status' => 'ACTIVE',
        ]);
        $meetingB = Meeting::create([
            'company_id' => $companyB->id, 'title' => 'Beta only',
            'start_at' => now()->addDay()->setTime(9, 0), 'end_at' => now()->addDay()->setTime(10, 0),
            'meeting_date' => now()->addDay()->toDateString(), 'status' => 'SCHEDULED',
            'created_by_user_id' => $userB->id,
        ]);

        // Company A manager (has meeting.view) must not be able to read company B's meeting.
        $token = $this->login('manager@ametecs.io');
        $this->withToken($token)->getJson('/api/meetings/' . $meetingB->id)->assertNotFound();
    }

    // ---- B5: role defaults seeded (audit / evidence / attendance / meeting perms) ----

    public function test_role_permission_defaults_are_seeded(): void
    {
        $slugs = fn (string $roleSlug) => Role::whereNull('company_id')->where('slug', $roleSlug)
            ->first()->permissions()->pluck('slug')->all();

        $hr = $slugs('HR_ADMIN');
        foreach (['meeting.cancel', 'attendance.edit', 'audit.export', 'evidence.view', 'gate.override'] as $p) {
            $this->assertContains($p, $hr, "HR_ADMIN should hold {$p}");
        }

        $manager = $slugs('MANAGER');
        $this->assertContains('meeting.schedule', $manager);
        $this->assertNotContains('meeting.cancel', $manager, 'Manager should not cancel meetings by default');

        $auditor = $slugs('AUDITOR');
        $this->assertContains('meeting.reports', $auditor);
        $this->assertContains('audit.export', $auditor);
        $this->assertContains('evidence.view', $auditor);
        $this->assertNotContains('meeting.schedule', $auditor, 'Auditor is read-only for meetings');
    }
}
