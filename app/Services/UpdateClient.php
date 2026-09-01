<?php

namespace App\Services;

use App\Models\InstallationLicense;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * On-prem self-update client (Ejaz, 1-Sep-2026).
 *
 * Talks to SmartEPT Central over the SAME channel and identity as licensing:
 * the licence key is the credential, the server fingerprint proves it is this
 * machine, and only update metadata travels. Central's address, the TLS escape
 * hatch and the strict-redirect handling all come from LicenseClient so there
 * is exactly ONE place that knows where Central lives.
 *
 * This class checks and downloads. It deliberately does NOT install: PHP cannot
 * reliably overwrite the very files it is executing (on Windows/IIS it cannot
 * overwrite them at all while they are open), so installation is handed to the
 * standalone updater in updater/updater.php, launched as its own process.
 */
class UpdateClient
{
    /** Everything this feature writes lives here — never in the app tree. */
    public const DIR = 'app/updates';

    public function __construct(private LicenseClient $licence)
    {
    }

    // ------------------------------------------------------------ identity

    /** The version this server believes it is running. */
    public function currentVersion(): string
    {
        $file = base_path('version.json');
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && ! empty($data['version'])) {
                return (string) $data['version'];
            }
        }

        return '0.0.0';
    }

    /**
     * Which channel this server follows. Comes from version.json — the same file
     * that carries the version — so opting a test box into beta is one edited
     * field and no .env change, and it cannot be lost to `artisan config:cache`.
     */
    public function channel(): string
    {
        $file = base_path('version.json');
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && ($data['channel'] ?? '') === 'beta') {
                return 'beta';
            }
        }

        return 'stable';
    }

    /** Stable, anonymous id for this installation (same shape as the licence fingerprint). */
    public function installationId(): string
    {
        return substr(hash('sha256', 'install|' . config('app.key')), 0, 32);
    }

    private function licenceKey(): ?string
    {
        $key = InstallationLicense::current()->license_key;

        return $key ?: null;
    }

    // ------------------------------------------------------------ the check

    /**
     * Ask Central whether a newer build exists. Never throws — the Licence
     * screen must always render, even with the network down.
     */
    public function check(): array
    {
        $key = $this->licenceKey();
        if (! $key) {
            return $this->fail('This server has no licence key yet. Enter and validate the key below, then check again.');
        }

        $url = rtrim((string) $this->licence->baseUrl(), '/') . '/api/v1/updates/check';

        try {
            $res = Http::timeout(20)->acceptJson()
                ->withOptions(['allow_redirects' => ['max' => 5, 'strict' => true]])
                ->when(! config('smartept.license_verify', true), fn ($r) => $r->withoutVerifying())
                ->post($url, [
                    'key'             => $key,
                    'fingerprint'     => $this->licence->fingerprint(),
                    'current_version' => $this->currentVersion(),
                    'product'         => 'smartept',
                    'channel'         => $this->channel(),
                    'installation_id' => $this->installationId(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('update check failed: ' . $e->getMessage());

            return $this->fail('Could not reach SmartEPT Central. Check this server\'s internet connection and try again.');
        }

        $body = $res->json();
        if (! is_array($body)) {
            return $this->fail('SmartEPT Central sent an unexpected reply (HTTP ' . $res->status() . ').');
        }
        if (! $res->successful() || ! ($body['ok'] ?? false)) {
            $message = $body['message'] ?? 'SmartEPT Central refused the update check.';
            // "Key not recognised" is usually the wrong Central, not a bad key — an
            // .env still pointing at a dev instance. Name the server that answered,
            // so the admin can see the difference without opening a support ticket.
            if (($body['reason'] ?? null) === 'unknown_key') {
                $message .= ' (asked ' . parse_url($url, PHP_URL_HOST) . ')';
            }

            return $this->fail($message, $body['reason'] ?? null);
        }

        $state = $this->state();
        $state['checked_at'] = now()->toDateTimeString();
        $state['current_version'] = $this->currentVersion();

        if (! ($body['update_available'] ?? false)) {
            $state['phase'] = 'idle';
            $state['available'] = null;
            $state['message'] = $body['message'] ?? 'This server is on the latest version.';
            $this->writeState($state);

            return ['ok' => true, 'update_available' => false] + $state;
        }

        $state['phase'] = 'available';
        $state['available'] = [
            'version'      => $body['version'],
            'title'        => $body['title'] ?? null,
            'notes'        => $body['notes'] ?? null,
            'size_bytes'   => $body['size_bytes'] ?? null,
            'package_hash' => $body['package_hash'] ?? null,
            'download_url' => $body['download_url'] ?? null,
            'released_at'  => $body['released_at'] ?? null,
        ];
        $state['message'] = 'Version ' . $body['version'] . ' is available.';
        $this->writeState($state);

        return ['ok' => true, 'update_available' => true] + $state;
    }

    // ----------------------------------------------------------- the download

    /** Fetch the package Central offered and prove it arrived intact. */
    public function download(): array
    {
        $state = $this->state();
        $available = $state['available'] ?? null;
        if (! $available || empty($available['download_url'])) {
            return $this->fail('Nothing to download — press "Check for Update" first.');
        }

        $dir = storage_path(self::DIR);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true)) {
            return $this->fail('Could not create ' . $dir . '. Give the web server write access to storage/ and try again.');
        }
        if (! is_writable($dir)) {
            return $this->fail('storage/app/updates is not writable by the web server, so the package cannot be saved.');
        }

        $target = $dir . '/SmartEPT-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $available['version']) . '-update.zip';

        $state['phase'] = 'downloading';
        $state['message'] = 'Downloading version ' . $available['version'] . '…';
        $this->writeState($state);

        try {
            $res = Http::timeout(900)
                ->withOptions(['allow_redirects' => ['max' => 5, 'strict' => true], 'sink' => $target])
                ->when(! config('smartept.license_verify', true), fn ($r) => $r->withoutVerifying())
                ->get($available['download_url']);
        } catch (\Throwable $e) {
            @unlink($target);

            return $this->fail('The download did not finish: ' . $e->getMessage());
        }

        if (! $res->successful()) {
            @unlink($target);

            return $this->fail('SmartEPT Central refused the download (HTTP ' . $res->status()
                . '). The link may have expired — check for the update again.');
        }

        // Normally the sink already wrote the file as it streamed. Some transports
        // (and the test double) hand the body back instead — take it either way.
        if (! is_file($target) || filesize($target) === 0) {
            file_put_contents($target, $res->body());
        }
        if (! is_file($target) || filesize($target) === 0) {
            return $this->fail('The package could not be saved to storage/app/updates.');
        }

        // Integrity is not optional: a truncated ZIP that installs is far worse
        // than a download that failed loudly.
        if (! empty($available['package_hash'])) {
            $got = hash_file('sha256', $target);
            if (! hash_equals(strtolower($available['package_hash']), strtolower($got))) {
                @unlink($target);

                return $this->fail('The downloaded package failed its integrity check and was deleted. Try again.');
            }
        }

        $state = $this->state();
        $state['phase'] = 'downloaded';
        $state['package'] = $target;
        $state['message'] = 'Version ' . $available['version'] . ' downloaded and verified. Ready to install.';
        $this->writeState($state);

        return ['ok' => true] + $state;
    }

    // ------------------------------------------------------------ the install

    /**
     * Hand the package to the standalone updater and return immediately. The
     * console then polls status() while the updater works.
     */
    public function install(): array
    {
        $state = $this->state();
        $package = $state['package'] ?? null;
        $version = $state['available']['version'] ?? null;

        if (! $package || ! is_file($package) || ! $version) {
            return $this->fail('No verified package is waiting. Check for the update and download it first.');
        }
        if (in_array($state['phase'] ?? '', ['installing'], true)) {
            return ['ok' => true] + $state; // already running — never launch twice
        }

        $updater = base_path('updater/updater.php');
        if (! is_file($updater)) {
            return $this->fail('The updater component is missing (updater/updater.php). Contact SmartEPT support.');
        }

        $php = $this->phpBinary();
        if (! $php) {
            return $this->fail('Could not locate the PHP command-line program on this server, so the update cannot be '
                . 'installed automatically. Set SMARTEPT_PHP_BINARY in .env to the full path of php.exe (or php).');
        }
        if (! $this->canSpawn()) {
            return $this->fail('This server blocks background processes (exec/popen are disabled in php.ini), so the '
                . 'update cannot install itself. Ask your IT team to run: ' . $php . ' updater/updater.php');
        }

        $job = [
            'base'        => base_path(),
            'package'     => $package,
            'version'     => $version,
            'sha256'      => $state['available']['package_hash'] ?? null,
            'php'         => $php,
            'state_file'  => $this->stateFile(),
            'started_at'  => now()->toDateTimeString(),
        ];
        $jobFile = storage_path(self::DIR . '/job.json');
        file_put_contents($jobFile, json_encode($job, JSON_PRETTY_PRINT));

        $state['phase'] = 'installing';
        $state['percent'] = 5;
        $state['message'] = 'Starting the updater…';
        $state['log'] = ['Update started for version ' . $version . '.'];
        $this->writeState($state);

        $this->spawn($php, $updater, $jobFile);

        return ['ok' => true] + $this->state();
    }

    // ------------------------------------------------------------ state

    public function stateFile(): string
    {
        return storage_path(self::DIR . '/state.json');
    }

    public function state(): array
    {
        $file = $this->stateFile();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $data = is_array($data) ? $data : [];

        return $data + [
            'phase'           => 'idle',
            'percent'         => 0,
            'message'         => '',
            'available'       => null,
            'package'         => null,
            'log'             => [],
            'checked_at'      => null,
            'current_version' => $this->currentVersion(),
        ];
    }

    public function writeState(array $state): void
    {
        $dir = storage_path(self::DIR);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $state['current_version'] = $state['current_version'] ?? $this->currentVersion();
        $state['updated_at'] = now()->toDateTimeString();
        @file_put_contents($this->stateFile(), json_encode($state, JSON_PRETTY_PRINT));
    }

    // ------------------------------------------------------------ helpers

    private function fail(string $message, ?string $reason = null): array
    {
        return ['ok' => false, 'message' => $message, 'reason' => $reason] + $this->state();
    }

    /**
     * The PHP CLI binary. PHP_BINARY under a web SAPI points at php-fpm/php-cgi,
     * which cannot run a script, so it is only trusted when it really is "php".
     */
    public function phpBinary(): ?string
    {
        $candidates = [];
        if ($env = env('SMARTEPT_PHP_BINARY')) {
            $candidates[] = $env;
        }
        $windows = str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');
        $exe = $windows ? 'php.exe' : 'php';
        if (defined('PHP_BINDIR')) {
            $candidates[] = rtrim(PHP_BINDIR, '\\/') . DIRECTORY_SEPARATOR . $exe;
        }
        if (defined('PHP_BINARY') && PHP_BINARY) {
            $name = strtolower(basename(PHP_BINARY));
            if ($name === 'php' || $name === 'php.exe') {
                $candidates[] = PHP_BINARY;
            }
            $candidates[] = rtrim(dirname(PHP_BINARY), '\\/') . DIRECTORY_SEPARATOR . $exe;
        }

        foreach ($candidates as $c) {
            if ($c && is_file($c)) {
                return $c;
            }
        }

        return null;
    }

    public function canSpawn(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('popen', $disabled, true) || ! in_array('proc_open', $disabled, true);
    }

    /** Launch the updater detached so it survives this request ending. */
    private function spawn(string $php, string $updater, string $jobFile): void
    {
        $log = storage_path(self::DIR . '/updater.log');
        $windows = str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');

        $cmd = $windows
            ? 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($updater) . ' ' . escapeshellarg($jobFile)
                . ' > ' . escapeshellarg($log) . ' 2>&1'
            : escapeshellarg($php) . ' ' . escapeshellarg($updater) . ' ' . escapeshellarg($jobFile)
                . ' > ' . escapeshellarg($log) . ' 2>&1 &';

        try {
            if ($windows) {
                $handle = popen('cmd /C ' . $cmd, 'r');
                if ($handle !== false) {
                    pclose($handle);
                }
            } else {
                $handle = proc_open($cmd, [], $pipes);
                if (is_resource($handle)) {
                    proc_close($handle);
                }
            }
        } catch (\Throwable $e) {
            Log::error('updater spawn failed: ' . $e->getMessage());
            $state = $this->state();
            $state['phase'] = 'failed';
            $state['message'] = 'The updater could not be started: ' . $e->getMessage();
            $this->writeState($state);
        }
    }
}
