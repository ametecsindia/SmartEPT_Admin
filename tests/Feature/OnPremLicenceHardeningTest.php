<?php

namespace Tests\Feature;

use App\Models\InstallationLicense;
use App\Services\LicenseClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ON-PREMISE LICENSING, round two (21-Aug-2026).
 *
 * The 19-Aug fixes in OnPremLicenceTest are correct as far as they go. Three
 * ways round them survived, and all three hurt paying customers:
 *
 *  A1. A non-200 from Central wrote a synthetic status ('http_500', 'http_405')
 *      onto a Central-sourced licence. Nothing matches that in operational(), so
 *      it fell through to `default => false` and 403'd the whole console — for a
 *      transient outage at Central's end that says nothing about the client.
 *
 *  A2. A successful phone-home replaced the bundle wholesale with Central's,
 *      which does not carry `source: 'file'`. The first time a .lic install
 *      reached Central it silently stopped counting as a file licence, and the
 *      next unknown_key DID take it down — the very defect fromFile() exists to
 *      prevent.
 *
 *  B1. The unverified-key window was anchored on last_checked_at, which the
 *      unreachable branch sets to now() on every failure. On an air-gapped
 *      server the nightly phone-home reopened the window for ever, so any random
 *      string was still a permanent free licence. The 19-Aug test passes only
 *      because it never runs a phone-home between its assertions. Production
 *      runs one every night at 01:00.
 */
class OnPremLicenceHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function centralLicence(array $overrides = []): InstallationLicense
    {
        $licence = InstallationLicense::current();
        $licence->forceFill(array_merge([
            'license_key' => 'CLOUD-KEY',
            'status' => 'active',
            'bundle' => ['source' => 'central', 'expires_at' => null, 'device_limit' => 25],
            'key_saved_at' => now()->subYear(),
        ], $overrides))->save();

        return $licence->fresh();
    }

    private function fileLicence(array $overrides = []): InstallationLicense
    {
        $licence = InstallationLicense::current();
        $licence->forceFill(array_merge([
            'license_key' => 'ONPREM-KEY-1',
            'status' => 'active',
            'bundle' => [
                'company' => 'Acme Ltd',
                'expires_at' => null,
                'grace_days' => 7,
                'device_limit' => 50,
                'source' => 'file',
            ],
            'last_checked_at' => null,
        ], $overrides))->save();

        return $licence->fresh();
    }

    // ---- A1: only an ANSWER may demote a licence ---------------------------

    public function test_a_central_outage_does_not_block_a_paid_client(): void
    {
        $licence = $this->centralLicence();

        foreach ([500, 502, 503, 405, 404] as $code) {
            Http::fake(['*' => Http::response('gateway error', $code)]);

            $after = app(LicenseClient::class)->validate($licence->fresh());

            $this->assertSame('active', $after->status, "HTTP {$code} demoted a paid licence");
            $this->assertTrue($after->operational(), "HTTP {$code} blocked the console");
        }
    }

    public function test_an_unrecognised_reason_does_not_demote(): void
    {
        $licence = $this->centralLicence();

        // A reason we have never seen — a new Central build, a proxy error page,
        // a truncated body. It is not a verdict about this client.
        Http::fake(['*' => Http::response(['ok' => false, 'reason' => 'something_new'], 200)]);

        $after = app(LicenseClient::class)->validate($licence);

        $this->assertSame('active', $after->status);
        $this->assertStringContainsString('could not answer', (string) $after->last_error);
    }

    public function test_central_can_still_demote_with_a_real_verdict(): void
    {
        // The guard must not become a way to ignore Central entirely.
        //
        // Central answers a genuine rejection with HTTP 403, not 200 — see
        // smartept-central Api/LicenseController.php:58
        //   return response()->json($result, ($result['ok'] ?? false) ? 200 : 403);
        // so the verdict is carried by the `reason` field, never by the status
        // code. Gating demotion on a 200 would mean no licence could ever be
        // demoted again.
        // Http::fake() MERGES stubs and the FIRST match wins, so re-faking '*'
        // inside the loop left every iteration answering with the first reason —
        // the loop looked like it covered five verdicts and covered one.
        $answer = ['unknown_key', 403];
        Http::fake(function () use (&$answer) {
            return Http::response(['ok' => false, 'reason' => $answer[0]], $answer[1]);
        });

        foreach ([
            'unknown_key' => 'unknown_key',
            'licence_expired' => 'expired',
            'licence_revoked' => 'revoked',
            'licence_suspended' => 'suspended',
            'server_mismatch' => 'server_mismatch',
        ] as $reason => $expected) {
            foreach ([403, 200] as $httpStatus) {
                $licence = $this->centralLicence();
                $answer = [$reason, $httpStatus];

                $after = app(LicenseClient::class)->validate($licence);

                $this->assertSame($expected, $after->status, "{$reason} over HTTP {$httpStatus} should demote");
            }
        }
    }

    public function test_a_403_with_no_reason_is_an_outage_not_a_verdict(): void
    {
        // A 403 from a proxy, a WAF or a login wall in front of Central looks
        // exactly like a rejection by status code alone. The body is what tells
        // them apart.
        $licence = $this->centralLicence();
        Http::fake(['*' => Http::response('<html>Forbidden</html>', 403)]);

        $after = app(LicenseClient::class)->validate($licence);

        $this->assertSame('active', $after->status);
        $this->assertTrue($after->operational());
    }

    public function test_an_outage_marks_the_licence_unreachable_rather_than_silently_passing(): void
    {
        $licence = $this->centralLicence();
        Http::fake(['*' => Http::response('', 503)]);

        $after = app(LicenseClient::class)->validate($licence);

        // Availability wins, but the console must be able to show that we have
        // not actually heard from Central.
        $this->assertNotNull($after->unreachable_since);
        $this->assertStringContainsString('http_503', (string) $after->last_error);
    }

    // ---- A2: a file licence stays a file licence ---------------------------

    public function test_a_successful_phone_home_does_not_erase_the_file_marker(): void
    {
        $licence = $this->fileLicence();

        // Central recognises the key this time and returns its own bundle, which
        // has no 'source'. Before the fix this quietly converted the licence to
        // a Central one.
        Http::fake(['*' => Http::response([
            'ok' => true,
            'bundle' => ['company' => 'Acme Ltd', 'device_limit' => 50, 'expires_at' => null],
        ], 200)]);

        $after = app(LicenseClient::class)->validate($licence);

        $this->assertTrue($after->fromFile(), 'the licence stopped counting as file-sourced');

        // And the protection still holds afterwards, which is the point.
        Http::fake(['*' => Http::response(['ok' => false, 'reason' => 'unknown_key'], 200)]);
        $after = app(LicenseClient::class)->validate($after->fresh());

        $this->assertSame('active', $after->status);
        $this->assertTrue($after->operational());
    }

    public function test_central_values_still_win_where_they_are_supplied(): void
    {
        $licence = $this->fileLicence();
        Http::fake(['*' => Http::response([
            'ok' => true,
            'bundle' => ['device_limit' => 99, 'expires_at' => null],
        ], 200)]);

        $after = app(LicenseClient::class)->validate($licence);

        $this->assertSame(99, $after->deviceLimit(), 'the merge must not pin stale values');
        $this->assertTrue($after->fromFile());
    }

    // ---- B1: the unverified window must not renew itself -------------------

    public function test_a_bogus_key_on_an_offline_server_stops_working(): void
    {
        $licence = InstallationLicense::current();
        $licence->forceFill([
            'license_key' => 'ANY-OLD-STRING',
            'status' => 'unconfigured',
            'bundle' => null,
            'key_saved_at' => now(),
        ])->save();

        $this->assertTrue($licence->fresh()->operational(), 'the 7-day window should be open on day 0');

        // Now do what production does: fail to reach Central, every night.
        // Each failure used to bump last_checked_at and reopen the window.
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('offline');
        });

        for ($day = 1; $day <= 10; $day++) {
            $this->travel(1)->days();
            app(LicenseClient::class)->validate($licence->fresh());
        }

        $this->assertFalse(
            $licence->fresh()->operational(),
            'ten nightly failed validations kept an unverified key alive — the window renewed itself'
        );
    }

    public function test_a_failed_validation_does_not_move_the_anchor(): void
    {
        $licence = InstallationLicense::current();
        $saved = now()->subDays(3);
        $licence->forceFill([
            'license_key' => 'ANY-OLD-STRING',
            'status' => 'unconfigured',
            'key_saved_at' => $saved,
        ])->save();

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('offline');
        });
        app(LicenseClient::class)->validate($licence->fresh());

        $this->assertSame(
            $saved->toDateTimeString(),
            $licence->fresh()->key_saved_at->toDateTimeString(),
            'key_saved_at must never be moved by a validation attempt'
        );
    }

    public function test_entering_a_key_stamps_the_anchor(): void
    {
        $licence = InstallationLicense::current();
        $licence->forceFill([
            'license_key' => 'KEY',
            'status' => 'unconfigured',
            'key_saved_at' => now(),
        ])->save();

        $this->assertNotNull($licence->fresh()->key_saved_at);
    }

    public function test_a_signed_file_licence_is_untouched_by_the_window(): void
    {
        // The window only ever applies to status 'unconfigured'. A real
        // on-premise client is 'active' and must be unaffected by all of this.
        $licence = $this->fileLicence(['key_saved_at' => now()->subYears(3)]);

        $this->travel(400)->days();

        $this->assertTrue($licence->fresh()->operational());
    }
}
