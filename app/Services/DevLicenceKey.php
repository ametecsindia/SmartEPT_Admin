<?php

namespace App\Services;

/**
 * DEVELOPER LICENCE TOGGLE (Ejaz, 19-Aug-2026).
 *
 * A single file at the project root turns licence enforcement off on OUR machines,
 * so the product can be run and tested locally without issuing a real .lic every
 * time. Delete the file and the licence enforces again — nothing else changes, and
 * there is no environment variable, no config edit and no code change involved.
 *
 *     php artisan smartept:licence off      -> writes  licence-off.key   (enforcement OFF)
 *     php artisan smartept:licence on       -> deletes it                (enforcement ON)
 *     php artisan smartept:licence status   -> says which state you are in
 *
 * WHY THE FILE IS KEYED, AND NOT JUST "DOES IT EXIST"
 * --------------------------------------------------
 * A bare marker file would be the easiest licence bypass ever shipped: any client
 * could create an empty licence-off.key and stop paying. So the file must contain a
 * token that only this codebase can produce for this machine:
 *
 *     token = HMAC-SHA256(SECRET, 'SMARTEPT-DEV-OFF|' . machineFingerprint)
 *
 * SECRET lives in this class and therefore inside the encoded app/ on any build that
 * leaves our office. A client can create the file, copy ours, or guess — none of it
 * works, because the token is bound to THEIR machine fingerprint and they cannot
 * compute a new one without the secret.
 *
 * The file is also excluded from the client-side build (deployment/make-clientside.php)
 * and gitignored, so it cannot reach a client by accident even if someone forgets.
 *
 * ROTATE `SECRET` if it is ever exposed. Doing so invalidates every existing
 * licence-off.key; regenerate with the artisan command above.
 */
class DevLicenceKey
{
    /**
     * Build secret. Not a credential for anything else — its only power is switching
     * licence enforcement off on a machine that already holds the file.
     */
    private const SECRET = 'sm4rtEPT|dev-licence-toggle|2026-08-19|ametecs-india';

    private const FILENAME = 'licence-off.key';

    /** Absolute path to the toggle file. */
    public function path(): string
    {
        return base_path(self::FILENAME);
    }

    /** The only token that disables enforcement on THIS machine. */
    public function expectedToken(): string
    {
        return hash_hmac(
            'sha256',
            'SMARTEPT-DEV-OFF|' . app(LicenseFile::class)->machineFingerprint(),
            self::SECRET
        );
    }

    /**
     * Is licence enforcement switched off on this machine right now?
     *
     * Fails SAFE: any error, missing file, or wrong token means enforcement stays ON.
     * Compared with hash_equals so a wrong token cannot be found by timing.
     */
    public function active(): bool
    {
        try {
            $file = $this->path();

            if (! is_readable($file)) {
                return false;
            }

            $given = trim((string) @file_get_contents($file));

            return $given !== '' && hash_equals($this->expectedToken(), $given);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Write the toggle for this machine. Returns the path written. */
    public function enable(): string
    {
        $file = $this->path();

        file_put_contents($file, $this->expectedToken() . "\n");

        return $file;
    }

    /** Remove the toggle. Returns true if a file was actually deleted. */
    public function disable(): bool
    {
        $file = $this->path();

        return is_file($file) && @unlink($file);
    }

    /**
     * The one place the rest of the app asks "should the licence be enforced?".
     * Keeps the env switch working for cloud/dev, and adds the file toggle.
     */
    public static function enforcementOn(): bool
    {
        if (! filter_var(config('smartept.licence_enforce', true), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return ! app(self::class)->active();
    }
}
