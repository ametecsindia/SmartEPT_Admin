<?php

namespace Tests\Feature;

use App\Models\ApplicationPolicy;
use App\Models\EnforcementState;
use App\Models\PolicyRule;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The diagnostic must never throw — it is what gets run when everything else is confusing, on a
 * client's server, by somebody who is already annoyed.
 *
 * 27-Aug-2026: the console read "Enforcing · 0 PC(s) reporting" while an agent was demonstrably
 * signed in, and working out why took several rounds of asking Ejaz to check one thing at a
 * time. Blocking is a chain of six links, each invisible from the link above. This asserts the
 * two answers that matter: it names the first broken link, and it does not crash.
 */
class WhyNotBlockingSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** With enforcement off, that is the FIRST thing it must say — everything below is moot. */
    public function test_it_names_the_tenant_switch_when_enforcement_is_off(): void
    {
        EnforcementState::forCompany(1)->update(['mode' => EnforcementState::OFF]);

        $this->artisan('smartept:why-not-blocking')
            ->expectsOutputToContain('Tenant switch')
            ->expectsOutputToContain('nothing is blocked for anybody')
            ->assertExitCode(0);
    }

    /**
     * The real one from the field. Enforcement on, ten rules saved, none armed, no PC enrolled —
     * a console that looks entirely healthy and blocks nothing.
     */
    public function test_it_catches_saved_rules_that_reach_no_pc(): void
    {
        EnforcementState::forCompany(1)->update(['mode' => EnforcementState::ENFORCE]);

        $policy = ApplicationPolicy::withoutGlobalScopes()->where('company_id', 1)->firstOrFail();

        // Exactly the shape that fools people: the action says Block, so the Rules screen looks
        // right, but the row is only TRACKED and is dropped without a word.
        PolicyRule::withoutGlobalScopes()->create([
            'company_id' => 1, 'policy_type' => 'APPLICATION', 'policy_id' => $policy->id,
            'item' => 'whatsapp', 'label' => 'WhatsApp', 'status' => 'TRACKED', 'action' => 'BLOCK',
        ]);

        $this->artisan('smartept:why-not-blocking')
            ->expectsOutputToContain('reach NO PC')
            ->expectsOutputToContain('status=TRACKED')
            ->expectsOutputToContain('no enforcement service has ever enrolled')
            ->assertExitCode(0);
    }

    /**
     * The invisible one. A client server running an older release has no handoff endpoint, so no
     * PC can ever enrol however many times somebody reinstalls the agent — and nothing in the
     * console says so. The route is present in this build, so this asserts the healthy answer;
     * its value is that the check exists at all and is exercised.
     */
    public function test_it_confirms_the_handoff_endpoint_this_build_needs(): void
    {
        $this->artisan('smartept:why-not-blocking')
            ->expectsOutputToContain('agent/enforcer-handoff')
            ->assertExitCode(0);
    }
}
