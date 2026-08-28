<?php

namespace Tests\Feature;

use App\Models\EnforcementState;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 26-Aug-2026 (Ejaz): SMARTEPT_ENFORCEMENT_DEFAULT=ENFORCE was set on an ALREADY-INSTALLED
 * client and nothing happened — firstOrCreate ignores the attributes once the row exists.
 * This command is how an existing site is armed.
 */
class EnforcementSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_it_arms_a_site_that_is_already_learning(): void
    {
        $before = EnforcementState::forCompany(1);
        $before->update(['mode' => EnforcementState::AUDIT, 'audit_started_at' => now()]);
        $version = (int) $before->policy_version;

        $this->artisan('smartept:enforcement on')->assertExitCode(0);

        $after = EnforcementState::forCompany(1)->refresh();
        $this->assertSame(EnforcementState::ENFORCE, $after->mode);
        $this->assertSame('PRECONFIGURED', $after->cleared_report_id);
        $this->assertNull($after->cleared_by_user_id, 'nobody cleared a report — credit no one');
        $this->assertGreaterThan($version, (int) $after->policy_version,
            'without a version bump the endpoints never re-fetch and keep enforcing the old policy');
    }

    public function test_off_records_a_reason_and_bumps_the_version(): void
    {
        $version = (int) EnforcementState::forCompany(1)->policy_version;

        $this->artisan('smartept:enforcement off --reason="CRM stopped opening"')->assertExitCode(0);

        $s = EnforcementState::forCompany(1)->refresh();
        $this->assertSame(EnforcementState::OFF, $s->mode);
        $this->assertSame('CRM stopped opening', $s->disabled_reason);
        $this->assertNotNull($s->disabled_at);
        $this->assertGreaterThan($version, (int) $s->policy_version);
    }

    /**
     * 27-Aug-2026 (Ejaz): "there should be no learning mechanism in the client."
     *
     * This used to assert that `audit` restarted the learning clock. It now asserts the
     * opposite, which is the change: the command is the last thing on a client installation
     * that could still write AUDIT, so it has to refuse like the console does. A site where
     * the console offers two states and the command line offers three is one support call
     * away from a tenant nobody can explain.
     */
    public function test_audit_is_refused_when_learning_is_not_part_of_this_installation(): void
    {
        config(['smartept.enforcement_learning_enabled' => false]);

        $this->artisan('smartept:enforcement audit')->assertExitCode(1);

        $this->assertSame(EnforcementState::OFF, EnforcementState::forCompany(1)->refresh()->mode,
            'a refused command must not have written anything');
    }

    /** With learning switched back on for our own lab, the clock still starts. */
    public function test_audit_restarts_the_learning_clock_when_learning_is_enabled(): void
    {
        config(['smartept.enforcement_learning_enabled' => true]);

        $this->artisan('smartept:enforcement audit')->assertExitCode(0);

        $s = EnforcementState::forCompany(1)->refresh();
        $this->assertSame(EnforcementState::AUDIT, $s->mode);
        $this->assertNotNull($s->audit_started_at);
    }

    public function test_no_argument_only_reports_and_changes_nothing(): void
    {
        $s = EnforcementState::forCompany(1);
        $s->update(['mode' => EnforcementState::AUDIT]);

        $this->artisan('smartept:enforcement')->assertExitCode(0);

        $this->assertSame(EnforcementState::AUDIT, EnforcementState::forCompany(1)->refresh()->mode);
    }

    public function test_an_unknown_state_changes_nothing(): void
    {
        $this->artisan('smartept:enforcement maybe')->assertExitCode(1);

        $this->assertSame(EnforcementState::OFF, EnforcementState::forCompany(1)->refresh()->mode);
    }

    /**
     * 27-Aug-2026 (Ejaz): "Only Enforcement ON or OFF and no learn option." The console now
     * arms directly through POST /enforcement/enable. promote() is untouched and still refuses
     * without a clean report — the two paths must stay distinguishable.
     */
    public function test_the_console_can_arm_directly_from_a_learning_state(): void
    {
        EnforcementState::forCompany(1)->update([
            'mode' => EnforcementState::AUDIT, 'audit_started_at' => now(),
        ]);
        $version = (int) EnforcementState::forCompany(1)->policy_version;

        $token = $this->postJson('/api/auth/login', [
            'email' => 'admin@ametecs.io', 'password' => 'password',
        ])->assertOk()->json('token');

        $this->withToken($token)->postJson('/api/enforcement/enable')->assertOk();

        $s = EnforcementState::forCompany(1)->refresh();
        $this->assertSame(EnforcementState::ENFORCE, $s->mode);
        $this->assertSame('ENABLED_DIRECTLY', $s->cleared_report_id,
            'an operator armed this — it must not masquerade as a cleared audit report');
        $this->assertNotNull($s->cleared_by_user_id, 'a person clicked it; record who');
        $this->assertGreaterThan($version, (int) $s->policy_version,
            'without a version bump no endpoint re-fetches and nothing actually blocks');
    }

    /**
     * promote() keeps its gate — arming directly must not have weakened the honest path.
     *
     * Learning is switched on here deliberately: with it off, promote() is refused before it
     * reads a report at all, and this test would pass without ever exercising the gate it
     * exists to protect. (That refusal has its own test in EnforcementLearningRemovedTest.)
     */
    public function test_promote_still_refuses_without_a_clean_report(): void
    {
        config(['smartept.enforcement_learning_enabled' => true]);

        EnforcementState::forCompany(1)->update([
            'mode' => EnforcementState::AUDIT, 'audit_started_at' => now(),
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'admin@ametecs.io', 'password' => 'password',
        ])->assertOk()->json('token');

        $this->withToken($token)->postJson('/api/enforcement/promote')->assertStatus(422);
        $this->assertSame(EnforcementState::AUDIT, EnforcementState::forCompany(1)->refresh()->mode);
    }
}
