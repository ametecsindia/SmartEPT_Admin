<?php

namespace Tests\Feature;

use App\Models\EnforcementState;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 26-Aug-2026 (Ejaz): "in the client package, the enforcement should be ON by default, no
 * learning again on client side."
 *
 * The catalogue work — which apps to block, Store vs desktop identifiers, website categories,
 * the BANKING profile — is done once here and ships with the package. A client repeating it
 * discovers nothing, so a fresh install may start armed.
 *
 * The two properties that must NOT be lost while doing that:
 *   1. an UPGRADE still never arms an existing installation (decision 4);
 *   2. a tenant shipped armed is recorded as PRECONFIGURED, never as a promotion that passed a
 *      clean audit — the console must be able to tell the truth about which one happened.
 */
class EnforcementDefaultModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);   // enforcement_states.company_id is a real FK
    }

    public function test_it_defaults_to_off_so_an_upgrade_never_arms_anybody(): void
    {
        config(['smartept.enforcement_default_mode' => 'OFF']);

        $state = EnforcementState::forCompany(1);

        $this->assertSame(EnforcementState::OFF, $state->mode);
        $this->assertFalse($state->isEnforcing());
    }

    public function test_a_client_package_can_ship_armed(): void
    {
        config(['smartept.enforcement_default_mode' => 'ENFORCE']);

        $state = EnforcementState::forCompany(1);

        $this->assertSame(EnforcementState::ENFORCE, $state->mode);
        $this->assertTrue($state->isEnforcing());
    }

    /** Shipped-armed and promoted-after-a-clean-audit are different claims. */
    public function test_a_shipped_armed_tenant_is_marked_preconfigured_not_a_fake_report(): void
    {
        config(['smartept.enforcement_default_mode' => 'ENFORCE']);

        $state = EnforcementState::forCompany(1);

        $this->assertSame('PRECONFIGURED', $state->cleared_report_id);
        $this->assertNotNull($state->cleared_at);
        $this->assertNull($state->cleared_by_user_id, 'nobody cleared a report — no user may be credited');
    }

    /** A junk value must fail safe, never arm anything. */
    public function test_an_unrecognised_value_falls_back_to_off(): void
    {
        config(['smartept.enforcement_default_mode' => 'yes-please']);

        $this->assertSame(EnforcementState::OFF, EnforcementState::forCompany(1)->mode);
    }

    /**
     * The kill switch has to survive this. Once a row exists, forCompany() must return what is
     * stored — otherwise an admin disables enforcement and the config quietly re-arms it on the
     * very next call, which would make the one ungated safety control useless.
     */
    public function test_disabling_is_not_undone_by_the_config_default(): void
    {
        config(['smartept.enforcement_default_mode' => 'ENFORCE']);

        $state = EnforcementState::forCompany(1);
        $state->update(['mode' => EnforcementState::OFF, 'disabled_at' => now(), 'disabled_reason' => 'A program stopped opening']);

        $this->assertSame(EnforcementState::OFF, EnforcementState::forCompany(1)->mode,
            'the kill switch must stay off until someone turns it back on');
    }

    /** The client package's own template must actually carry the setting. */
    public function test_the_shipped_env_template_arms_a_new_install(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('SMARTEPT_ENFORCEMENT_DEFAULT=ENFORCE', $env);
        $this->assertStringContainsString('%LOCALAPPDATA%', $env,
            'the template must state what skipping the learning period trades away');
    }
}
