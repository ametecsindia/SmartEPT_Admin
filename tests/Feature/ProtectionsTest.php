<?php

namespace Tests\Feature;

use App\Models\ApplicationPolicy;
use App\Models\PolicyRule;
use App\Models\WebsitePolicy;
use App\Services\ProtectionCapabilities;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Protections: block an ACTIVITY inside an application, not the application.
 *
 * Companion to PerRuleEnforcementTest, which covers the all-or-nothing half.
 * The client's brief for this feature says the same thing three times and it is
 * what almost every assertion here is really checking:
 *
 *     Application = Allowed. Specific activity = Blocked.
 *
 * So the failure mode these tests exist to catch is not "the checkbox does not
 * save". It is a protection that quietly becomes a full block, and a protection
 * the console reports while nothing on the endpoint acts on it.
 */
class ProtectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function appPolicy(): ApplicationPolicy
    {
        return ApplicationPolicy::withoutGlobalScopes()->first();
    }

    // --- backward compatibility -------------------------------------------

    public function test_a_rule_saved_without_protections_stores_null(): void
    {
        $this->actingAsCompanyAdmin();
        $policy = $this->appPolicy();

        $this->putJson("/api/policies/application/{$policy->id}/rules", [
            'rules' => [
                ['item' => 'steam', 'label' => 'steam', 'status' => 'BLOCKED', 'action' => 'WARN'],
            ],
        ])->assertOk();

        $rule = PolicyRule::withoutGlobalScopes()->where('item', 'steam')->first();

        // NULL, not []. The column's "nothing set" state and the pre-upgrade
        // state have to be the same value, or a rule saved by this build and
        // one that predates the column look different to every reader.
        $this->assertNull($rule->protections);
        $this->assertSame([], $rule->protectionList());
        $this->assertFalse($rule->hasProtections());
    }

    public function test_an_existing_rule_keeps_working_exactly_as_before(): void
    {
        (require base_path('database/migrations/2026_08_21_000100_create_policy_rules_table.php'))->backfill();

        foreach (PolicyRule::withoutGlobalScopes()->get() as $rule) {
            $this->assertNull($rule->protections, 'the upgrade must not invent a protection on any existing rule');
        }

        // And the existing full-block semantics are untouched: the console's
        // rename to "Full Block & Close" was words on a screen, nothing else.
        $r = new PolicyRule(['status' => 'BLOCKED', 'action' => 'CLOSE']);
        $this->assertTrue($r->isEnforcing());
    }

    // --- the central claim -------------------------------------------------

    public function test_an_allowed_application_can_carry_protections(): void
    {
        $this->actingAsCompanyAdmin();
        $policy = $this->appPolicy();

        $this->putJson("/api/policies/application/{$policy->id}/rules", [
            'rules' => [[
                'item' => 'whatsapp', 'label' => 'WhatsApp',
                'status' => 'ALLOWED', 'action' => 'LOG',
                'protections' => ['camera' => true],
            ]],
        ])->assertOk();

        $rule = PolicyRule::withoutGlobalScopes()->where('item', 'whatsapp')->first();

        $this->assertSame('ALLOWED', $rule->status);
        $this->assertSame(['camera'], $rule->protectionList());
        // The one that matters. An allowed application with protections must
        // never look like something to prevent from running.
        $this->assertFalse($rule->isEnforcing(), 'a protected application must still be allowed to run');
    }

    public function test_protections_can_be_set_independently_of_each_other(): void
    {
        $this->actingAsCompanyAdmin();
        $policy = $this->appPolicy();

        $this->putJson("/api/policies/application/{$policy->id}/rules", [
            'rules' => [[
                'item' => 'anydesk', 'label' => 'AnyDesk',
                'status' => 'ALLOWED', 'action' => 'LOG', 'confirmed' => true,
                'protections' => ['file' => true, 'image' => false, 'camera' => false],
            ]],
        ])->assertOk();

        $rule = PolicyRule::withoutGlobalScopes()->where('item', 'anydesk')->first();

        $this->assertSame(['file'], $rule->protectionList());
        $this->assertTrue($rule->hasProtection('file'));
        $this->assertFalse($rule->hasProtection('image'));
        $this->assertFalse($rule->hasProtection('camera'));
    }

    public function test_protections_are_dropped_when_switched_off(): void
    {
        $this->actingAsCompanyAdmin();
        $policy = $this->appPolicy();

        $body = fn (array $p) => ['rules' => [[
            'item' => 'whatsapp', 'label' => 'WhatsApp',
            'status' => 'ALLOWED', 'action' => 'LOG', 'protections' => $p,
        ]]];

        $this->putJson("/api/policies/application/{$policy->id}/rules", $body(['camera' => true]))->assertOk();
        $this->putJson("/api/policies/application/{$policy->id}/rules", $body(['camera' => false]))->assertOk();

        // A protection an admin removed must actually go. Left behind, it keeps
        // restricting an employee with nothing in the console to explain it.
        $this->assertNull(PolicyRule::withoutGlobalScopes()->where('item', 'whatsapp')->first()->protections);
    }

    // --- refusals ----------------------------------------------------------

    public function test_windows_itself_cannot_be_protected_either(): void
    {
        $this->actingAsCompanyAdmin();
        $policy = $this->appPolicy();

        $this->putJson("/api/policies/application/{$policy->id}/rules", [
            'rules' => [[
                'item' => 'explorer', 'label' => 'explorer',
                'status' => 'ALLOWED', 'action' => 'LOG',
                'protections' => ['camera' => true],
            ]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'RULE_REFUSED');

        // Denying explorer.exe the camera, or closing its file dialogs, is the
        // same class of damage as terminating it — by a quieter route.
        $this->assertNull(PolicyRule::withoutGlobalScopes()->where('item', 'explorer')->first());
    }

    public function test_a_protection_that_cannot_be_enforced_is_dropped_not_stored(): void
    {
        $this->actingAsCompanyAdmin();
        $policy = $this->appPolicy();

        // UltraViewer does not use the camera; the matrix says UNSUPPORTED.
        $res = $this->putJson("/api/policies/application/{$policy->id}/rules", [
            'rules' => [[
                'item' => 'ultraviewer', 'label' => 'UltraViewer',
                'status' => 'ALLOWED', 'action' => 'LOG',
                'protections' => ['camera' => true],
            ]],
        ])->assertOk();

        // The row saves; the protection does not, and the response says so.
        // Refusing the whole save used to mean a protection withdrawn from the
        // catalogue blocked every other edit on the screen.
        $res->assertJsonPath('unenforceable.0', 'ultraviewer → camera');
        $this->assertStringContainsString('cannot currently enforce', $res->json('warning'));

        // Storing it would put a control in the console, in the audit trail and
        // in front of a bank's auditor that no endpoint will ever act on.
        $rule = PolicyRule::withoutGlobalScopes()->where('item', 'ultraviewer')->first();
        $this->assertNotNull($rule);
        $this->assertNull($rule->protections);
    }

    public function test_an_unknown_protection_key_is_ignored(): void
    {
        $this->actingAsCompanyAdmin();
        $policy = $this->appPolicy();

        $this->putJson("/api/policies/application/{$policy->id}/rules", [
            'rules' => [[
                'item' => 'whatsapp', 'label' => 'WhatsApp',
                'status' => 'ALLOWED', 'action' => 'LOG',
                'protections' => ['camera' => true, 'microphone' => true],
            ]],
        ])->assertOk();

        $this->assertSame(['camera'], PolicyRule::withoutGlobalScopes()->where('item', 'whatsapp')->first()->protectionList());
    }

    // --- the capability matrix ---------------------------------------------

    public function test_the_capability_matrix_is_served_to_the_console(): void
    {
        $this->actingAsCompanyAdmin();

        $res = $this->getJson('/api/policies/protection-capabilities')->assertOk();

        $res->assertJsonPath('data.items.whatsapp.camera.status', 'SUPPORTED');
        $res->assertJsonPath('data.items.anydesk.file.status', 'SUPPORTED');
        $res->assertJsonPath('data.items.anydesk.image.status', 'UNSUPPORTED');
        $res->assertJsonPath('data.defaults.WEBSITE.file.status', 'UNSUPPORTED');

        // Every offered protection must name a mechanism. One without is a
        // checkbox with nothing behind it, which is the whole defect class.
        foreach ($res->json('data.items') as $item => $entry) {
            foreach ($entry as $protection => $spec) {
                if (in_array($spec['status'], ProtectionCapabilities::OFFERED, true)) {
                    $this->assertNotNull($spec['mechanism'], "{$item}.{$protection} is offered with no mechanism behind it");
                }
            }
        }
    }

    public function test_an_unlisted_application_falls_back_to_the_type_default(): void
    {
        $caps = app(ProtectionCapabilities::class);

        $typed = $caps->forItem('SomeInHouseTool.exe', 'APPLICATION');
        $this->assertSame('UNSUPPORTED', $typed['file']['status'],
            'a running desktop app cannot be stopped from sending files - never offered');
        $this->assertSame('UNVERIFIED', $typed['camera']['status']);
        // Offered only where a real mechanism exists: AnyDesk's own switch, and
        // the media-server block for messengers whose media path is separate.
        $this->assertTrue($caps->isOffered('anydesk', 'APPLICATION', 'file'));
        $this->assertTrue($caps->isOffered('whatsapp', 'APPLICATION', 'file'));
        $this->assertSame('media_server_block', $caps->forItem('whatsapp', 'APPLICATION')['file']['mechanism']);
        $this->assertFalse($caps->isOffered('telegram', 'APPLICATION', 'file'));
        $this->assertFalse($caps->isOffered('outlook', 'APPLICATION', 'file'));

        $this->assertTrue($caps->isOffered('whatsapp', 'APPLICATION', 'camera'));
        $this->assertFalse($caps->isOffered('ultraviewer', 'APPLICATION', 'camera'));
    }

    // --- what reaches the endpoint -----------------------------------------

    public function test_the_agent_bundle_carries_protections_as_a_plain_list(): void
    {
        $this->actingAsCompanyAdmin();
        $policy = $this->appPolicy();

        $this->putJson("/api/policies/application/{$policy->id}/rules", [
            'rules' => [[
                'item' => 'whatsapp', 'label' => 'WhatsApp',
                'status' => 'ALLOWED', 'action' => 'LOG',
                'protections' => ['camera' => true],
            ]],
        ])->assertOk();

        $employee = \App\Models\Employee::withoutGlobalScopes()->first();
        $bundle = app(\App\Services\PolicyResolver::class)->bundleForEmployee($employee);

        $rules = collect($bundle['policies']['application']['rules'] ?? []);
        $whatsapp = $rules->firstWhere('item', 'whatsapp');

        $this->assertNotNull($whatsapp, 'the agent never received the rule');
        $this->assertSame(['camera'], $whatsapp['protections']);
        // The agent decides whether to prevent anything from `enforced`. A
        // protected-but-allowed application must not arrive marked for closure.
        $this->assertFalse($whatsapp['enforced']);
    }

    public function test_website_protections_collapse_to_the_levers_a_browser_actually_has(): void
    {
        $this->actingAsCompanyAdmin();
        $site = WebsitePolicy::withoutGlobalScopes()->first();

        $this->putJson("/api/policies/website/{$site->id}/rules", [
            'rules' => [
                ['item' => 'mail.google.com', 'label' => 'Gmail', 'status' => 'ALLOWED', 'action' => 'LOG',
                 'protections' => ['file' => true]],
                ['item' => 'meet.google.com', 'label' => 'Meet', 'status' => 'ALLOWED', 'action' => 'LOG'],
            ],
        ])->assertOk();

        $rule = PolicyRule::withoutGlobalScopes()->where('item', 'mail.google.com')->first();
        // 22-Sep-2026: per-site upload block is UNSUPPORTED (it was browser-wide and took
        // the file picker away from every site). The tick is dropped; the row still saves.
        $this->assertSame([], $rule->protectionList());
        $this->assertFalse($rule->isEnforcing(), 'a protected site must still be reachable');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Same shape as PerRuleEnforcementTest's helper, and for the same reason:
     * actingAs() does not survive TestCase::call(), which forgets the auth
     * guards before every request, so the suite logs in and carries a bearer
     * token instead.
     */
    private function actingAsCompanyAdmin(): \App\Models\User
    {
        $user = \App\Models\User::query()
            ->whereRelation('role', 'slug', 'COMPANY_ADMIN')
            ->firstOrFail();

        $this->withToken($this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk()->json('token'));

        return $user;
    }

    /** "steam" and "steam.exe" on one screen are one rule; a tick on either survives. */
    public function test_duplicate_rows_that_normalise_to_one_item_keep_their_protections(): void
    {
        $this->actingAsCompanyAdmin();
        $policy = $this->appPolicy();

        $this->putJson("/api/policies/application/{$policy->id}/rules", ['rules' => [
            ['item' => 'steam', 'label' => 'steam', 'status' => 'BLOCKED', 'action' => 'WARN',
             'protections' => ['camera' => true]],
            ['item' => 'steam.exe', 'label' => 'steam.exe', 'status' => 'BLOCKED', 'action' => 'WARN',
             'protections' => ['camera' => false]],
        ]])->assertOk();

        $rows = PolicyRule::withoutGlobalScopes()->where('item', 'like', 'steam%')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['camera'], $rows->first()->protectionList());
    }
}
