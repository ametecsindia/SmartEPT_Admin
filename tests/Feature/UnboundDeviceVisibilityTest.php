<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 28-Sep-2026: an employee got "This device was unbound by an administrator. Ask them to
 * approve a re-bind on the Devices screen." on one PC, but that PC was not on the Devices
 * screen, so nothing could be approved. The same employee signed in fine on another PC.
 */
class UnboundDeviceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    public function test_shared_pc_unbound_for_previous_user_surfaces_under_the_new_user_for_rebind(): void
    {
        // Priya used the PC, then her device was unbound (e.g. relieved/offboarded).
        $priya = $this->login('priya.raman@ametecs.io');
        $this->withToken($priya)->postJson('/api/agent/register-device', [
            'device_uuid' => 'SHARED-PC-1', 'computer_name' => 'DESK-07',
        ])->assertCreated();
        $device = EmployeeDevice::where('device_uuid', 'SHARED-PC-1')->firstOrFail();
        $admin = $this->login('admin@ametecs.io');
        $this->withToken($admin)->postJson("/api/devices/{$device->id}/unbind")->assertOk();

        // Dev sits at the same PC. Still blocked (R2-3) …
        $dev = $this->login('dev.patel@ametecs.io');
        $this->withToken($dev)->postJson('/api/agent/register-device', [
            'device_uuid' => 'SHARED-PC-1', 'computer_name' => 'DESK-07',
        ])->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_UNBOUND');

        // … but the row now belongs to Dev, so his admins see it under his name.
        $devEmployee = Employee::whereHas('user', fn ($q) => $q->where('email', 'dev.patel@ametecs.io'))->firstOrFail();
        $this->assertSame($devEmployee->id, $device->fresh()->employee_id);
        $this->assertNotNull($device->fresh()->unbound_at);

        $list = $this->withToken($admin)->getJson('/api/devices?per_page=500')->assertOk()->json('data');
        $row = collect($list)->firstWhere('device_uuid', 'SHARED-PC-1');
        $this->assertNotNull($row, 'the blocked PC must be on the Devices screen');
        $this->assertSame($devEmployee->id, $row['employee_id']);

        // Approve re-bind → Dev signs in.
        $this->withToken($admin)->postJson("/api/devices/{$device->id}/rebind")->assertOk();
        $this->withToken($dev)->postJson('/api/agent/register-device', [
            'device_uuid' => 'SHARED-PC-1', 'computer_name' => 'DESK-07',
        ])->assertCreated();
    }

    public function test_another_companys_unbind_does_not_block_this_company(): void
    {
        $priya = $this->login('priya.raman@ametecs.io');
        $this->withToken($priya)->postJson('/api/agent/register-device', [
            'device_uuid' => 'MOVED-PC-1', 'computer_name' => 'TEST-LAPTOP',
        ])->assertCreated();

        // The PC was last registered — and unbound — under a different tenant.
        $foreign = Company::create(['name' => 'Other Corp', 'code' => 'OTHER-UNB', 'status' => 'ACTIVE']);
        EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'MOVED-PC-1')
            ->update(['company_id' => $foreign->id, 'unbound_at' => now()]);

        $this->withToken($priya)->postJson('/api/agent/register-device', [
            'device_uuid' => 'MOVED-PC-1', 'computer_name' => 'TEST-LAPTOP',
        ])->assertCreated();

        $row = EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'MOVED-PC-1')->firstOrFail();
        $employee = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->firstOrFail();
        $this->assertSame($employee->company_id, $row->company_id);
        $this->assertNull($row->unbound_at);
    }
}
