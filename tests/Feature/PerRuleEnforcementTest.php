<?php

namespace Tests\Feature;

use App\Models\ApplicationPolicy;
use App\Models\EnforcementAuditEvent;
use App\Models\EnforcementState;
use App\Models\PolicyRule;
use App\Models\WebsitePolicy;
use App\Services\ComplianceEvaluator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-rule actions and the audit gate.
 *
 * The bug these cover cost a client: the console offered "close the app" and
 * "block the site", saved the value, shipped it to the agent — and nothing
 * anywhere acted on it. A collection agency was penalised by their bank for
 * WhatsApp use that SmartEPT reported and never prevented.
 */
class PerRuleEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // The learning gate is half of what this class covers, and since 27-Aug-2026 it is
        // switched OFF on every client installation (Ejaz: "no learning mechanism in the
        // client"). Switched on here so these tests keep exercising the gate itself — that it
        // is REFUSED when learning is removed is a separate claim, tested in
        // EnforcementLearningRemovedTest rather than smuggled in as a passing 422 here.
        config(['smartept.enforcement_learning_enabled' => true]);
    }

    // --- migration: existing tenants keep today's behaviour ----------------

    public function test_existing_json_entries_become_rules_carrying_their_current_action(): void
    {
        $policy = ApplicationPolicy::withoutGlobalScopes()->first();
        $this->assertNotNull($policy, 'the seeder should create an application policy');

        // RefreshDatabase migrates an empty database, so the migration's own
        // backfill ran before the seeder existed and had nothing to convert.
        // Run it against the seeded policy — that is the upgrade a real tenant
        // performs, and it is idempotent by design.
        (require base_path('database/migrations/2026_08_21_000100_create_policy_rules_table.php'))->backfill();

        // Every seeded blocked app should now have a row of its own.
        foreach (['steam', 'utorrent', 'anydesk'] as $item) {
            $rule = PolicyRule::withoutGlobalScopes()
                ->where('policy_type', 'APPLICATION')
                ->where('policy_id', $policy->id)
                ->where('item', $item)
                ->first();

            $this->assertNotNull($rule, "blocked app {$item} did not become a rule");
            $this->assertSame('BLOCKED', $rule->status);
            // Decision 4: inherit the policy's action verbatim. Nobody gets
            // surprise enforcement from running an upgrade.
            $this->assertSame($policy->action_on_blocked, $rule->action, "{$item} changed behaviour on upgrade");
        }
    }

    public function test_no_tenant_starts_enforcing(): void
    {
        $policy = ApplicationPolicy::withoutGlobalScopes()->first();

        $enforcing = PolicyRule::withoutGlobalScopes()
            ->where('company_id', $policy->company_id)
            ->enforcing()
            ->count();

        $this->assertSame(0, $enforcing, 'a freshly migrated tenant must not have a single enforcing rule');

        $state = EnforcementState::forCompany($policy->company_id);
        $this->assertSame(EnforcementState::OFF, $state->mode);
    }

    // --- per-rule actions --------------------------------------------------

    public function test_each_rule_carries_its_own_action(): void
    {
        $evaluator = new ComplianceEvaluator();

        $policy = [
            'action_on_blocked' => 'WARN',
            'blocked_apps'      => ['steam', 'whatsapp'],
            'rules'             => [
                ['item' => 'steam',    'status' => 'BLOCKED', 'action' => 'WARN'],
                ['item' => 'whatsapp', 'status' => 'BLOCKED', 'action' => 'CLOSE'],
            ],
        ];

        $steam = $evaluator->classifyApp($policy, 'steam.exe');
        $this->assertTrue($steam['blocked']);
        $this->assertSame('WARN', $steam['action']);

        // The whole point: two blocked apps, two different actions, one policy.
        $whatsapp = $evaluator->classifyApp($policy, 'WhatsApp.exe');
        $this->assertTrue($whatsapp['blocked']);
        $this->assertSame('CLOSE', $whatsapp['action']);
        $this->assertSame('whatsapp', $whatsapp['rule']['item'] ?? null, 'the verdict must name the rule that fired');
    }

    public function test_a_bundle_without_rules_behaves_exactly_as_before(): void
    {
        $evaluator = new ComplianceEvaluator();

        // An agent at or below 0.14 sends no rules list. It must be unaffected.
        $policy = ['action_on_blocked' => 'SCREENSHOT', 'blocked_apps' => ['steam']];

        $verdict = $evaluator->classifyApp($policy, 'steam.exe');
        $this->assertTrue($verdict['blocked']);
        $this->assertSame('SCREENSHOT', $verdict['action']);
        $this->assertNull($verdict['rule']);
    }

    /**
     * The round trip the console actually performs: save a per-row action, then
     * re-read the policy list the way the Rules screen does on page load.
     *
     * This failed in the field — the screen saved CLOSE correctly and read it
     * back as WARN, because GET /policies/{type} returned the policy row with no
     * rules attached and every row fell through to action_on_blocked.
     */
    public function test_a_saved_row_action_survives_a_page_reload(): void
    {
        $this->actingAsCompanyAdmin();
        $policy = ApplicationPolicy::withoutGlobalScopes()->first();

        $this->putJson("/api/policies/application/{$policy->id}/rules", [
            'rules' => [
                ['item' => 'steam',    'label' => 'steam',    'status' => 'BLOCKED', 'action' => 'WARN'],
                ['item' => 'utorrent', 'label' => 'utorrent', 'status' => 'BLOCKED', 'action' => 'CLOSE'],
            ],
        ])->assertOk();

        // What the Rules screen fetches on load.
        $rules = collect($this->getJson('/api/policies/application')->assertOk()->json('data.0.rules'))
            ->keyBy('item');

        $this->assertNotEmpty($rules, 'the policy list must carry its per-item rules');
        $this->assertSame('WARN', $rules['steam']['action'] ?? null);
        $this->assertSame('CLOSE', $rules['utorrent']['action'] ?? null, 'a saved CLOSE must not read back as WARN');
        $this->assertArrayHasKey('confirmed', $rules['utorrent'], 'the console reads a boolean, not a timestamp');
    }

    // --- the matcher bug ---------------------------------------------------

    public function test_web_whatsapp_com_matches_a_browser_title(): void
    {
        $evaluator = new ComplianceEvaluator();
        $policy = ['action_on_blocked' => 'BLOCK', 'blocked_sites' => ['web.whatsapp.com']];

        // The exact case that never worked: the entry's first label is "web",
        // which is under the 4-character floor, so the entry matched nothing at
        // all and the title never contains the full domain.
        $verdict = $evaluator->classifyWebsite($policy, null, 'WhatsApp - Google Chrome');

        $this->assertTrue($verdict['blocked'], 'web.whatsapp.com still does not match a browser title');
        $this->assertSame('BLOCK', $verdict['action']);
    }


    public function test_a_generic_label_does_not_flag_every_browser_window(): void
    {
        $evaluator = new ComplianceEvaluator();
        $policy = ['action_on_blocked' => 'BLOCK', 'blocked_sites' => ['mail.google.com']];

        // Skipping the "mail" prefix leaves "google", and every Chrome window
        // title ends in "Google Chrome". Matching that as a whole word would
        // turn one blocked site into a violation on every tab an employee opens.
        $this->assertFalse($evaluator->classifyWebsite($policy, null, 'Gmail - Google Chrome')['blocked']);

        // The literal domain still matches, which is what the browser reports
        // once a real URL is available.
        $this->assertTrue($evaluator->classifyWebsite($policy, 'mail.google.com', null)['blocked']);
    }

    public function test_short_entries_still_need_the_literal_domain(): void
    {
        $evaluator = new ComplianceEvaluator();
        $policy = ['action_on_blocked' => 'BLOCK', 'blocked_sites' => ['x.com']];

        // The 4-character floor exists so "x" does not match every window title
        // containing the letter x. That guard must survive the fix.
        $this->assertFalse($evaluator->classifyWebsite($policy, null, 'Excel - Book1')['blocked']);
        $this->assertTrue($evaluator->classifyWebsite($policy, 'x.com', null)['blocked']);
    }

    public function test_allowed_still_wins_over_blocked(): void
    {
        $evaluator = new ComplianceEvaluator();
        $policy = [
            'action_on_blocked' => 'CLOSE',
            'allowed_apps'      => ['anydesk'],
            'blocked_apps'      => ['anydesk'],
            'rules'             => [['item' => 'anydesk', 'status' => 'BLOCKED', 'action' => 'CLOSE']],
        ];

        $verdict = $evaluator->classifyApp($policy, 'AnyDesk.exe');
        $this->assertFalse($verdict['blocked'], 'an explicitly allowed app must never be closed');
    }

    // --- the audit gate ----------------------------------------------------

    public function test_promotion_is_refused_while_the_report_is_empty(): void
    {
        $this->actingAsCompanyAdmin();

        $this->postJson('/api/enforcement/start-audit')->assertOk();

        // An empty report reads as "clean" and almost always means the service
        // is not running. Refusing it is the point.
        $res = $this->postJson('/api/enforcement/promote');
        $res->assertStatus(422);
        $this->assertStringContainsString('not a clean report', $res->json('message'));
    }

    public function test_promotion_is_refused_while_unexpected_programs_are_listed(): void
    {
        $this->actingAsCompanyAdmin();
        $companyId = $this->companyId();

        $this->postJson('/api/enforcement/start-audit')->assertOk();

        // Backdate so the minimum learning period is not what fails the check.
        $state = EnforcementState::forCompany($companyId);
        $state->forceFill(['audit_started_at' => now()->subDays(10)])->save();

        EnforcementAuditEvent::withoutGlobalScopes()->create([
            'company_id'  => $companyId,
            'device_uuid' => 'dev-1',
            'target'      => 'C:\\Users\\rsharma\\AppData\\Local\\Microsoft\\Teams\\current\\Teams.exe',
            'target_hash' => hash('sha256', 'teams'),
            'expected'    => false,
            'occurrences' => 12,
            'last_seen_at' => now(),
        ]);

        $res = $this->postJson('/api/enforcement/promote');
        $res->assertStatus(422);
        $this->assertStringContainsString('staff currently use', $res->json('message'));

        $this->assertSame(
            EnforcementState::AUDIT,
            EnforcementState::forCompany($companyId)->fresh()->mode,
            'a refused promotion must leave the tenant in audit'
        );
    }

    public function test_a_clean_report_promotes_and_records_who_cleared_it(): void
    {
        $user = $this->actingAsCompanyAdmin();
        $companyId = $this->companyId();

        $this->postJson('/api/enforcement/start-audit')->assertOk();
        EnforcementState::forCompany($companyId)
            ->forceFill(['audit_started_at' => now()->subDays(10)])->save();

        EnforcementAuditEvent::withoutGlobalScopes()->create([
            'company_id'  => $companyId,
            'device_uuid' => 'dev-1',
            'target'      => 'C:\\Program Files\\WindowsApps\\5319275A.WhatsAppDesktop_1.0\\WhatsApp.exe',
            'target_hash' => hash('sha256', 'whatsapp'),
            'expected'    => true,
            'occurrences' => 4,
            'last_seen_at' => now(),
        ]);

        $res = $this->postJson('/api/enforcement/promote')->assertOk();

        $state = EnforcementState::forCompany($companyId)->fresh();
        $this->assertSame(EnforcementState::ENFORCE, $state->mode);
        $this->assertSame($user->id, $state->cleared_by_user_id);
        $this->assertNotNull($state->cleared_report_id);
        $this->assertSame($state->cleared_report_id, $res->json('report_id'));
    }

    public function test_the_kill_switch_is_never_gated(): void
    {
        $this->actingAsCompanyAdmin();
        $companyId = $this->companyId();

        EnforcementState::forCompany($companyId)
            ->forceFill(['mode' => EnforcementState::ENFORCE])->save();

        $this->postJson('/api/enforcement/disable', ['reason' => 'client called'])->assertOk();

        $state = EnforcementState::forCompany($companyId)->fresh();
        $this->assertSame(EnforcementState::OFF, $state->mode);
        $this->assertSame('client called', $state->disabled_reason);
        $this->assertNotNull($state->disabled_at);
    }

    public function test_the_policy_version_moves_when_rules_change(): void
    {
        $resolver = app(\App\Services\PolicyResolver::class);
        $companyId = $this->companyId();

        $before = $resolver->latestPolicyVersionFor($companyId);

        $policy = WebsitePolicy::withoutGlobalScopes()->first();
        PolicyRule::withoutGlobalScopes()->create([
            'company_id'  => $companyId,
            'policy_type' => 'WEBSITE',
            'policy_id'   => $policy->id,
            'item'        => 'web.whatsapp.com',
            'status'      => 'BLOCKED',
            'action'      => 'BLOCK',
            'version'     => 1,
        ]);

        // bundle['policy_version'] is the MONITORING policy's version and never
        // moved for a rule edit, which is why rule changes reached endpoints so
        // slowly. This one has to move.
        $this->assertGreaterThan($before, $resolver->latestPolicyVersionFor($companyId));
    }

    // --- the learning period -----------------------------------------------

    /**
     * The learning period is configurable per deployment: 20 minutes on a pilot,
     * three days at a bank. It used to be a hardcoded 3 and nothing could shorten
     * it, so a one-PC test could never reach enforcement at all.
     */
    public function test_the_learning_period_is_configurable_and_gates_promotion(): void
    {
        config(['smartept.min_audit_minutes' => 20]);

        $this->actingAsCompanyAdmin();
        $companyId = $this->companyId();

        $this->postJson('/api/enforcement/start-audit')->assertOk();

        EnforcementAuditEvent::withoutGlobalScopes()->create([
            'company_id' => $companyId, 'device_uuid' => 'PC-1',
            'target' => 'whatsapp.exe', 'target_hash' => hash('sha256', 'whatsapp.exe'),
            'outcome' => 'WOULD_BLOCK', 'source' => 'PROCESS', 'expected' => true,
            'occurrences' => 3, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        // Five minutes in: refused, and it says how long is left rather than
        // naming a unit the admin did not choose.
        EnforcementState::forCompany($companyId)
            ->forceFill(['audit_started_at' => now()->subMinutes(5)])->save();

        $res = $this->getJson('/api/enforcement/audit-report')->assertOk();
        $this->assertFalse($res->json('data.promotion.allowed'));
        $this->assertStringContainsString('minute', $res->json('data.promotion.reason'));
        $this->postJson('/api/enforcement/promote')->assertStatus(422);

        // Twenty-one minutes in, report clean: allowed.
        EnforcementState::forCompany($companyId)
            ->forceFill(['audit_started_at' => now()->subMinutes(21)])->save();

        $this->assertTrue($this->getJson('/api/enforcement/audit-report')->json('data.promotion.allowed'));
        $this->postJson('/api/enforcement/promote')->assertOk();

        $this->assertSame(
            EnforcementState::ENFORCE,
            EnforcementState::forCompany($companyId)->fresh()->mode
        );
    }

    /** A regulated client raises it, and the same code holds them to it. */
    public function test_a_longer_period_still_holds(): void
    {
        config(['smartept.min_audit_minutes' => 4320]); // three days

        $this->actingAsCompanyAdmin();
        $companyId = $this->companyId();

        $this->postJson('/api/enforcement/start-audit')->assertOk();
        EnforcementAuditEvent::withoutGlobalScopes()->create([
            'company_id' => $companyId, 'device_uuid' => 'PC-1',
            'target' => 'whatsapp.exe', 'target_hash' => hash('sha256', 'whatsapp.exe'),
            'outcome' => 'WOULD_BLOCK', 'source' => 'PROCESS', 'expected' => true,
            'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        EnforcementState::forCompany($companyId)
            ->forceFill(['audit_started_at' => now()->subHours(2)])->save();

        $this->assertFalse($this->getJson('/api/enforcement/audit-report')->json('data.promotion.allowed'));
        $this->postJson('/api/enforcement/promote')->assertStatus(422);
    }

    // --- helpers -----------------------------------------------------------

    private function actingAsCompanyAdmin(): \App\Models\User
    {
        $user = \App\Models\User::query()
            ->whereRelation('role', 'slug', 'COMPANY_ADMIN')
            ->firstOrFail();

        // actingAs() does not survive TestCase::call(), which forgets the auth
        // guards before every request. Log in and carry a bearer token instead,
        // exactly as the rest of the suite does.
        $this->withToken($this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk()->json('token'));

        return $user;
    }

    private function companyId(): int
    {
        return (int) ApplicationPolicy::withoutGlobalScopes()->first()->company_id;
    }
}
