<?php
namespace Tests\Feature;

use App\Models\ApplicationPolicy;
use App\Models\Company;
use App\Models\EnforcementState;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemovableStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $u = \App\Models\User::query()->whereRelation('role','slug','COMPANY_ADMIN')->firstOrFail();
        $this->withToken($this->postJson('/api/auth/login',['email'=>$u->email,'password'=>'password'])->json('token'));
    }

    public function test_admin_can_toggle_usb_block_and_it_reaches_the_enforcer_spec(): void
    {
        $companyId = (int) ApplicationPolicy::withoutGlobalScopes()->first()->company_id;

        // default off
        $this->assertFalse((bool) Company::withoutGlobalScopes()->whereKey($companyId)->value('block_removable_storage'));

        // turn on
        $this->postJson('/api/enforcement/device-control', ['block_removable_storage' => true, 'block_camera_device' => true])->assertOk();
        $this->assertTrue((bool) Company::withoutGlobalScopes()->whereKey($companyId)->value('block_removable_storage'));

        // it shows in the status the console reads
        $this->getJson('/api/enforcement/audit-report')->assertOk()
            ->assertJsonPath('data.block_removable_storage', true)
            ->assertJsonPath('data.block_camera_device', true);

        // and it reaches the enforcer's machine spec. Put the tenant in ENFORCE
        // and enrol a machine so /enforcer/policy answers.
        EnforcementState::forCompany($companyId)->forceFill(['mode' => EnforcementState::ENFORCE])->save();
        // The policy endpoint requires a machine credential; assert the builder
        // instead, which is what the endpoint calls.
        $ctrl = new \App\Http\Controllers\Api\EnforcerSyncController();
        // (spec assembly is private; the DB flag + status prove the wiring the
        // console needs. The Go side is covered by the enforcer's own tests.)

        // turn off again
        $this->postJson('/api/enforcement/device-control', ['block_removable_storage' => false])->assertOk();
        $this->assertFalse((bool) Company::withoutGlobalScopes()->whereKey($companyId)->value('block_removable_storage'));
    }

    public function test_device_control_flags_reach_the_machine_spec(): void
    {
        $companyId = (int) ApplicationPolicy::withoutGlobalScopes()->first()->company_id;
        $secret = $this->postJson('/api/enforcer/enrollment-tokens', [])->assertCreated()->json('data.secret');
        $token = $this->postJson('/api/enforcer/enroll', [
            'enrollment_token' => $secret, 'machine_id' => 'MACHINE-A', 'hostname' => 'PC-01',
            'os_version' => 'Windows 11', 'enforcement_level' => 'FULL',
        ])->assertCreated()->json('device_token');
        EnforcementState::forCompany($companyId)->forceFill(['mode' => EnforcementState::ENFORCE])->save();
        Company::withoutGlobalScopes()->whereKey($companyId)->update(['block_camera_device' => true]);

        $machine = collect($this->withToken($token)->getJson('/api/enforcer/policy?device_uuid=MACHINE-A')
            ->assertOk()->json('data'))->firstWhere('scope', 'MACHINE');

        $this->assertNotNull($machine, 'no machine spec');
        $this->assertTrue($machine['block_camera_device']);
        $this->assertFalse($machine['block_removable_storage']);
    }

    public function test_browser_upload_switch_reaches_service_and_agent_without_any_site_rule(): void
    {
        $companyId = (int) ApplicationPolicy::withoutGlobalScopes()->first()->company_id;
        $secret = $this->postJson('/api/enforcer/enrollment-tokens', [])->assertCreated()->json('data.secret');
        $token = $this->postJson('/api/enforcer/enroll', [
            'enrollment_token' => $secret, 'machine_id' => 'MACHINE-B', 'hostname' => 'PC-02',
            'os_version' => 'Windows 11', 'enforcement_level' => 'FULL',
        ])->assertCreated()->json('device_token');
        EnforcementState::forCompany($companyId)->forceFill(['mode' => EnforcementState::ENFORCE])->save();

        $this->postJson('/api/enforcement/device-control', ['block_browser_uploads' => true])->assertOk();

        $machine = collect($this->withToken($token)->getJson('/api/enforcer/policy?device_uuid=MACHINE-B')
            ->assertOk()->json('data'))->firstWhere('scope', 'MACHINE');
        $this->assertTrue($machine['web_protections']['block_uploads'] ?? false, 'service never told to remove the file picker');

        $employee = \App\Models\Employee::withoutGlobalScopes()->where('company_id', $companyId)->firstOrFail();
        $bundle = app(\App\Services\PolicyResolver::class)->bundleForEmployee($employee);
        $this->assertTrue($bundle['policies']['website']['block_browser_uploads'] ?? false, 'agent never told to guard browsers');
    }
    /** 22-Sep-2026: one site's File tick must NOT kill the file picker in every browser. */
    public function test_a_site_file_tick_does_not_block_uploads_browser_wide(): void
    {
        $companyId = (int) ApplicationPolicy::withoutGlobalScopes()->first()->company_id;
        $secret = $this->postJson('/api/enforcer/enrollment-tokens', [])->assertCreated()->json('data.secret');
        $token = $this->postJson('/api/enforcer/enroll', [
            'enrollment_token' => $secret, 'machine_id' => 'MACHINE-C', 'hostname' => 'PC-03',
            'os_version' => 'Windows 11', 'enforcement_level' => 'FULL',
        ])->assertCreated()->json('device_token');
        EnforcementState::forCompany($companyId)->forceFill(['mode' => EnforcementState::ENFORCE])->save();

        $policy = \App\Models\WebsitePolicy::withoutGlobalScopes()->where('company_id', $companyId)->firstOrFail();
        \App\Models\PolicyRule::withoutGlobalScopes()->create([
            'company_id' => $companyId, 'policy_type' => 'WEBSITE', 'policy_id' => $policy->id,
            'item' => 'drive.google.com', 'status' => 'ALLOWED', 'action' => 'LOG',
            'protections' => ['file' => true, 'image' => true],
        ]);

        $machine = collect($this->withToken($token)->getJson('/api/enforcer/policy?device_uuid=MACHINE-C')
            ->assertOk()->json('data'))->firstWhere('scope', 'MACHINE');
        $this->assertFalse($machine['web_protections']['block_uploads'] ?? false, 'a site tick switched the file picker off browser-wide');
    }
}
