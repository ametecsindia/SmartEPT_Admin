<?php

namespace Tests\Feature;

use App\Models\EnforcementState;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 27-Aug-2026 (Ejaz): "there should be no learning mechanism in the client. Whatever you have
 * learnt so far, implement that. By default, keep enforcement ON in the client package."
 *
 * Removing a feature is only done when nothing can still reach it. There were four ways into
 * AUDIT — the config default, the start-audit endpoint, the promote endpoint, and the
 * command-line switch — and a client site put back into learning by any one of them looks
 * exactly like the bug he reported: enforcement "on", nothing blocked, learning again.
 *
 * So each of the four is covered here, plus the state an UPGRADED site is actually in: a row
 * that already says AUDIT. That one must read OFF and must NOT be silently promoted to
 * ENFORCE — an upgrade that starts blocking on its own is decision 4, and it stands.
 */
class EnforcementLearningRemovedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);   // enforcement_states.company_id is a real FK
        config(['smartept.enforcement_learning_enabled' => false]);
    }

    private function adminToken(): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'admin@ametecs.io', 'password' => 'password',
        ])->assertOk()->json('token');
    }

    // ---- the four ways in -------------------------------------------------

    /** 1. The config default. AUDIT is not a mode a new row may be created in. */
    public function test_the_config_default_cannot_start_a_new_site_in_learning(): void
    {
        config(['smartept.enforcement_default_mode' => 'AUDIT']);

        // OFF, not ENFORCE: an unusable value must never be the thing that arms an estate.
        $this->assertSame(EnforcementState::OFF, EnforcementState::forCompany(1)->mode);
    }

    /** 2. The endpoint that starts a learning period. */
    public function test_start_audit_is_refused(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/enforcement/start-audit')
            ->assertStatus(422)
            ->assertJsonPath('data.learning_enabled', false);

        $this->assertSame(EnforcementState::OFF, EnforcementState::forCompany(1)->mode,
            'a refused request must not have written anything');
    }

    /** 3. The endpoint that promotes out of one. Refused before it reads any report. */
    public function test_promote_is_refused(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/enforcement/promote')
            ->assertStatus(422)
            ->assertJsonPath('data.use_instead', 'POST /api/enforcement/enable');
    }

    /** 4. The command line. Two states in the console and three here is a support call. */
    public function test_the_command_refuses_audit(): void
    {
        $this->artisan('smartept:enforcement', ['state' => 'audit'])
            ->assertExitCode(1);

        $this->assertSame(EnforcementState::OFF, EnforcementState::forCompany(1)->mode);
    }

    /** ...but the two states that DO exist still work from the command line. */
    public function test_the_command_still_turns_enforcement_on_and_off(): void
    {
        $this->artisan('smartept:enforcement', ['state' => 'on'])->assertExitCode(0);
        $this->assertSame(EnforcementState::ENFORCE, EnforcementState::forCompany(1)->mode);

        $this->artisan('smartept:enforcement', ['state' => 'off'])->assertExitCode(0);
        $this->assertSame(EnforcementState::OFF, EnforcementState::forCompany(1)->mode);
    }

    // ---- the site that is ALREADY mid-learning ----------------------------

    /**
     * The upgrade case, and the one with a wrong answer that looks right.
     *
     * Reading a stored AUDIT as ENFORCE would "finish the migration" and start blocking on an
     * estate nobody surveyed, during an upgrade, with nobody having pressed anything.
     */
    public function test_a_site_left_mid_learning_acts_off_and_is_never_auto_armed(): void
    {
        $state = EnforcementState::forCompany(1);
        $state->update(['mode' => EnforcementState::AUDIT, 'audit_started_at' => now()]);

        $fresh = EnforcementState::forCompany(1);

        $this->assertSame(EnforcementState::OFF, $fresh->effectiveMode(), 'acts OFF');
        $this->assertSame(EnforcementState::AUDIT, $fresh->mode, 'the stored value is not rewritten behind anyone\'s back');
        $this->assertFalse($fresh->isEnforcing(), 'and above all it does not start blocking');
    }

    /** The console must be able to draw it: the report says OFF, and says why. */
    public function test_the_console_report_shows_off_for_a_stored_audit(): void
    {
        EnforcementState::forCompany(1)->update(['mode' => EnforcementState::AUDIT]);

        $this->withToken($this->adminToken())
            ->getJson('/api/enforcement/audit-report')
            ->assertOk()
            ->assertJsonPath('data.mode', EnforcementState::OFF)
            ->assertJsonPath('data.stored_mode', EnforcementState::AUDIT)
            ->assertJsonPath('data.learning_enabled', false);
    }

    // ---- and the escape hatch still exists for our own lab ----------------

    /**
     * Learning is removed, not deleted. Surveying a genuinely unknown estate is the one job
     * the catalogue cannot do, so the gate has to still work when it is switched back on —
     * otherwise this is a one-way door with no way to check our own work.
     */
    public function test_turning_learning_back_on_restores_the_gate(): void
    {
        config(['smartept.enforcement_learning_enabled' => true]);

        $this->withToken($this->adminToken())
            ->postJson('/api/enforcement/start-audit')
            ->assertOk();

        $this->assertSame(EnforcementState::AUDIT, EnforcementState::forCompany(1)->mode);

        // And promote is still gated on a clean report, exactly as it always was —
        // no endpoint has reported anything, so an empty report is not a clean one.
        $this->withToken($this->adminToken())
            ->postJson('/api/enforcement/promote')
            ->assertStatus(422);
    }

    /** The shipped template must carry the setting, or a package ships with learning on. */
    public function test_the_shipped_env_template_removes_learning(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('SMARTEPT_ENFORCEMENT_LEARNING=false', $env);
        $this->assertStringContainsString('SMARTEPT_ENFORCEMENT_DEFAULT=ENFORCE', $env);
    }
}
