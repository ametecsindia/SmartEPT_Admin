<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\EmployeeComplianceEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Caller mobile app → SmartEPT (6-Oct-2026): field sign-in punches carry their
 * location, and the GPS trail raises/resolves GEOFENCE_EXIT violations.
 */
class CallerFieldForceTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->employee = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $this->secret = 'sk_live_caller_' . uniqid();
        ApiKey::create(['company_id' => $this->employee->company_id, 'name' => 'Caller', 'prefix' => substr($this->secret, 0, 12),
            'key_hash' => hash('sha256', $this->secret), 'scopes' => ['ingest', 'read'], 'active' => true]);
    }

    private function fix(string $id, string $time, string $geo, float $dist = 0.2): array
    {
        return ['external_id' => $id, 'employee_code' => 'E-1001', 'captured_at' => now()->toDateString() . "T{$time}+05:30",
            'lat' => 17.46, 'lng' => 78.36, 'geo_status' => $geo, 'distance_km' => $dist, 'limit_km' => 0.5];
    }

    public function test_sign_in_punch_keeps_location_in_metadata(): void
    {
        $this->withHeader('X-Api-Key', $this->secret)->postJson('/api/v1/attendance/punches', ['punches' => [[
            'employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => now()->toDateString() . ' 09:05:00',
            'external_id' => 'caller-login-1', 'source' => 'CALLER', 'lat' => 17.46, 'lng' => 78.36, 'geo_status' => 'within',
        ]]])->assertStatus(202);

        $meta = json_decode((string) BiometricLog::withoutGlobalScopes()->where('external_id', 'caller-login-1')->value('metadata'), true);
        $this->assertSame('within', $meta['geo_status']);
        $this->assertSame('CALLER', $meta['source']);
    }

    public function test_leaving_the_fence_raises_one_violation_and_returning_resolves_it(): void
    {
        $post = fn (array $locs) => $this->withHeader('X-Api-Key', $this->secret)->postJson('/api/v1/fieldforce/locations', ['locations' => $locs]);

        $post([$this->fix('l1', '09:00:00', 'within'), $this->fix('l2', '09:05:00', 'outside', 3.1), $this->fix('l3', '09:10:00', 'outside', 3.4)])
            ->assertStatus(202)->assertJsonPath('stored', 3);
        $post([$this->fix('l2', '09:05:00', 'outside', 3.1)])->assertJsonPath('stored', 0);   // re-delivery

        $events = EmployeeComplianceEvent::withoutGlobalScopes()->where('event_type', 'GEOFENCE_EXIT')->get();
        $this->assertCount(1, $events);
        $this->assertNull($events[0]->resolved_at);

        $post([$this->fix('l4', '09:20:00', 'within')]);
        $this->assertNotNull(EmployeeComplianceEvent::withoutGlobalScopes()->where('event_type', 'GEOFENCE_EXIT')->value('resolved_at'));

        $this->withHeader('X-Api-Key', $this->secret)->getJson('/api/v1/fieldforce/locations?employee_code=e-1001')
            ->assertOk()->assertJsonPath('count', 4);
        $this->assertSame(4, DB::table('field_force_locations')->count());
    }
}
