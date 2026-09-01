<?php
/**
 * SmartEPT on-premises updater (Ejaz, 1-Sep-2026).
 *
 * Runs as its OWN process, outside Laravel, launched by
 * App\Services\UpdateClient::install(). It has to be standalone: the files it
 * replaces include the very application that would otherwise be running it,
 * and on Windows an open PHP file cannot be overwritten at all.
 *
 * The sequence mirrors the agreed flow chart:
 *   verify → backup → maintenance mode → install → migrate → health check →
 *   maintenance off, and on ANY failure after the backup exists, roll back.
 *
 * Progress is written to storage/app/updates/state.json, which the console
 * polls through public/update-status.php (that file answers during maintenance
 * mode, when nothing routed through Laravel can).
 *
 * Usage: php updater/updater.php <path-to-job.json>
 */

set_time_limit(0);
ignore_user_abort(true);
error_reporting(E_ALL);

$jobFile = $argv[1] ?? '';
if ($jobFile === '' || ! is_file($jobFile)) {
    fwrite(STDERR, "updater: job file not found\n");
    exit(1);
}

$job = json_decode((string) file_get_contents($jobFile), true);
if (! is_array($job) || empty($job['base']) || empty($job['package']) || empty($job['version'])) {
    fwrite(STDERR, "updater: malformed job file\n");
    exit(1);
}

$base      = rtrim($job['base'], '\\/');
$package   = $job['package'];
$version   = $job['version'];
$sha       = $job['sha256'] ?? null;
$php       = $job['php'] ?? PHP_BINARY;
$stateFile = $job['state_file'] ?? ($base . '/storage/app/updates/state.json');
$workDir   = dirname($stateFile);
$stamp     = date('Ymd_Hi');
$backupDir = $base . '/storage/app/updates/backup_' . $stamp;

/** Everything below reports through here so the console always knows where it is. */
function step(string $phase, int $percent, string $message): void
{
    global $stateFile;
    $state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
    $state = is_array($state) ? $state : [];
    $state['phase']   = $phase;
    $state['percent'] = $percent;
    $state['message'] = $message;
    $log = isset($state['log']) && is_array($state['log']) ? $state['log'] : [];
    $log[] = date('H:i:s') . '  ' . $message;
    $state['log'] = array_slice($log, -60);
    $state['updated_at'] = date('Y-m-d H:i:s');
    @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    echo $message . PHP_EOL;
}

function setInstalledVersion(string $v): void
{
    global $stateFile;
    $state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
    $state = is_array($state) ? $state : [];
    $state['current_version'] = $v;
    $state['available'] = null;
    $state['package'] = null;
    @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
}

function artisan(string $command, string $base, string $php): array
{
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($base . '/artisan') . ' ' . $command . ' 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);

    return [$code, trim(implode("\n", $out))];
}

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function copyTree(string $from, string $to, array $skipTop = []): int
{
    $count = 0;
    if (is_file($from)) {
        @mkdir(dirname($to), 0775, true);
        if (@copy($from, $to)) {
            $count++;
        }

        return $count;
    }
    if (! is_dir($from)) {
        return 0;
    }
    @mkdir($to, 0775, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (in_array(strtolower($entry), $skipTop, true)) {
            continue;
        }
        $count += copyTree($from . '/' . $entry, $to . '/' . $entry);
    }

    return $count;
}

/**
 * Restore whatever was backed up, then let the app back in.
 *
 * Each replaced folder is REMOVED before its backup is copied back, not merged
 * over. Copying alone would leave behind every file the new version added — a
 * stray new migration in database/migrations would then run on the next update
 * and break a database the rollback was supposed to protect.
 */
function rollback(string $backupDir, string $base, string $php, string $why): void
{
    global $topLevel;

    step('rolling_back', 90, 'Update failed: ' . $why . ' — restoring the previous version.');
    if (is_dir($backupDir)) {
        foreach ((array) $topLevel as $entry) {
            $live = $base . '/' . $entry;
            is_dir($live) ? rrmdir($live) : @unlink($live);
            if (file_exists($backupDir . '/' . $entry)) {
                copyTree($backupDir . '/' . $entry, $live);
            }
        }
        if (is_file($backupDir . '/version.json')) {
            @copy($backupDir . '/version.json', $base . '/version.json');
        }
        step('rolling_back', 95, 'Previous version restored from ' . basename($backupDir) . '.');
    } else {
        step('rolling_back', 95, 'No backup had been taken yet — nothing was changed.');
    }
    artisan('optimize:clear', $base, $php);
    artisan('up', $base, $php);
    @unlink($base . '/storage/framework/down');
    step('failed', 100, 'Update cancelled. This server is still running its previous version. Reason: ' . $why);
    exit(1);
}

// ---------------------------------------------------------------- 1. verify
step('verifying', 10, 'Verifying the update package…');

if (! is_file($package)) {
    step('failed', 100, 'The update package is missing. Download it again.');
    exit(1);
}
if ($sha && ! hash_equals(strtolower($sha), strtolower((string) hash_file('sha256', $package)))) {
    @unlink($package);
    step('failed', 100, 'The update package failed its integrity check and was deleted. Download it again.');
    exit(1);
}
if (! class_exists('ZipArchive')) {
    step('failed', 100, 'PHP on this server has no zip extension, so the package cannot be opened. Enable ext-zip.');
    exit(1);
}

$extract = $workDir . '/extract_' . preg_replace('/[^0-9A-Za-z.\-]/', '', $version) . '_' . $stamp;
rrmdir($extract);
@mkdir($extract, 0775, true);

$zip = new ZipArchive();
if ($zip->open($package) !== true || ! $zip->extractTo($extract)) {
    rrmdir($extract);
    step('failed', 100, 'The update package could not be opened. Download it again.');
    exit(1);
}
$zip->close();

// The chart's package wraps the payload in app/ next to manifest.json; a plain
// zip of the application root is accepted too, so both build styles install.
$payload = (is_file($extract . '/manifest.json') && is_dir($extract . '/app'))
    ? $extract . '/app'
    : $extract;

$topLevel = array_values(array_diff(scandir($payload) ?: [], ['.', '..']));
if (! $topLevel) {
    rrmdir($extract);
    step('failed', 100, 'The update package is empty.');
    exit(1);
}

// Never let a package overwrite this installation's identity or its data.
$protected = ['.env', 'storage', 'license.lic', '.git'];
$topLevel = array_values(array_filter($topLevel, fn ($e) => ! in_array(strtolower($e), $protected, true)));

step('verifying', 15, 'Package verified. It replaces: ' . implode(', ', $topLevel) . '.');

// ---------------------------------------------------------------- 2. backup
step('backup', 25, 'Backing up the current version…');

@mkdir($backupDir, 0775, true);
if (! is_dir($backupDir)) {
    rrmdir($extract);
    step('failed', 100, 'Could not create a backup folder under storage/app/updates. Nothing was changed.');
    exit(1);
}
foreach ($topLevel as $entry) {
    if (file_exists($base . '/' . $entry)) {
        copyTree($base . '/' . $entry, $backupDir . '/' . $entry);
    }
}
if (is_file($base . '/version.json')) {
    copyTree($base . '/version.json', $backupDir . '/version.json');
}
step('backup', 30, 'Backup written to storage/app/updates/' . basename($backupDir) . '.');

// ------------------------------------------------------- 3. maintenance mode
step('maintenance', 35, 'Putting SmartEPT into maintenance mode…');

[$code] = artisan('down --retry=60', $base, $php);
if ($code !== 0) {
    // artisan may already be half-replaced or unbootable — the flag file alone
    // is enough for Laravel to refuse requests.
    @mkdir($base . '/storage/framework', 0775, true);
    @file_put_contents($base . '/storage/framework/down', json_encode([
        'except' => [], 'redirect' => null, 'retry' => 60, 'refresh' => null,
        'secret' => null, 'status' => 503, 'template' => null,
    ]));
}

// ---------------------------------------------------------------- 4. install
step('installing', 55, 'Installing version ' . $version . '…');

$copied = 0;
foreach ($topLevel as $entry) {
    $copied += copyTree($payload . '/' . $entry, $base . '/' . $entry);
}
if ($copied === 0) {
    rrmdir($extract);
    rollback($backupDir, $base, $php, 'no files could be written (check folder permissions)');
}
step('installing', 60, $copied . ' files installed.');

// The status endpoint must survive an update that replaces public/.
if (! is_file($base . '/public/update-status.php') && is_file($base . '/updater/update-status.php')) {
    @copy($base . '/updater/update-status.php', $base . '/public/update-status.php');
}

// -------------------------------------------------------------- 5. migrations
step('migrating', 70, 'Updating the database…');

artisan('optimize:clear', $base, $php);
[$mCode, $mOut] = artisan('migrate --force', $base, $php);
if ($mCode !== 0) {
    rrmdir($extract);
    rollback($backupDir, $base, $php, 'the database update failed — ' . substr($mOut, 0, 400));
}

// ------------------------------------------------------------ 6. health check
step('checking', 85, 'Checking that SmartEPT starts correctly…');

[$hCode, $hOut] = artisan('--version', $base, $php);
if ($hCode !== 0) {
    rrmdir($extract);
    rollback($backupDir, $base, $php, 'the application did not start after the update — ' . substr($hOut, 0, 400));
}

// -------------------------------------------------------- 7. maintenance off
step('finishing', 95, 'Bringing SmartEPT back online…');

// Keep the channel: a beta test box must stay on beta after it updates, or the
// next check silently drops it back to stable.
$existing = is_file($base . '/version.json')
    ? json_decode((string) file_get_contents($base . '/version.json'), true) : null;
file_put_contents($base . '/version.json', json_encode([
    'product' => 'smartept',
    'version' => $version,
    'channel' => (is_array($existing) && ($existing['channel'] ?? '') === 'beta') ? 'beta' : 'stable',
    'installed_at' => date('c'),
], JSON_PRETTY_PRINT));

artisan('optimize:clear', $base, $php);
artisan('up', $base, $php);
@unlink($base . '/storage/framework/down');

rrmdir($extract);
@unlink($package);

setInstalledVersion($version);
step('done', 100, 'SmartEPT is now running version ' . $version . '. The previous version is kept in storage/app/updates/'
    . basename($backupDir) . ' and can be deleted once everything looks right.');

exit(0);
