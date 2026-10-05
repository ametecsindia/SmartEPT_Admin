<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointSecurityCommand;
use App\Models\EndpointSecurityEvent;
use App\Models\EndpointSecurityStatus;
use App\Models\EnforcementMachine;
use App\Models\InstallationLicense;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 03-Oct-2026 — SmartEPT Endpoint Security (Microsoft Defender monitoring & management).
 * Standard: nothing. Enforcer: monitoring, threats, compliance, quick scan, signature update.
 * Commander: + full scan, custom scan, policies, events, command history, advanced reports.
 */
class EndpointSecurityTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['endpoint_security.enabled' => true]);
        $this->companyId = (int) Company::query()->orderBy('id')->value('id');
    }

    private function plan(?string $tier, array $features = []): void
    {
        $l = InstallationLicense::current();
        $l->forceFill([
            'license_key' => 'SEPT-TEST-ESEC-0000-ABCD', 'status' => 'active', 'last_checked_at' => now(),
            'bundle' => array_filter(['kind' => 'subscription', 'tier' => $tier, 'status' => 'active', 'features' => $features,
                'expires_at' => now()->addYear()->toDateString(), 'grace_days' => 7, 'device_limit' => 100], fn ($v) => $v !== null),
        ])->save();
    }

    private function enforcer(): void
    {
        $this->plan('enforcer', ['enforcement' => true, 'live_view' => false]);
    }

    private function commander(): void
    {
        $this->plan('commander', ['enforcement' => true, 'live_view' => true]);
    }

    private ?string $adminToken = null;

    /** One real login per test (the login route is throttled), then reuse the bearer token. */
    private function admin(): void
    {
        if (! $this->adminToken) {
            $u = User::query()->whereRelation('role', 'slug', 'COMPANY_ADMIN')->firstOrFail();
            $this->adminToken = $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'password'])->assertOk()->json('token');
        }
        $this->withToken($this->adminToken);
    }

    private function machine(string $host = 'COLLECTION-PC-024'): array
    {
        $m = EnforcementMachine::withoutGlobalScopes()->create([
            'company_id' => $this->companyId, 'machine_id' => 'M-' . $host, 'hostname' => $host,
            'os_version' => 'Windows 11 23H2', 'enrolled_at' => now()->subDay(), 'last_seen_at' => now(),
        ]);

        return [$m, $m->createToken('enforcer:' . $host, ['enforcer'])->plainTextToken];
    }

    private function health(array $over = []): array
    {
        return array_replace_recursive([
            'provider' => 'Microsoft Defender Antivirus', 'providerType' => 'defender',
            'installedProviders' => [['name' => 'Windows Defender', 'active' => true, 'upToDate' => true, 'defender' => true]],
            'antivirusInstalled' => true, 'antivirusEnabled' => true,
            'realTimeProtectionEnabled' => true, 'behaviorMonitoringEnabled' => true, 'ioavProtectionEnabled' => true,
            'runningMode' => 'Normal', 'signatureVersion' => '1.417.123.0',
            'signatureLastUpdated' => now()->subHours(3)->toIso8601String(), 'signatureOutdated' => false,
            'lastQuickScan' => now()->subDay()->toIso8601String(),
            'firewall' => ['domain' => true, 'private' => true, 'public' => true],
            'checkedAt' => now()->toIso8601String(),
        ], $over);
    }

    private function sync(string $token, array $body = [])
    {
        return $this->withToken($token)->postJson('/api/enforcer/security/sync',
            array_merge(['capabilities' => ['endpoint_security_v1'], 'version' => '0.22.0'], $body));
    }

    // --- plans ----------------------------------------------------------------

    public function test_standard_has_no_endpoint_security(): void
    {
        $this->plan('standard', ['enforcement' => false]);
        $this->admin();
        $this->getJson('/api/endpoint-security/access')->assertOk()->assertJsonPath('data.level', 'none')->assertJsonPath('data.can_view', false);
        $this->getJson('/api/endpoint-security/overview')->assertStatus(403)->assertJsonPath('error.code', 'FEATURE_NOT_AVAILABLE')
            ->assertJsonPath('error.message', 'Endpoint Security is available in SmartEPT Enforcer and Commander.');
        [$m, $tok] = $this->machine();
        $this->admin();
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/quick-scan")->assertStatus(403);
        $this->getJson('/api/endpoint-security/reports/summary')->assertStatus(403);

        // The endpoint is told to idle and nothing is stored.
        $this->sync($tok, ['health' => $this->health()])->assertOk()->assertJsonPath('enabled', false);
        $this->assertSame(0, EndpointSecurityStatus::withoutGlobalScopes()->count());
    }

    public function test_switched_off_globally_means_nothing_anywhere(): void
    {
        $this->commander();
        config(['endpoint_security.enabled' => false]);
        $this->admin();
        $this->getJson('/api/endpoint-security/overview')->assertStatus(403);
        [, $tok] = $this->machine();
        $this->sync($tok, ['health' => $this->health()])->assertJsonPath('enabled', false);
    }

    public function test_enforcer_capabilities(): void
    {
        $this->enforcer();
        [$m, $tok] = $this->machine();
        $this->sync($tok, ['health' => $this->health()])->assertOk()->assertJsonPath('enabled', true);
        $this->admin();

        $this->getJson('/api/endpoint-security/access')->assertJsonPath('data.level', 'basic')->assertJsonPath('data.can_edit_policy', false);
        $this->getJson('/api/endpoint-security/overview')->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('summary.protected', 1);
        $this->getJson("/api/endpoint-security/devices/{$m->id}/threats")->assertOk();
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/quick-scan")->assertCreated();
        EndpointSecurityCommand::query()->update(['status' => 'completed']);
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/update-signatures")->assertCreated();
        EndpointSecurityCommand::query()->update(['status' => 'completed']);
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/refresh")->assertCreated();

        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/full-scan")->assertStatus(403)
            ->assertJsonPath('error.code', 'FEATURE_NOT_AVAILABLE');
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/custom-scan", ['path' => 'C:\\Users\\Public'])->assertStatus(403);
        $this->putJson('/api/endpoint-security/policy', [])->assertStatus(403);
        $this->getJson('/api/endpoint-security/commands')->assertStatus(403);
        $this->getJson("/api/endpoint-security/devices/{$m->id}/events")->assertStatus(403);
        $this->getJson('/api/endpoint-security/reports/compliance')->assertOk();
        $this->getJson('/api/endpoint-security/reports/actions')->assertStatus(403);
    }

    public function test_commander_capabilities_and_custom_path_validation(): void
    {
        $this->commander();
        [$m, $tok] = $this->machine();
        $this->sync($tok, ['health' => $this->health()]);
        $this->admin();

        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/full-scan")->assertCreated();
        // One Defender action at a time.
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/quick-scan")->assertStatus(409)
            ->assertJsonPath('error.code', 'SCAN_ALREADY_RUNNING');
        EndpointSecurityCommand::query()->update(['status' => 'completed']);

        foreach (['C:\\x"; Remove-Item C:\\ -Recurse #', '\\\\server\\share', 'C:\\a\\..\\Windows', 'C:\\$(calc)', 'https://evil', 'C:\\x;calc'] as $bad) {
            $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/custom-scan", ['path' => $bad])->assertStatus(422)
                ->assertJsonPath('error.code', 'INVALID_SCAN_PATH');
        }
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/custom-scan", ['path' => 'c:/Users/Public/Downloads/'])
            ->assertCreated()->assertJsonPath('data.parameters.path', 'C:\\Users\\Public\\Downloads');

        $this->putJson('/api/endpoint-security/policy', [
            'requireAntivirus' => true, 'requireRealtimeProtection' => true, 'maximumSignatureAgeHours' => 1,
            'requireFirewall' => true, 'allowThirdPartyAntivirus' => true, 'pathRedaction' => 'FILENAME_ONLY',
        ])->assertOk();
        // Signatures 3h old against a 1h policy: re-evaluated on save.
        $this->assertSame('ACTION_REQUIRED', EndpointSecurityStatus::withoutGlobalScopes()->first()->compliance);
        $this->getJson('/api/endpoint-security/commands')->assertOk();
        $this->getJson("/api/endpoint-security/devices/{$m->id}/events")->assertOk();
        $this->getJson('/api/endpoint-security/reports/actions')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'endpoint_security.custom_scan']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'endpoint_security.policy_update']);
        // The endpoint receives the new redaction policy.
        $this->sync($tok)->assertJsonPath('redaction', 'FILENAME_ONLY');
    }

    // --- command round trip ------------------------------------------------------

    public function test_command_lifecycle_expiry_and_downgrade(): void
    {
        $this->commander();
        [$m, $tok] = $this->machine();
        $this->sync($tok, ['health' => $this->health()]);
        $this->admin();
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/quick-scan")->assertCreated();

        $cmd = $this->sync($tok)->assertJsonCount(1, 'commands')->json('commands.0');
        $this->assertSame('AV_QUICK_SCAN', $cmd['type']);
        $this->assertArrayNotHasKey('path', $cmd);
        $row = EndpointSecurityCommand::withoutGlobalScopes()->where('uuid', $cmd['id'])->first();
        $this->assertSame('received', $row->status);

        $this->sync($tok, ['results' => [['commandId' => $cmd['id'], 'status' => 'running', 'startedAt' => now()->toIso8601String()]]])
            ->assertJsonCount(0, 'commands');
        $this->sync($tok, ['results' => [['commandId' => $cmd['id'], 'status' => 'completed', 'completedAt' => now()->toIso8601String()]]]);
        // A late, older report never moves it backwards.
        $this->sync($tok, ['results' => [['commandId' => $cmd['id'], 'status' => 'running']]]);
        $this->assertSame('completed', $row->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'endpoint_security.command_completed']);

        // Failure codes become friendly text, never raw endpoint output.
        $this->admin();
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/update-signatures")->assertCreated();
        $c2 = $this->sync($tok)->json('commands.0.id');
        $this->sync($tok, ['results' => [['commandId' => $c2, 'status' => 'failed', 'errorCode' => 'SIGNATURE_UPDATE_FAILED', 'message' => '<script>']]]);
        $this->assertStringContainsString('could not update', EndpointSecurityCommand::withoutGlobalScopes()->where('uuid', $c2)->value('error_message'));

        // Expired before pickup.
        $this->admin();
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/full-scan")->assertCreated();
        EndpointSecurityCommand::withoutGlobalScopes()->where('status', 'queued')->update(['expires_at' => now()->subMinute()]);
        $this->sync($tok)->assertJsonCount(0, 'commands');
        $this->assertSame(1, EndpointSecurityCommand::withoutGlobalScopes()->where('status', 'expired')->count());

        // Downgraded to Enforcer while a full scan was queued: cancelled, never delivered.
        $this->admin();
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/full-scan")->assertCreated();
        $this->enforcer();
        $this->sync($tok)->assertJsonCount(0, 'commands');
        $this->assertSame(1, EndpointSecurityCommand::withoutGlobalScopes()->where('status', 'cancelled')->count());
    }

    public function test_unauthorized_and_revoked_machines(): void
    {
        $this->commander();
        [$m, $tok] = $this->machine();
        $this->postJson('/api/enforcer/security/sync', [])->assertStatus(401);
        $m->forceFill(['revoked_at' => now()])->save();
        $this->sync($tok)->assertStatus(403);

        // An employee/admin token is not an enforcer credential.
        $this->admin();
        $this->postJson('/api/enforcer/security/sync', [])->assertStatus(403);
    }

    // --- compliance, threats, alerts ----------------------------------------------

    public function test_threats_compliance_and_state_based_alerts(): void
    {
        $this->enforcer();
        [$m, $tok] = $this->machine();
        $this->sync($tok, ['health' => $this->health()]);
        $status = fn () => EndpointSecurityStatus::withoutGlobalScopes()->first();
        $this->assertSame('COMPLIANT', $status()->compliance);

        $threat = ['threatId' => '2147519003', 'threatName' => 'Trojan:Win32/Example', 'status' => 'detected', 'active' => true,
            'initialDetectionTime' => now()->toIso8601String(), 'resources' => ['C:\\Users\\***\\Downloads\\x.exe']];
        $this->sync($tok, ['health' => $this->health(), 'threats' => [$threat],
            'events' => [['recordId' => 101, 'eventId' => 1116, 'kind' => 'threat_detected', 'time' => now()->toIso8601String()]]]);
        $this->assertSame('NON_COMPLIANT', $status()->compliance);
        $this->assertContains('SECURITY_THREAT_UNRESOLVED', $status()->compliance_issues);

        // Still unhealthy: no duplicate alert.
        $this->sync($tok, ['health' => $this->health(), 'threats' => [$threat]]);
        $opened = fn ($code) => EndpointSecurityEvent::withoutGlobalScopes()->where('kind', 'alert_opened')->where('detail', $code)->count();
        $this->assertSame(1, $opened('SECURITY_THREAT_UNRESOLVED'));
        $this->assertSame(1, $opened('SECURITY_THREAT_DETECTED'));

        // Quarantined: resolved.
        $this->sync($tok, ['health' => $this->health(), 'threats' => [array_merge($threat, ['status' => 'quarantined', 'active' => false])]]);
        $this->assertSame('COMPLIANT', $status()->compliance);
        $this->assertSame(1, EndpointSecurityEvent::withoutGlobalScopes()->where('kind', 'alert_resolved')->count());

        // Events are idempotent on (machine, record id).
        $this->sync($tok, ['events' => [['recordId' => 101, 'eventId' => 1116, 'kind' => 'threat_detected', 'time' => now()->toIso8601String()]]]);
        $this->assertSame(1, EndpointSecurityEvent::withoutGlobalScopes()->where('record_id', 101)->count());

        // Real-time off = critical; firewall off = attention only.
        $this->sync($tok, ['health' => $this->health(['firewall' => ['public' => false]])]);
        $this->assertSame('ACTION_REQUIRED', $status()->compliance);
        $this->sync($tok, ['health' => $this->health(['realTimeProtectionEnabled' => false])]);
        $this->assertSame('NON_COMPLIANT', $status()->compliance);
    }

    public function test_third_party_antivirus_is_compliant_and_monitoring_only(): void
    {
        $this->commander();
        [$m, $tok] = $this->machine();
        $this->sync($tok, ['health' => $this->health([
            'provider' => 'Sophos Endpoint', 'providerType' => 'third_party', 'realTimeProtectionEnabled' => null,
        ])]);
        $this->assertSame('COMPLIANT', EndpointSecurityStatus::withoutGlobalScopes()->first()->compliance);
        $this->admin();
        $this->getJson("/api/endpoint-security/devices/{$m->id}")->assertJsonPath('data.monitoring_only', true);
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/quick-scan")->assertStatus(409)
            ->assertJsonPath('error.code', 'THIRD_PARTY_AV_ACTIVE');
    }

    public function test_unknown_is_never_compliant(): void
    {
        $this->enforcer();
        [$m, $tok] = $this->machine();
        $this->sync($tok, ['health' => ['providerType' => 'unknown', 'errors' => ['SECURITY_PROVIDER_UNAVAILABLE', 'rm -rf']]]);
        $s = EndpointSecurityStatus::withoutGlobalScopes()->first();
        $this->assertSame('UNKNOWN', $s->compliance);
        $this->assertSame(['SECURITY_PROVIDER_UNAVAILABLE'], $s->errors);

        // Stale report reads as UNKNOWN even if it was compliant.
        $this->sync($tok, ['health' => $this->health()]);
        EndpointSecurityStatus::withoutGlobalScopes()->update(['received_at' => now()->subHours(3)]);
        $this->admin();
        $this->getJson('/api/endpoint-security/overview')->assertJsonPath('data.0.compliance', 'UNKNOWN');
    }

    public function test_older_agent_needs_upgrade_and_breaks_nothing(): void
    {
        $this->enforcer();
        [$m] = $this->machine('OLD-PC');
        $this->admin();
        $this->getJson('/api/endpoint-security/overview')->assertOk()
            ->assertJsonPath('summary.upgrade_required', 1)->assertJsonPath('data.0.compliance', 'UNKNOWN');
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/quick-scan")->assertStatus(409)
            ->assertJsonPath('error.code', 'AGENT_UPGRADE_REQUIRED');
    }

    public function test_scan_report_shows_outcome_threats_and_events(): void
    {
        $this->enforcer();
        [$m, $tok] = $this->machine();
        $this->sync($tok, ['health' => $this->health()]);
        $this->admin();
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/quick-scan")->assertCreated();
        $cmd = $this->sync($tok)->json('commands.0.id');
        $id = EndpointSecurityCommand::withoutGlobalScopes()->where('uuid', $cmd)->value('id');
        $start = now()->subMinutes(3);
        $this->sync($tok, ['results' => [['commandId' => $cmd, 'status' => 'running', 'startedAt' => $start->toIso8601String()]]]);

        $this->admin();
        $this->getJson("/api/endpoint-security/commands/$id/report")->assertOk()->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.outcome', 'Still running on the PC — open this report again when it completes.');

        $this->sync($tok, [
            'results' => [['commandId' => $cmd, 'status' => 'completed', 'startedAt' => $start->toIso8601String(), 'completedAt' => now()->toIso8601String()]],
            'threats' => [['threatId' => '2147519003', 'threatName' => 'Virus:DOS/EICAR_Test_File', 'severity' => 5, 'status' => 'quarantined',
                'active' => false, 'actionSuccess' => true, 'initialDetectionTime' => now()->subMinute()->utc()->toIso8601String(), 'resources' => ['C:\\Users\\***\\eicar.com']]],
            'events' => [['recordId' => 900, 'eventId' => 1001, 'kind' => 'scan_completed', 'time' => now()->utc()->toIso8601String()]],
        ]);
        $this->admin();
        $r = $this->getJson("/api/endpoint-security/commands/$id/report")->assertOk();
        $r->assertJsonPath('data.outcome', 'Completed — 1 threat(s) found by Microsoft Defender.')
            ->assertJsonPath('data.threats.0.name', 'Virus:DOS/EICAR_Test_File')
            ->assertJsonPath('data.events.0.kind', 'scan_completed')
            ->assertJsonPath('data.machine_id', $m->id);
        $this->assertGreaterThanOrEqual(170, $r->json('data.duration_seconds'));
    }

    public function test_company_compliance_report_lists_every_pc_and_checkpoint(): void
    {
        $this->enforcer();
        [$m, $tok] = $this->machine('BRANCH-PC-01');
        $m->forceFill(['device_uuid' => 'CR-1'])->save();
        $this->sync($tok, ['health' => $this->health(['posture' => [
            'osName' => 'Microsoft Windows 11 Pro', 'osBuild' => '22631', 'osRelease' => '23H2',
            'lastPatch' => now()->subDays(10)->toDateString(), 'lastPatchId' => 'KB5050', 'bitlocker' => 'Off',
            'admins' => ['BRANCH-PC-01\\Administrator', 'BRANCH-PC-01\\ravi'], 'lockSecs' => '600', 'macrosBlocked' => 'True',
            'evil' => '<script>', // unknown key: dropped
        ]])])->assertOk();
        [, $tok2] = $this->machine('BRANCH-PC-02'); // older service: no posture, never "pass" for what it did not report
        $this->sync($tok2, ['health' => $this->health(['realTimeProtectionEnabled' => false])])->assertOk();
        \Illuminate\Support\Facades\DB::table('pc_audit_events')->insert(['company_id' => $this->companyId, 'device_uuid' => 'CR-1',
            'kind' => 'usb_storage', 'occurred_at' => now(), 'title' => 'SanDisk', 'outcome' => 'Blocked by SmartEPT', 'fingerprint' => str_repeat('a', 40)]);
        $this->assertArrayNotHasKey('evil', EndpointSecurityStatus::withoutGlobalScopes()->where('enforcement_machine_id', $m->id)->first()->posture);

        $this->admin();
        $d = $this->getJson('/api/endpoint-security/compliance-report?from=' . now()->subDays(30)->toDateString() . '&to=' . now()->toDateString())->assertOk()->json('data');
        $this->assertSame(2, $d['totals']['pcs']);
        $this->assertSame(1, $d['totals']['usb_blocked']);
        $pcs = collect($d['pcs'])->keyBy('device');
        $one = $pcs['BRANCH-PC-01']['checks'];
        $this->assertSame('fail', $one['bitlocker']['s']);
        $this->assertSame('fail', $one['os']['s']); // 23H2 Home/Pro ended 11-Nov-2025
        $this->assertSame('pass', $one['patch']['s']);
        $this->assertSame('pass', $one['lock']['s']);
        $this->assertSame('pass', $one['macros']['s']);
        $this->assertSame('2: Administrator, ravi', $one['admins']['t']);
        $this->assertSame(1, $pcs['BRANCH-PC-01']['period']['usb']);
        $two = $pcs['BRANCH-PC-02']['checks'];
        $this->assertSame('fail', $two['realtime']['s']);
        $this->assertSame('unknown', $two['bitlocker']['s']);
        $this->assertSame('unknown', $two['patch']['s']);
        $this->assertSame(0, $d['totals']['fully_compliant']);

        $this->getJson('/api/endpoint-security/compliance-report?from=2025-01-01&to=2026-10-04')->assertStatus(422);
        $this->plan('standard');
        $this->getJson('/api/endpoint-security/compliance-report?from=2026-10-01&to=2026-10-04')->assertStatus(403);
    }

    public function test_a_just_enrolled_pc_is_waiting_not_upgrade_required(): void
    {
        $this->enforcer();
        // Same clock for the write and the request (production boots on the company's timezone).
        Company::withoutGlobalScopes()->whereKey($this->companyId)->update(['timezone' => config('app.timezone')]);
        [$m] = $this->machine('NEW-PC');
        $m->forceFill(['enrolled_at' => now()->subMinute()])->save();
        $this->admin();
        $this->getJson('/api/endpoint-security/overview')->assertOk()
            ->assertJsonPath('summary.upgrade_required', 0)->assertJsonPath('data.0.waiting', true);
    }

    public function test_legacy_licence_without_tier_derives_from_existing_features(): void
    {
        $this->plan(null, ['enforcement' => true]);
        $this->admin();
        $this->getJson('/api/endpoint-security/access')->assertJsonPath('data.level', 'basic');
        $this->plan(null, ['enforcement' => true, 'live_view' => true]);
        $this->getJson('/api/endpoint-security/access')->assertJsonPath('data.level', 'advanced');
        $this->plan('commander', ['enforcement' => true, 'live_view' => true, 'endpoint_security' => false]);
        $this->getJson('/api/endpoint-security/access')->assertJsonPath('data.level', 'none');
    }

    // --- regression: existing enforcement contract unchanged ---------------------

    public function test_enforcer_heartbeat_contract_is_unchanged(): void
    {
        $this->commander();
        [, $tok] = $this->machine();
        $res = $this->withToken($tok)->postJson('/api/enforcer/heartbeat', ['enforcer_version' => '0.22.0', 'device_uuid' => 'DEV-TEST'])->assertOk();
        $this->assertSame(['ok', 'server_time', 'enforcement'], array_keys($res->json()));
        $this->assertSame(['mode', 'latest_policy_version', 'resync_required', 'kill_switch', 'employee_id', 'device_only'], array_keys($res->json('enforcement')));
    }

    public function test_role_matrix_view_and_edit_ticks(): void
    {
        $this->enforcer();
        [$m, $tok] = $this->machine();
        $this->sync($tok, ['health' => $this->health()]);
        $admin = User::query()->whereRelation('role', 'slug', 'COMPANY_ADMIN')->firstOrFail();
        $role = \App\Models\Role::create(['company_id' => $admin->company_id, 'slug' => 'SEC_T', 'name' => 'Sec', 'base_slug' => 'COMPLIANCE_OFFICER', 'is_system' => false]);
        $perm = fn ($slug) => \App\Models\Permission::where('slug', $slug)->value('id');
        $role->permissions()->attach(\App\Models\Permission::firstOrCreate(['slug' => 'card.dashboard.workforce_status.view'], ['name' => 'd', 'group' => 'g'])->id);
        $u = User::create(['name' => 'Sec', 'email' => 'sec-t@x.io', 'password' => 'password', 'company_id' => $admin->company_id, 'role_id' => $role->id, 'status' => 'ACTIVE']);
        $this->withToken($u->createToken('t')->plainTextToken);

        $this->getJson('/api/endpoint-security/overview')->assertStatus(403);
        $this->getJson('/api/endpoint-security/access')->assertJsonPath('data.can_view', false);

        $role->permissions()->attach($perm('card.endsec.security_overview.view'));
        $this->getJson('/api/endpoint-security/overview')->assertOk();
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/quick-scan")->assertStatus(403);

        $role->permissions()->attach($perm('card.endsec.security_overview.edit'));
        $this->postJson("/api/endpoint-security/devices/{$m->id}/actions/quick-scan")->assertCreated();
        $this->getJson('/api/endpoint-security/access')->assertJsonPath('data.can_act', true)->assertJsonPath('data.can_view_policy', false);
    }

    public function test_migration_down_removes_only_what_it_added(): void
    {
        $before = \App\Models\Permission::count() - 4;
        $mig = require database_path('migrations/2026_10_03_000100_create_endpoint_security_tables.php');
        $mig->down();
        foreach (['endpoint_security_status', 'endpoint_security_threats', 'endpoint_security_events', 'endpoint_security_commands', 'endpoint_security_policies'] as $t) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable($t));
        }
        $this->assertSame($before, \App\Models\Permission::count());
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('enforcement_machines'));
        $mig->up(); // idempotent re-apply
        $mig->up();
        $this->assertSame($before + 4, \App\Models\Permission::count());
    }

    public function test_a_half_finished_earlier_run_is_completed_by_migrating_again(): void
    {
        // 03-Oct-2026 on MySQL: the threats table was created, then its index failed (name > 64 chars).
        $mig = require database_path('migrations/2026_10_03_000100_create_endpoint_security_tables.php');
        \Illuminate\Support\Facades\Schema::table('endpoint_security_threats', fn ($t) => $t->dropUnique('es_threats_machine_threat_uq'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasIndex('endpoint_security_threats', 'es_threats_machine_threat_uq'));
        $mig->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('endpoint_security_threats', 'es_threats_machine_threat_uq'));
        foreach (\Illuminate\Support\Facades\Schema::getIndexes('endpoint_security_events') as $ix) {
            $this->assertLessThanOrEqual(64, strlen($ix['name']));
        }
    }

    public function test_a_renamed_pc_shows_its_current_name_not_the_enrolment_one(): void
    {
        // 03-Oct-2026, Ejaz: the console said DESKTOP-KN8IQRK while Devices said AH.
        $this->commander();
        [$m, $tok] = $this->machine('DESKTOP-KN8IQRK');
        $emp = \App\Models\Employee::withoutGlobalScopes()->where('company_id', $this->companyId)->firstOrFail();
        $mk = fn ($uuid, $name, $ago) => \App\Models\EmployeeDevice::withoutGlobalScopes()->forceCreate([
            'company_id' => $this->companyId, 'employee_id' => $emp->id, 'device_uuid' => $uuid,
            'computer_name' => $name, 'os_version' => 'Windows 11', 'last_heartbeat_at' => now()->subMinutes($ago)]);
        $mk('DEV-OLD', 'DESKTOP-KN8IQRK', 60 * 24 * 5);   // the row the service enrolled with
        $mk('DEV-NEW', 'AH', 1);                          // reinstalled + renamed: what Devices shows
        // A dead record whose own device still exists is a DIFFERENT PC (LRCR8LO case): own name.
        $m->forceFill(['device_uuid' => 'DEV-OLD', 'signed_in_employee_id' => $emp->id, 'last_seen_at' => now()->subDays(25)])->save();
        $this->admin();
        $this->getJson('/api/endpoint-security/overview')->assertJsonPath('data.0.device', 'DESKTOP-KN8IQRK')
            ->assertJsonPath('data.0.service_offline', true)->assertJsonPath('summary.service_offline', 1);
        // Its own device row gone (PC renamed/reinstalled, KN8IQRK -> AH): the current name, still offline.
        \App\Models\EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'DEV-OLD')->delete();
        $this->admin();
        $this->getJson('/api/endpoint-security/overview')->assertJsonPath('data.0.device', 'AH')
            ->assertJsonPath('data.0.service_offline', true);
        // A newer phone of the same employee never names the PC.
        \App\Models\EmployeeDevice::withoutGlobalScopes()->forceCreate([
            'company_id' => $this->companyId, 'employee_id' => $emp->id, 'device_uuid' => 'DEV-PHONE',
            'computer_name' => 'samsung SM-M146B', 'os_version' => 'Android 14', 'last_heartbeat_at' => now()]);
        $this->admin();
        $this->getJson('/api/endpoint-security/overview')->assertJsonPath('data.0.device', 'AH');
        \App\Models\EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'DEV-PHONE')->delete();
        // A live one takes the current device name.
        $m->forceFill(['last_seen_at' => now()])->save();

        $this->admin();
        $this->getJson('/api/endpoint-security/overview')->assertJsonPath('data.0.device', 'AH')
            ->assertJsonPath('data.0.employee.id', $emp->id);

        // Once the service reports, its own hostname wins.
        $this->sync($tok, ['hostname' => 'AH', 'health' => $this->health()]);
        $this->admin();
        $this->getJson('/api/endpoint-security/overview')->assertJsonPath('data.0.device', 'AH');
        $this->sync($tok, ['hostname' => 'bad name; drop', 'health' => $this->health()]);
        $this->assertSame('AH', EndpointSecurityStatus::withoutGlobalScopes()->value('hostname'));
    }

    public function test_utc_times_from_the_endpoint_are_stored_on_the_company_clock(): void
    {
        $this->enforcer();
        [, $tok] = $this->machine();
        $z = '2026-10-03T11:34:54Z';
        $this->sync($tok, ['health' => $this->health(['checkedAt' => $z, 'lastQuickScan' => $z]),
            'threats' => [['threatId' => '1', 'status' => 'quarantined', 'active' => false, 'initialDetectionTime' => $z]]]);
        $tz = Company::withoutGlobalScopes()->whereKey($this->companyId)->value('timezone') ?: config('app.timezone');
        $want = \Illuminate\Support\Carbon::parse($z)->setTimezone($tz)->format('Y-m-d H:i:s'); // the company clock
        $this->assertSame($want, \Illuminate\Support\Facades\DB::table('endpoint_security_status')->value('last_quick_scan_at'));
        $this->assertSame($want, \Illuminate\Support\Facades\DB::table('endpoint_security_threats')->value('detected_at'));
    }
}
