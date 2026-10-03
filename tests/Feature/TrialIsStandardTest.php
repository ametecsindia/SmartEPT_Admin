<?php

namespace Tests\Feature;

use App\Models\InstallationLicense;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 30-Sep-2026 (Ejaz): a Cloud trial is Standard — it gets the features its licence carries,
 * no longer everything. Enforcer/Commander on a trial = a Super Admin grant in Central.
 */
class TrialIsStandardTest extends TestCase
{
    use RefreshDatabase;

    private function licence(array $features): InstallationLicense
    {
        $l = InstallationLicense::current();
        $l->forceFill([
            'license_key' => 'SEPT-TRIA-LKEY-TEST-ABCD', 'status' => 'active', 'last_checked_at' => now(),
            'bundle' => ['kind' => 'trial', 'tier' => 'standard', 'status' => 'active', 'features' => $features,
                'expires_at' => now()->addDays(5)->toDateString(), 'grace_days' => 0, 'device_limit' => 10],
        ])->save();

        return $l->fresh();
    }

    public function test_a_standard_trial_has_no_enforcement_or_liveview(): void
    {
        $l = $this->licence(['attendance' => true, 'live_screen' => true]);
        $this->assertFalse($l->hasFeature('enforcement'));
        $this->assertFalse($l->hasFeature('live_view'));
    }

    public function test_a_trial_granted_commander_gets_both(): void
    {
        $l = $this->licence(['enforcement' => true, 'live_view' => true, 'liveview_max_users' => 5]);
        $this->assertTrue($l->hasFeature('enforcement'));
        $this->assertTrue($l->hasFeature('live_view'));
    }

    public function test_the_console_is_told_what_the_plan_allows(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->licence(['enforcement' => true, 'live_view' => false]);
        $token = $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');
        $this->withToken($token)->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('user.plan_access', ['enforcement' => true, 'live_view' => false]);
    }

    public function test_no_key_evaluation_window_is_unchanged(): void
    {
        $this->assertTrue(InstallationLicense::current()->hasFeature('enforcement'));
    }
}
