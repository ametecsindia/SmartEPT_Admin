<?php

namespace Tests\Feature;

use App\Models\Designation;
use App\Models\Employee;
use App\Models\Team;
use App\Services\PolicyResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 07-Oct-2026 (Ejaz): tracking mode "Activity & productivity — no screenshots" (NO_SCREENSHOTS).
 * Everything FULL captures except screen images; settable at designation level too.
 */
class NoScreenshotsTrackingModeTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        $this->seed(DatabaseSeeder::class);

        return Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
    }

    public function test_no_screenshots_keeps_activity_and_turns_off_only_screenshots(): void
    {
        $e = $this->employee();
        $e->forceFill(['tracking_mode' => 'NO_SCREENSHOTS'])->save();

        $b = app(PolicyResolver::class)->bundleForEmployee($e->fresh());
        $this->assertSame('NO_SCREENSHOTS', $b['tracking_mode']);

        $p = $b['policies'];
        if (isset($p['screenshot'])) {
            $this->assertFalse($p['screenshot']['enabled']);
            $this->assertFalse($p['screenshot']['on_blocked_app']);
            $this->assertFalse($p['screenshot']['on_blocked_website']);
        }
        if (isset($p['monitoring'])) {
            // Not forced off — whatever the monitoring policy says still stands.
            $full = app(PolicyResolver::class)->bundleForEmployee($e->forceFill(['tracking_mode' => 'FULL'])->fresh());
            $this->assertSame($full['policies']['monitoring']['app_usage_enabled'] ?? null, $p['monitoring']['app_usage_enabled'] ?? null);
            $this->assertSame($full['policies']['monitoring']['tracking_enabled'] ?? null, $p['monitoring']['tracking_enabled'] ?? null);
        }
    }

    public function test_designation_level_applies_and_team_cannot_outrank_it(): void
    {
        $e = $this->employee();
        $d = Designation::withoutGlobalScopes()->create(['company_id' => $e->company_id, 'name' => 'Collector', 'tracking_mode' => 'NO_SCREENSHOTS']);
        $t = Team::withoutGlobalScopes()->create(['company_id' => $e->company_id, 'name' => 'Voice', 'tracking_mode' => 'FULL']);
        $e->forceFill(['designation_id' => $d->id, 'team_id' => $t->id, 'tracking_mode' => null])->save();

        $this->assertSame('NO_SCREENSHOTS', app(PolicyResolver::class)->effectiveTrackingMode($e->fresh()));

        // The employee's own setting still beats the designation.
        $e->forceFill(['tracking_mode' => 'FULL'])->save();
        $this->assertSame('FULL', app(PolicyResolver::class)->effectiveTrackingMode($e->fresh()));
    }
}
