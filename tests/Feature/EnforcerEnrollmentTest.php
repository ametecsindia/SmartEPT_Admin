<?php

namespace Tests\Feature;

use App\Models\ApplicationPolicy;
use App\Models\EnforcementMachine;
use App\Models\EnrollmentToken;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Machine enrolment for the Windows enforcement service.
 *
 * The service needs a credential of its own so it can sync at boot with nobody
 * signed in (decision 9). That means one unauthenticated endpoint, which is the
 * only one in the product that hands out a long-lived credential — so what
 * guards it matters more than usual.
 */
class EnforcerEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function companyId(): int
    {
        return (int) ApplicationPolicy::withoutGlobalScopes()->first()->company_id;
    }

    /** Log in for real and keep the bearer token: actingAs() does not survive
     *  TestCase::call(), which forgets the guards before every request. */
    private function signIn(User $user): User
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk()->json('token');
        $this->withToken($token);

        return $user;
    }

    private function admin(): User
    {
        return $this->signIn(
            User::query()->whereRelation('role', 'slug', 'COMPANY_ADMIN')->firstOrFail()
        );
    }

    private function mintSecret(array $overrides = []): string
    {
        $this->admin();
        $res = $this->postJson('/api/enforcer/enrollment-tokens', $overrides)->assertCreated();

        return $res->json('data.secret');
    }

    private function enrol(string $secret, array $overrides = [])
    {
        return $this->postJson('/api/enforcer/enroll', array_merge([
            'enrollment_token'  => $secret,
            'machine_id'        => 'MACHINE-A',
            'hostname'          => 'FLOOR-PC-01',
            'os_version'        => 'Windows 11 23H2',
            'enforcement_level' => 'FULL',
        ], $overrides));
    }

    // --- the happy path ----------------------------------------------------

    public function test_a_machine_enrols_and_receives_two_separate_secrets(): void
    {
        $secret = $this->mintSecret(['label' => 'Floor rollout']);

        $res = $this->enrol($secret)->assertCreated();

        $token = $res->json('device_token');
        $hmac  = $res->json('hmac_key');

        $this->assertNotEmpty($token, 'no device token issued — the machine cannot authenticate at boot');
        $this->assertNotEmpty($hmac, 'no integrity key issued — the local store could not tell a real policy from an edited one');

        // They must not be the same value. A leaked bearer token must not also
        // be a way to forge a policy the endpoint would accept.
        $this->assertNotSame($token, $hmac);
        // 32 bytes hex.
        $this->assertSame(64, strlen($hmac));
    }

    public function test_the_integrity_key_is_never_stored(): void
    {
        $secret = $this->mintSecret();
        $hmac = $this->enrol($secret)->json('hmac_key');

        $machine = EnforcementMachine::withoutGlobalScopes()->firstOrFail();

        // Only a short fingerprint, for support. The server never reads the
        // endpoint's policy store, so it has no business holding the key.
        $this->assertNotSame($hmac, $machine->integrity_key_fp);
        $this->assertSame(substr(hash('sha256', $hmac), 0, 16), $machine->integrity_key_fp);

        $row = json_encode($machine->toArray());
        $this->assertStringNotContainsString($hmac, $row, 'the integrity key was persisted');
    }

    public function test_a_freshly_enrolled_machine_is_not_reported_as_protected(): void
    {
        $secret = $this->mintSecret();
        $this->enrol($secret, ['enforcement_level' => 'FULL'])->assertCreated();

        $machine = EnforcementMachine::withoutGlobalScopes()->firstOrFail();

        // It claimed FULL capability. It has still proven nothing.
        $this->assertSame('UNKNOWN', $machine->enforcement_health);
        $this->assertFalse($machine->isProtected());
    }

    // --- what guards the unauthenticated endpoint --------------------------

    public function test_enrolment_without_a_token_is_refused(): void
    {
        $this->postJson('/api/enforcer/enroll', ['machine_id' => 'X'])->assertStatus(422);
    }

    public function test_an_invalid_token_is_refused(): void
    {
        $this->enrol('sept_enrol_not_a_real_token')->assertStatus(403);
        $this->assertSame(0, EnforcementMachine::withoutGlobalScopes()->count());
    }

    public function test_every_refusal_gives_the_same_message(): void
    {
        // Telling an unauthenticated caller whether a token exists, expired or
        // was spent is free reconnaissance.
        $unknown = $this->enrol('sept_enrol_nope')->json('reason');

        $secret = $this->mintSecret();
        $this->enrol($secret)->assertCreated();
        $spent = $this->enrol($secret, ['machine_id' => 'MACHINE-B'])->json('reason');

        $this->assertSame($unknown, $spent);
    }

    public function test_a_token_is_single_use_by_default(): void
    {
        $secret = $this->mintSecret();

        $this->enrol($secret)->assertCreated();
        $this->enrol($secret, ['machine_id' => 'MACHINE-B'])->assertStatus(403);

        $this->assertSame(1, EnforcementMachine::withoutGlobalScopes()->count());
    }

    public function test_a_multi_use_token_covers_a_rollout_then_stops(): void
    {
        $secret = $this->mintSecret(['max_uses' => 3, 'label' => 'Floor rollout']);

        foreach (['A', 'B', 'C'] as $m) {
            $this->enrol($secret, ['machine_id' => 'PC-' . $m])->assertCreated();
        }
        $this->enrol($secret, ['machine_id' => 'PC-D'])->assertStatus(403);

        $this->assertSame(3, EnforcementMachine::withoutGlobalScopes()->count());
    }

    public function test_an_expired_token_is_refused(): void
    {
        $secret = $this->mintSecret(['ttl_hours' => 1]);

        $this->travel(2)->hours();

        $this->enrol($secret)->assertStatus(403);
    }

    public function test_a_revoked_token_is_refused(): void
    {
        $this->admin();
        $res = $this->postJson('/api/enforcer/enrollment-tokens', [])->assertCreated();
        $secret = $res->json('data.secret');
        $id = $res->json('data.id');

        $this->postJson("/api/enforcer/enrollment-tokens/{$id}/revoke")->assertOk();

        $this->enrol($secret)->assertStatus(403);
    }

    public function test_the_secret_is_only_ever_returned_once(): void
    {
        $secret = $this->mintSecret();

        $list = $this->getJson('/api/enforcer/enrollment-tokens')->assertOk()->json('data');

        $this->assertNotEmpty($list);
        $this->assertStringNotContainsString($secret, json_encode($list), 'the enrolment secret was readable after minting');
        $this->assertArrayNotHasKey('secret', $list[0]);
    }

    public function test_only_the_hash_is_stored(): void
    {
        $secret = $this->mintSecret();
        $row = EnrollmentToken::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(hash('sha256', $secret), $row->token_hash);
        $this->assertStringNotContainsString($secret, json_encode($row->toArray()));
    }

    public function test_minting_requires_an_administrator(): void
    {
        $employee = User::query()->whereRelation('role', 'slug', 'EMPLOYEE')->first();
        if (! $employee) {
            $this->markTestSkipped('no employee user in the seed');
        }
        $this->signIn($employee);

        $this->postJson('/api/enforcer/enrollment-tokens', [])->assertStatus(403);
    }

    // --- website rules ------------------------------------------------------

    /**
     * A rule set to "Close / block it" on a WEBSITE must reach the endpoint.
     *
     * Until this existed the console offered the choice, saved it, and the
     * endpoint was never told — so a site rule was a setting that did nothing.
     * That is the same shape of defect as the one that cost the client.
     */
    public function test_website_rules_reach_the_endpoint_on_the_machine_baseline(): void
    {
        $this->admin();
        $token = $this->enrol($this->mintSecret())->assertCreated()->json('device_token');

        $company = \App\Models\ApplicationPolicy::withoutGlobalScopes()->firstOrFail()->company_id;
        $site = \App\Models\WebsitePolicy::withoutGlobalScopes()->where('company_id', $company)->firstOrFail();

        \App\Models\PolicyRule::withoutGlobalScopes()->updateOrCreate(
            ['policy_type' => 'WEBSITE', 'policy_id' => $site->id, 'item' => 'netflix'],
            ['company_id' => $company, 'label' => 'netflix', 'status' => 'BLOCKED', 'action' => 'BLOCK'],
        );
        // A warn-only rule prevents nothing and must NOT be sent.
        \App\Models\PolicyRule::withoutGlobalScopes()->updateOrCreate(
            ['policy_type' => 'WEBSITE', 'policy_id' => $site->id, 'item' => 'youtube'],
            ['company_id' => $company, 'label' => 'youtube', 'status' => 'BLOCKED', 'action' => 'WARN'],
        );

        // ENFORCE, not AUDIT (27-Aug-2026). The mode is a fixture detail here — this test is
        // about a blocked website reaching the endpoint — and ENFORCE is the state a client
        // installation is actually in now that there is no learning period. AUDIT would be
        // answered OFF on such an installation, so the endpoint would receive no spec at all
        // and this test would fail for a reason that has nothing to do with website rules.
        \App\Models\EnforcementState::forCompany($company)
            ->forceFill(['mode' => \App\Models\EnforcementState::ENFORCE])->save();

        $specs = $this->withToken($token)
            ->getJson('/api/enforcer/policy?device_uuid=MACHINE-A')
            ->assertOk()
            ->json('data');

        $machine = collect($specs)->firstWhere('scope', 'MACHINE');
        $this->assertNotNull($machine, 'no machine baseline was sent');
        $this->assertContains('netflix', $machine['sites'] ?? [], 'the blocked website never reached the endpoint');
        $this->assertNotContains('youtube', $machine['sites'] ?? [], 'a warn-only website was sent as if it were enforced');
    }

    // --- re-enrolment ------------------------------------------------------

    public function test_re_enrolling_the_same_machine_updates_it_rather_than_duplicating(): void
    {
        $first = $this->mintSecret();
        $this->enrol($first, ['hostname' => 'OLD-NAME'])->assertCreated();

        $second = $this->mintSecret();
        $this->enrol($second, ['hostname' => 'NEW-NAME'])->assertCreated();

        $this->assertSame(1, EnforcementMachine::withoutGlobalScopes()->count(), 'a rebuilt PC appeared twice in the console');
        $this->assertSame('NEW-NAME', EnforcementMachine::withoutGlobalScopes()->first()->hostname);
    }

    public function test_re_enrolling_invalidates_the_previous_credential(): void
    {
        $first = $this->mintSecret();
        $oldToken = $this->enrol($first)->json('device_token');

        $second = $this->mintSecret();
        $newToken = $this->enrol($second)->json('device_token');

        $this->assertNotSame($oldToken, $newToken);

        // A credential taken from a decommissioned machine must stop working
        // the moment that machine is rebuilt.
        $machine = EnforcementMachine::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(1, $machine->tokens()->count(), 'the old device token survived re-enrolment');
    }

    public function test_re_enrolment_issues_a_new_integrity_key(): void
    {
        $first = $this->mintSecret();
        $a = $this->enrol($first)->json('hmac_key');

        $second = $this->mintSecret();
        $b = $this->enrol($second)->json('hmac_key');

        // Correct and deliberate: the endpoint's previously cached policies
        // become unreadable and it refetches. A stale policy applied under a
        // new enrolment would be a policy nobody can account for.
        $this->assertNotSame($a, $b);
    }

    // --- revoking an endpoint ----------------------------------------------

    public function test_revoking_a_machine_stops_it_syncing_but_says_the_policy_stays(): void
    {
        $secret = $this->mintSecret();
        $this->enrol($secret)->assertCreated();

        $machine = EnforcementMachine::withoutGlobalScopes()->firstOrFail();
        $this->admin();

        $res = $this->postJson("/api/enforcer/machines/{$machine->id}/revoke")->assertOk();

        $this->assertSame(0, $machine->fresh()->tokens()->count());
        $this->assertNotNull($machine->fresh()->revoked_at);

        // Revoking a credential must not be a quiet way to disarm a PC — the
        // response has to say so, or an admin will assume it did.
        $this->assertStringContainsString('stays in force', $res->json('message'));
    }

    public function test_the_machines_list_separates_capability_from_achievement(): void
    {
        $secret = $this->mintSecret();
        $this->enrol($secret, ['enforcement_level' => 'FULL'])->assertCreated();

        $this->admin();
        $rows = $this->getJson('/api/enforcer/machines')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('FULL', $rows[0]['enforcement_level']);
        $this->assertSame('UNKNOWN', $rows[0]['enforcement_health']);
        // Capable is not the same as protected. Conflating them is what cost
        // the collection agency their bank.
        $this->assertFalse($rows[0]['protected']);
    }

    public function test_a_token_only_enrols_into_its_own_company(): void
    {
        $secret = $this->mintSecret();
        $this->enrol($secret)->assertCreated();

        $this->assertSame(
            $this->companyId(),
            (int) EnforcementMachine::withoutGlobalScopes()->firstOrFail()->company_id
        );
    }
}
