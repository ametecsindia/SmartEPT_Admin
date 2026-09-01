<?php

namespace Tests\Feature;

use App\Models\InstallationLicense;
use App\Services\UpdateClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Check for Update" on the Licence screen (Ejaz, 1-Sep-2026).
 *
 * The rules that matter to a client sitting in front of this button:
 *  - it never explodes: no key, no network, a refusing Central — each produces
 *    a sentence the admin can act on, not a stack trace;
 *  - a package that does not match its hash is DELETED, never installed;
 *  - nothing installs itself by accident — download and install are separate,
 *    deliberate steps.
 */
class SelfUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['smartept.license_url' => 'https://central.test']);
        $this->dir = storage_path(UpdateClient::DIR);
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            is_file($f) and @unlink($f);
        }
    }

    private function client(): UpdateClient
    {
        return app(UpdateClient::class);
    }

    private function licensed(): void
    {
        InstallationLicense::current()->forceFill([
            'license_key' => 'SEPT-TEST-KEY', 'status' => 'active',
        ])->save();
    }

    public function test_a_server_with_no_licence_key_is_told_what_to_do(): void
    {
        Http::fake();

        $result = $this->client()->check();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('licence key', $result['message']);
        Http::assertNothingSent();
    }

    public function test_an_unreachable_central_never_breaks_the_screen(): void
    {
        $this->licensed();
        Http::fake(fn () => throw new \RuntimeException('cURL error 6: could not resolve host'));

        $result = $this->client()->check();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Could not reach SmartEPT Central', $result['message']);
    }

    public function test_no_update_available_is_reported_plainly(): void
    {
        $this->licensed();
        Http::fake(['central.test/api/v1/updates/check' => Http::response([
            'ok' => true, 'update_available' => false, 'message' => 'This server is already on the latest published version.',
        ])]);

        $result = $this->client()->check();

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['update_available']);
        $this->assertSame('idle', $this->client()->state()['phase']);
    }

    public function test_an_available_update_is_remembered_for_the_download_step(): void
    {
        $this->licensed();
        Http::fake(['central.test/api/v1/updates/check' => Http::response([
            'ok' => true, 'update_available' => true, 'version' => '1.6.0',
            'notes' => 'Biometric fixes', 'size_bytes' => 1234, 'package_hash' => str_repeat('a', 64),
            'download_url' => 'https://central.test/api/v1/updates/download/tok',
        ])]);

        $result = $this->client()->check();

        $this->assertTrue($result['update_available']);
        $state = $this->client()->state();
        $this->assertSame('available', $state['phase']);
        $this->assertSame('1.6.0', $state['available']['version']);
        $this->assertSame('Biometric fixes', $state['available']['notes']);
    }

    public function test_a_refusal_from_central_is_passed_through_in_plain_words(): void
    {
        $this->licensed();
        Http::fake(['central.test/api/v1/updates/check' => Http::response([
            'ok' => false, 'reason' => 'amc_expired',
            'message' => 'Your AMC has ended, so new versions are not included.',
        ], 403)]);

        $result = $this->client()->check();

        $this->assertFalse($result['ok']);
        $this->assertSame('amc_expired', $result['reason']);
        $this->assertStringContainsString('AMC', $result['message']);
    }

    public function test_a_package_that_fails_its_hash_is_deleted_not_installed(): void
    {
        $this->licensed();
        $this->offer(str_repeat('b', 64));                       // hash of nothing
        Http::fake(['central.test/api/v1/updates/download/*' => Http::response('corrupted-bytes')]);

        $result = $this->client()->download();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('integrity check', $result['message']);
        $this->assertSame([], glob($this->dir . '/*.zip'));
    }

    public function test_a_good_package_is_kept_and_waits_for_a_deliberate_install(): void
    {
        $this->licensed();
        $body = 'a-real-enough-zip';
        $this->offer(hash('sha256', $body));
        Http::fake(['central.test/api/v1/updates/download/*' => Http::response($body)]);

        $result = $this->client()->download();

        $this->assertTrue($result['ok']);
        $state = $this->client()->state();
        $this->assertSame('downloaded', $state['phase']);
        $this->assertFileExists($state['package']);
        // Downloading must never start an install on its own.
        $this->assertNotSame('installing', $state['phase']);
    }

    public function test_installing_without_a_downloaded_package_refuses(): void
    {
        $this->licensed();

        $result = $this->client()->install();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Check for the update', $result['message']);
    }

    public function test_the_current_version_comes_from_version_json(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $this->client()->currentVersion());
    }

    public function test_the_channel_follows_version_json_and_defaults_to_stable(): void
    {
        $this->licensed();
        Http::fake(['central.test/api/v1/updates/check' => Http::response(['ok' => true, 'update_available' => false])]);

        $this->client()->check();

        // A client install has no "channel" key at all — it must never ask for beta.
        Http::assertSent(fn ($r) => $r['channel'] === 'stable');
    }

    /** Put an offered update into the state file, as a successful check would. */
    private function offer(string $hash): void
    {
        $client = $this->client();
        $state = $client->state();
        $state['phase'] = 'available';
        $state['available'] = [
            'version' => '1.6.0', 'package_hash' => $hash, 'size_bytes' => 17,
            'download_url' => 'https://central.test/api/v1/updates/download/tok',
        ];
        $client->writeState($state);
    }
}
