<?php

namespace Tests\Feature;

use App\Services\DevLicenceKey;
use App\Services\LicenseFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DEVELOPER LICENCE TOGGLE (Ejaz, 19-Aug-2026).
 *
 * A file at the project root switches licence enforcement off on OUR machines for
 * local testing; deleting it restores enforcement. The whole point of these tests is
 * the security half: the file must be USELESS to a client. A bare marker would be a
 * one-touch licence bypass, so it carries a token bound to the machine fingerprint
 * and keyed with a secret that lives inside the (encoded) app/.
 */
class DevLicenceKeyTest extends TestCase
{
    use RefreshDatabase;

    private function key(): DevLicenceKey
    {
        return app(DevLicenceKey::class);
    }

    protected function tearDown(): void
    {
        $this->key()->disable();   // never leave the toggle behind
        parent::tearDown();
    }

    public function test_enforcement_is_on_when_no_toggle_file_exists(): void
    {
        $this->key()->disable();

        $this->assertFalse($this->key()->active());
        $this->assertTrue(DevLicenceKey::enforcementOn());
    }

    public function test_a_correct_token_switches_enforcement_off(): void
    {
        $path = $this->key()->enable();

        $this->assertFileExists($path);
        $this->assertTrue($this->key()->active());
        $this->assertFalse(DevLicenceKey::enforcementOn());
    }

    public function test_removing_the_file_restores_enforcement(): void
    {
        $this->key()->enable();
        $this->assertFalse(DevLicenceKey::enforcementOn());

        $this->assertTrue($this->key()->disable());
        $this->assertTrue(DevLicenceKey::enforcementOn(), 'deleting the file must re-arm the licence');
    }

    public function test_an_empty_file_does_nothing(): void
    {
        file_put_contents($this->key()->path(), '');

        $this->assertFalse($this->key()->active(), 'a bare marker file must not disable licensing');
        $this->assertTrue(DevLicenceKey::enforcementOn());
    }

    public function test_a_guessed_or_copied_token_does_nothing(): void
    {
        // What a client would try: random text, a plausible-looking hash, and a token
        // that is valid on SOMEBODY ELSE's machine (a copied licence-off.key).
        $foreignToken = hash_hmac(
            'sha256',
            'SMARTEPT-DEV-OFF|' . str_repeat('a', 40),   // another machine's fingerprint
            'sm4rtEPT|dev-licence-toggle|2026-08-19|ametecs-india'
        );

        foreach (['yes', 'true', '1', str_repeat('f', 64), $foreignToken] as $attempt) {
            file_put_contents($this->key()->path(), $attempt);

            $this->assertFalse($this->key()->active(), "token '{$attempt}' must be rejected");
            $this->assertTrue(DevLicenceKey::enforcementOn());
        }
    }

    public function test_the_token_is_bound_to_this_machine(): void
    {
        $fingerprint = app(LicenseFile::class)->machineFingerprint();

        $this->assertSame(
            hash_hmac('sha256', 'SMARTEPT-DEV-OFF|' . $fingerprint,
                'sm4rtEPT|dev-licence-toggle|2026-08-19|ametecs-india'),
            $this->key()->expectedToken()
        );
    }

    public function test_the_artisan_command_reports_and_switches_state(): void
    {
        $this->artisan('smartept:licence status')->expectsOutputToContain('ON')->assertSuccessful();

        $this->artisan('smartept:licence off')->assertSuccessful();
        $this->assertTrue($this->key()->active());

        $this->artisan('smartept:licence status')->expectsOutputToContain('OFF')->assertSuccessful();

        $this->artisan('smartept:licence on')->assertSuccessful();
        $this->assertFalse($this->key()->active());
    }

    public function test_the_toggle_lets_a_blocked_employee_sign_in(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        // Force the install into a blocked licence state.
        $licence = \App\Models\InstallationLicense::current();
        $licence->forceFill(['license_key' => 'TEST-KEY', 'status' => 'revoked'])->save();

        $this->postJson('/api/auth/login', [
            'email' => 'priya.raman@ametecs.io', 'password' => 'password',
        ])->assertStatus(403);

        $this->key()->enable();

        $this->postJson('/api/auth/login', [
            'email' => 'priya.raman@ametecs.io', 'password' => 'password',
        ])->assertOk();
    }
}
