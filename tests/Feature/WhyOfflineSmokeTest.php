<?php
namespace Tests\Feature;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The diagnostic must never throw — it is what gets run when everything else is confusing. */
class WhyOfflineSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_and_explains_every_employee(): void
    {
        $this->seed(DatabaseSeeder::class);
        \App\Models\EmployeeDevice::create([
            'company_id' => 1, 'employee_id' => \App\Models\Employee::withoutGlobalScopes()->value('id'),
            'device_uuid' => 'uuid-smoke', 'session_status' => 'FORCE_LOGOUT',
            'current_status' => 'ONLINE', 'last_heartbeat_at' => now(),
        ]);
        $this->artisan('smartept:why-offline')
            ->expectsOutputToContain('dashboard window')
            ->assertExitCode(0);
    }
}
