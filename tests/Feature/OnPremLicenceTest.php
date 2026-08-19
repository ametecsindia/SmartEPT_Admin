<?php

namespace Tests\Feature;

use App\Models\InstallationLicense;
use App\Services\LicenseClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ON-PREMISE LICENSING — the two defects that hurt PAYING customers (Ejaz, 19-Aug-2026).
 *
 * 1. A perpetual, fully paid, offline client could be bricked by a *successful* call
 *    home: uploading a .lic sets license_key, so the install counted as configured and
 *    phoned smartept.com daily; if Central did not recognise the key it overwrote the
 *    good .lic verdict with 'unknown_key' and blocked the entire console.
 *
 * 2. Any random string typed into the Licence screen on an air-gapped server granted
 *    permanent, uncapped access, because the resulting 'unconfigured' status counted as
 *    operational forever.
 */
class OnPremLicenceTest extends TestCase
{
    use RefreshDatabase;

    /** A licence as LicenseFile::apply() leaves it after a valid .lic upload. */
    private function fileLicence(array $overrides = []): InstallationLicense
    {
        $licence = InstallationLicense::current();

        $licence->forceFill(array_merge([
            'license_key' => 'ONPREM-KEY-1',
            'status' => 'active',
            'bundle' => [
                'company' => 'Acme Ltd',
                'expires_at' => null,          // perpetual
                'grace_days' => 7,
                'device_limit' => 50,
                'source' => 'file',            // <- the marker that matters
            ],
            'last_checked_at' => null,
        ], $overrides))->save();

        return $licence->fresh();
    }

    // ---- Defect 1: the phone-home must not touch a .lic licence ------------------

    public function test_a_file_licence_is_recognised_as_such(): void
    {
        $this->assertTrue($this->fileLicence()->fromFile());
    }

    public function test_a_central_licence_is_not_treated_as_a_file_licence(): void
    {
        $licence = InstallationLicense::current();
        $licence->forceFill([
            'license_key' => 'CLOUD-KEY',
            'status' => 'active',
            'bundle' => ['source' => 'central', 'expires_at' => null],
        ])->save();

        $this->assertFalse($licence->fresh()->fromFile());
    }

    public function test_central_cannot_demote_a_file_licence_to_unknown_key(): void
    {
        $licence = $this->fileLicence();

        // Central answers "I have never heard of this key" — which is what happens when
        // a .lic was issued offline and never registered centrally.
        Http::fake([
            '*/api/v1/license/validate' => Http::response(['ok' => false, 'reason' => 'unknown_key'], 200),
        ]);

        $after = app(LicenseClient::class)->validate($licence);

        $this->assertSame('active', $after->status, 'the signed .lic must stay authoritative');
        $this->assertTrue($after->operational(), 'a paid offline client must not be blocked');
        $this->assertStringContainsString('remains authoritative', (string) $after->last_error);
    }

    /**
     * End to end, through the real sign-in gate. An ordinary EMPLOYEE is exactly who
     * gets locked out when a licence goes bad (admins are the rescue route and are never
     * blocked at sign-in), so this is the check that matters: with a .lic in place and
     * Central rejecting the key, they must still get in — and the moment the same
     * licence is NOT file-sourced, they must not.
     */
    public function test_an_employee_can_still_sign_in_when_central_rejects_a_file_licence(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        Http::fake([
            '*/api/v1/license/validate' => Http::response(['ok' => false, 'reason' => 'unknown_key'], 200),
        ]);

        $licence = $this->fileLicence();
        app(LicenseClient::class)->validate($licence);

        $this->postJson('/api/auth/login', [
            'email' => 'priya.raman@ametecs.io', 'password' => 'password',
        ])->assertOk();

        // Same rejection, but a Central-sourced licence: the employee IS blocked.
        $licence = InstallationLicense::current();
        $licence->forceFill(['bundle' => ['source' => 'central', 'expires_at' => null], 'status' => 'active'])->save();
        app(LicenseClient::class)->validate($licence->fresh());

        $this->assertSame('unknown_key', InstallationLicense::current()->fresh()->status);
        $this->postJson('/api/auth/login', [
            'email' => 'priya.raman@ametecs.io', 'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_a_central_licence_is_still_demoted_when_central_rejects_it(): void
    {
        $licence = InstallationLicense::current();
        $licence->forceFill([
            'license_key' => 'CLOUD-KEY',
            'status' => 'active',
            'bundle' => ['source' => 'central', 'expires_at' => null],
        ])->save();

        Http::fake([
            '*/api/v1/license/validate' => Http::response(['ok' => false, 'reason' => 'unknown_key'], 200),
        ]);

        $after = app(LicenseClient::class)->validate($licence->fresh());

        $this->assertSame('unknown_key', $after->status, 'the cloud path must keep working');
        $this->assertFalse($after->operational());
    }

    // ---- Defect 2: an unconfirmed key is not a free licence ----------------------

    public function test_a_never_validated_key_works_briefly_then_stops(): void
    {
        $licence = InstallationLicense::current();
        $licence->forceFill([
            'license_key' => 'ANY-OLD-STRING',
            'status' => 'unconfigured',
            'bundle' => null,
        ])->save();

        // Right after saving: allowed, because Central may simply be unreachable.
        $this->assertTrue($licence->fresh()->operational());

        // Eight days later, still never confirmed: no longer a licence.
        $this->travel(8)->days();
        $this->assertFalse($licence->fresh()->operational(),
            'an unconfirmed key must not grant permanent access');
    }

    public function test_a_signed_file_licence_is_unaffected_by_that_window(): void
    {
        $this->fileLicence();

        $this->travel(400)->days();

        $this->assertTrue(InstallationLicense::current()->fresh()->operational(),
            'a perpetual .lic must still work more than a year later');
    }

    public function test_an_expired_file_licence_still_honours_its_grace(): void
    {
        $this->fileLicence([
            'status' => 'expired',
            'bundle' => ['expires_at' => now()->subDays(3)->toDateString(), 'grace_days' => 7, 'source' => 'file'],
        ]);

        $this->assertTrue(InstallationLicense::current()->fresh()->operational(), 'inside grace');

        $this->travel(10)->days();
        $this->assertFalse(InstallationLicense::current()->fresh()->operational(), 'past grace');
    }
}
