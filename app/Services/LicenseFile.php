<?php

namespace App\Services;

use App\Models\InstallationLicense;
use Illuminate\Support\Carbon;

/**
 * Offline, node-locked licence file (EPT-29).
 *
 * A `license.lic` is a signed token: base64url(payload).base64url(signature).
 * SmartEPT Central signs the payload with a PRIVATE key it never shares; this class
 * verifies it with the PUBLIC key embedded below. Because the product ships
 * SourceGuardian-encrypted, neither the public key nor this verification can be
 * swapped out on the client, so a forged file cannot pass.
 *
 * No network is used — validation is fully offline. The file is bound to a machine
 * fingerprint, so copying it to another PC fails. A valid file simply populates the
 * existing InstallationLicense record, so every downstream check (EnsureLicensed,
 * device limit, the Licence screen, the 7-day evaluation) keeps working unchanged.
 */
class LicenseFile
{
    /**
     * SmartEPT Central licence PUBLIC key. Replace this whole block with the public
     * key from YOUR key pair before SourceGuardian-encrypting for production
     * (deployment\installers\GENERATE-LICENSE-KEYS.bat prints it). Keep the matching
     * private key offline on Central — never ship it.
     */
    private const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAt8/Jhr44AvMaoQwHg9H/
u/PaR6GFmrTDc2/4IYl69AlXAogbhmPj/ZfJakvArMncCQrNQwDPVknGofTK4OjI
IKzMLNZt8JkESR8yTcnudIPIYpL5t+CG+pU5nDPjsIPxw5e+qJloYJBvzhiiYg2x
pRDf1YR/qPQKLy/Aaba21lSngnI4/yfriH4wajf932sBcnQ01dR3/k6TSo96FxKi
EWo0a4oX3asStib/S9nFD9jcuz2lJjFBxaio4TkT9jk+avA4LHDoXnH7H1xOUH82
pU/2CqdwOp4B/s7ncPSzL1iUCqTKUHsV5BmZI1ZLnqmc1dGiBPNiBlLQzwBzg7vG
GwIDAQAB
-----END PUBLIC KEY-----
PEM;

    /** Where the licence file lives (defaults to license.lic in the app root). */
    public function path(): string
    {
        $p = (string) config('smartept.license_file');
        return $p !== '' ? $p : base_path('license.lic');
    }

    /** Stable, hard-to-move machine fingerprint (SMBIOS/OS UUID, hashed). */
    public function machineFingerprint(): string
    {
        // SmartPRS2 pattern (13-Aug-2026): compute ONCE, persist to
        // storage/app/.machine_fp. Spawning wmic/powershell per request is slow
        // and can intermittently fail — which would make a valid licence look
        // "wrong machine" and block a licensed client. The persisted file keeps
        // the fingerprint fast and STABLE for the life of the install.
        static $fp = null;
        if ($fp !== null) {
            return $fp;
        }
        $cacheFile = storage_path('app/.machine_fp');
        if (is_readable($cacheFile)) {
            $c = trim((string) @file_get_contents($cacheFile));
            if (strlen($c) === 40 && ctype_xdigit($c)) {
                return $fp = $c;
            }
        }
        $fp = substr(hash('sha256', 'SMARTEPT|' . $this->rawMachineId()), 0, 40);
        @file_put_contents($cacheFile, $fp);

        return $fp;
    }

    private function rawMachineId(): string
    {
        try {
            if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
                $out = (string) @shell_exec('wmic csproduct get uuid 2>NUL');
                if (preg_match('/[0-9A-Fa-f]{8}-?[0-9A-Fa-f]{4}-?[0-9A-Fa-f]{4}-?[0-9A-Fa-f]{4}-?[0-9A-Fa-f]{12}/', $out, $m)) {
                    return strtoupper($m[0]);
                }
                $out = (string) @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystemProduct).UUID" 2>NUL');
                if (trim($out) !== '') {
                    return strtoupper(trim($out));
                }
            } elseif (PHP_OS_FAMILY === 'Linux') {
                foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $f) {
                    if (is_readable($f)) {
                        return trim((string) file_get_contents($f));
                    }
                }
            } elseif (PHP_OS_FAMILY === 'Darwin' && function_exists('shell_exec')) {
                $out = (string) @shell_exec("ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null | awk -F'\"' '/IOPlatformUUID/{print \$4}'");
                if (trim($out) !== '') {
                    return trim($out);
                }
            }
        } catch (\Throwable $e) {
            // fall through to the weak fallback
        }

        // Fallback (never empty). Weaker than a hardware UUID but keeps the app usable.
        return php_uname('n') . '|' . php_uname('s') . '|' . php_uname('m');
    }

    /** Verify the licence file. Returns ['ok'=>bool,'reason'=>?string,'payload'=>?array]. */
    public function verify(): array
    {
        $file = $this->path();
        if (! is_readable($file)) {
            return ['ok' => false, 'reason' => 'no_file'];
        }

        $raw = trim((string) file_get_contents($file));
        $parts = explode('.', $raw);
        if (count($parts) !== 2) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        [$b64, $sig64] = $parts;
        $payloadJson = $this->b64urlDecode($b64);
        $sig = $this->b64urlDecode($sig64);
        if ($payloadJson === false || $sig === false) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $pub = openssl_pkey_get_public(self::PUBLIC_KEY);
        if ($pub === false) {
            return ['ok' => false, 'reason' => 'bad_public_key'];
        }

        // Signature is over the exact base64url payload string.
        if (openssl_verify($b64, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
            return ['ok' => false, 'reason' => 'invalid_signature'];
        }

        $p = json_decode($payloadJson, true);
        if (! is_array($p) || empty($p['key'])) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        // Node-lock: the file MUST be for THIS machine. SmartPRS2 rule
        // (13-Aug-2026): a .lic with NO fingerprint is a FLOATING licence that
        // would run on any PC, breaking "one licence <-> one machine" — refuse
        // it. Central always embeds the target machine's fingerprint.
        $fp = (string) ($p['fingerprint'] ?? '');
        if ($fp === '') {
            return ['ok' => false, 'reason' => 'not_locked', 'payload' => $p];
        }
        if (! hash_equals($fp, $this->machineFingerprint())) {
            return ['ok' => false, 'reason' => 'wrong_machine', 'payload' => $p];
        }

        return ['ok' => true, 'reason' => null, 'payload' => $p];
    }

    /**
     * Verify the file and fold the result into the InstallationLicense record so the
     * rest of the app (gate, device limit, Licence screen) sees a normal licence.
     */
    public function apply(): InstallationLicense
    {
        $license = InstallationLicense::current();
        $v = $this->verify();

        if ($v['ok']) {
            $p = $v['payload'];
            $expires = $p['expires_at'] ?? null;
            $expired = $expires && Carbon::parse($expires)->endOfDay()->isPast();

            $license->forceFill([
                'license_key' => $p['key'],
                'status' => $expired ? 'expired' : 'active',
                'bundle' => [
                    'company' => $p['company'] ?? null,
                    'plan' => $p['plan'] ?? null,
                    'device_limit' => $p['device_limit'] ?? null,
                    'kind' => $p['kind'] ?? null,
                    'deployment' => $p['deployment'] ?? null,
                    'expires_at' => $expires,
                    'grace_days' => (int) ($p['grace_days'] ?? 7),
                    'features' => $p['features'] ?? [],
                    'source' => 'file',
                ],
                'last_checked_at' => now(),
                'unreachable_since' => null,
                'last_error' => null,
            ])->save();
        } elseif ($v['reason'] !== 'no_file') {
            // A file is present but bad — surface it; block (unless still in evaluation).
            $license->forceFill([
                'status' => $v['reason'] === 'wrong_machine' ? 'server_mismatch' : 'invalid_license_file',
                'last_checked_at' => now(),
                'last_error' => 'Licence file rejected: ' . $v['reason'],
            ])->save();
        }
        // no_file → leave the record as-is (evaluation window or a previously good state).

        return $license->fresh() ?? $license;
    }

    /** Save an uploaded/pasted licence token to the licence path, then apply it. */
    public function import(string $contents): InstallationLicense
    {
        $file = $this->path();
        @file_put_contents($file, trim($contents) . "\n");

        return $this->apply();
    }

    private function b64urlDecode(string $s)
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
