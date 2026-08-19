<?php

/**
 * Build the pure on-premise client-side application folder.
 *
 *   php deployment/make-clientside.php [target] [--force] [--no-vendor]
 *
 * Default target: a sibling of this project called `smartept-clientside`
 * (so from C:\laragon\www\smartept it writes C:\laragon\www\smartept-clientside).
 * Pass a path to put it anywhere, or a bare name to make a per-client folder:
 *
 *   php deployment/make-clientside.php                    -> ..\smartept-clientside
 *   php deployment/make-clientside.php acme               -> ..\smartept-clientside-acme
 *   php deployment/make-clientside.php D:\builds\acme     -> that exact path
 *
 * What comes out is the APPLICATION and nothing else: app, bootstrap, config,
 * database (migrations + seeders), public, resources, routes, storage skeleton,
 * vendor, artisan, composer.json, .env.example. No .bat files, no shell scripts,
 * no tests, no docs, no build tooling, no git metadata, and none of our own
 * runtime data. Encode whatever you choose afterwards — this script does not
 * touch SourceGuardian.
 *
 * Deliberately plain PHP, not a batch file: the same command runs on the Windows
 * boxes we usually install on and on the Linux ones we occasionally do.
 */

// ---------------------------------------------------------------------------
// What NEVER goes to a client
// ---------------------------------------------------------------------------

/** Directories, relative to the project root. Matched on the whole path. */
const SKIP_DIRS = [
    '.git', '.github', '.idea', '.vscode', '.fleet', '.nova', '.zed',
    'node_modules', 'tests', 'docs', 'deployment', '_cloudsync', '_to_delete',
    'individual',

    // Our own runtime data. storage/app/backups holds DATABASE BACKUPS and
    // storage/app/smartept holds stored screenshots/evidence — shipping either
    // would hand a client another customer's data.
    'storage/logs',
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/app/backups',
    'storage/app/smartept',
    'storage/app/evidence',
    'storage/app/private',
    'storage/app/public',
    'bootstrap/cache',
    'public/storage',   // a symlink; `php artisan storage:link` recreates it
];

/** Exact filenames, matched anywhere in the tree. */
const SKIP_FILES = [
    // secrets and machine-bound state
    '.env', '.env.bak', '.env.backup', '.env.production', 'license.lic', '.machine_fp',
    // the developer licence toggle must NEVER reach a client, even by accident
    'licence-off.key',
    // developer tooling
    'phpunit.xml', '.phpunit.result.cache', '.gitignore', '.gitattributes',
    '.editorconfig', 'auth.json', 'composer.lock.bak',
    // front-end build tooling — the console is self-contained, nothing to build
    'package.json', 'package-lock.json', 'vite.config.js', 'tailwind.config.js',
    'postcss.config.js',
    // a stray 0-byte artefact in the product root. It is listed in SKIP_DIRS too, but
    // it is a FILE, so it slipped through and reached the first client build.
    'individual',
];

/** Filename patterns (fnmatch), matched anywhere. No batch files, ever. */
const SKIP_PATTERNS = ['*.bat', '*.sh', '*.command', '*.fuse_hidden*', '*.commit.txt', 'build-log.txt'];

/**
 * Directories this script OWNS. In --sync mode, anything inside these that is no
 * longer in the source is deleted, so a file removed from the product disappears
 * from the client repo too. Everything else in the target — .git, .env, storage
 * contents, vendor, license.lic — is never touched.
 */
const OWNED_DIRS = ['app', 'config', 'database', 'public', 'resources', 'routes'];

/** Root files this script owns in --sync mode. */
const OWNED_ROOT_FILES = ['artisan', 'composer.json', 'composer.lock', '.env.example',
    'BUILD-INFO.txt', 'README.md'];

/** Empty folders the client's Laravel needs in order to boot. */
const RUNTIME_DIRS = [
    'storage/app/private',
    'storage/app/public',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'bootstrap/cache',
];

// ---------------------------------------------------------------------------

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Cannot resolve the project root.\n");
    exit(1);
}

$argvRest  = array_slice($argv, 1);
$sync      = in_array('--sync', $argvRest, true);
$force     = in_array('--force', $argvRest, true) || $sync;
$noVendor  = in_array('--no-vendor', $argvRest, true);
$positional = array_values(array_filter($argvRest, fn ($a) => ! str_starts_with($a, '--')));

$target = resolveTarget($root, $positional[0] ?? null);

echo "SmartEPT — build the on-premise client-side application\n";
echo str_repeat('=', 62), "\n";
echo "  Source : {$root}\n";
echo "  Target : {$target}\n\n";

if (is_dir($target) && ! isEmptyDir($target)) {
    if (! $force) {
        fwrite(STDERR, "Target already exists and is not empty.\n");
        fwrite(STDERR, "Re-run with --sync to refresh it in place (this is the normal case\n");
        fwrite(STDERR, "once the client repo exists), or --force to overwrite without pruning.\n");
        exit(1);
    }
    echo $sync
        ? "  (--sync: refreshing in place; stale files will be pruned)\n\n"
        : "  (--force: writing over the existing folder)\n\n";
}

if (! $noVendor && ! is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/autoload.php is missing.\n");
    fwrite(STDERR, "Run:  composer install --no-dev --optimize-autoloader\n");
    fwrite(STDERR, "…or re-run with --no-vendor if the client will run Composer themselves.\n");
    exit(1);
}

// A vendor/ built WITH dev dependencies would ship phpunit, mockery and friends to the
// client. Catch it here rather than in a customer's folder.
if (! $noVendor) {
    $devMarkers = array_filter(['vendor/phpunit', 'vendor/mockery', 'vendor/fakerphp'],
        fn ($d) => is_dir($root . '/' . $d));

    if ($devMarkers) {
        fwrite(STDERR, "vendor/ contains DEV dependencies (" . implode(', ', $devMarkers) . ").\n");
        fwrite(STDERR, "A client build must not ship those. Do this, then re-run:\n\n");
        fwrite(STDERR, "    composer install --no-dev --optimize-autoloader\n");
        fwrite(STDERR, "    php deployment/make-clientside.php " . $target . " --sync\n");
        fwrite(STDERR, "    composer install                       (restore your dev tools)\n\n");
        fwrite(STDERR, "Or pass --no-vendor and let the client run Composer themselves.\n");
        fwrite(STDERR, "Use --allow-dev-vendor to override (not for a real handover).\n");

        if (! in_array('--allow-dev-vendor', $argvRest, true)) {
            exit(1);
        }
        fwrite(STDERR, "  (--allow-dev-vendor: continuing anyway)\n\n");
    }
}

$stats = ['files' => 0, 'bytes' => 0, 'skipped' => 0, 'pruned' => 0];
copyTree($root, $target, $root, $noVendor, $stats);

if ($sync) {
    pruneStale($root, $target, $noVendor, $stats);
}

foreach (RUNTIME_DIRS as $d) {
    @mkdir($target . '/' . $d, 0775, true);
}

writeBuildInfo($root, $target, $stats, $noVendor);
writeReadme($target);

echo "\n", str_repeat('=', 62), "\n";
echo "  Done.\n";
echo '  Copied  : ' . number_format($stats['files']) . ' files, ' . human($stats['bytes']) . "\n";
echo '  Skipped : ' . number_format($stats['skipped']) . " (dev files, our runtime data, secrets)\n";
if ($sync) {
    echo '  Pruned  : ' . number_format($stats['pruned']) . " (removed from the product since last sync)\n";
}
if ($noVendor) {
    echo "  vendor/ : NOT included — the client must run composer install.\n";
}
echo "\n  Next: encode whatever you choose under {$target}" . DIRECTORY_SEPARATOR . "app,\n";
echo "  then hand the folder over. Install steps are in README.md inside it.\n";

// ---------------------------------------------------------------------------

function resolveTarget(string $root, ?string $arg): string
{
    $parent = dirname($root);

    if ($arg === null || $arg === '') {
        return $parent . DIRECTORY_SEPARATOR . 'smartept-clientside';
    }

    // A bare name (no slash, no drive letter) means a per-client folder.
    if (! str_contains($arg, '/') && ! str_contains($arg, '\\') && ! preg_match('/^[A-Za-z]:/', $arg)) {
        return $parent . DIRECTORY_SEPARATOR . 'smartept-clientside-' . $arg;
    }

    return rtrim($arg, '/\\');
}

function isEmptyDir(string $dir): bool
{
    foreach (scandir($dir) ?: [] as $e) {
        if ($e !== '.' && $e !== '..') {
            return false;
        }
    }

    return true;
}

function relPath(string $root, string $path): string
{
    return str_replace('\\', '/', ltrim(substr($path, strlen($root)), '/\\'));
}

function skipDir(string $rel): bool
{
    foreach (SKIP_DIRS as $d) {
        if ($rel === $d || str_starts_with($rel, $d . '/')) {
            return true;
        }
    }

    return false;
}

function skipFile(string $name): bool
{
    if (in_array($name, SKIP_FILES, true)) {
        return true;
    }

    foreach (SKIP_PATTERNS as $p) {
        if (fnmatch($p, $name)) {
            return true;
        }
    }

    return false;
}

function copyTree(string $srcDir, string $dstDir, string $root, bool $noVendor, array &$stats): void
{
    if (! is_dir($dstDir) && ! @mkdir($dstDir, 0775, true) && ! is_dir($dstDir)) {
        fwrite(STDERR, "Cannot create {$dstDir}\n");
        exit(1);
    }

    foreach (scandir($srcDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $src = $srcDir . DIRECTORY_SEPARATOR . $entry;
        $rel = relPath($root, $src);

        if (is_link($src)) {          // never follow symlinks (public/storage)
            $stats['skipped']++;
            continue;
        }

        if (is_dir($src)) {
            if (skipDir($rel) || ($noVendor && $rel === 'vendor')) {
                $stats['skipped']++;
                continue;
            }
            if ($rel === 'vendor') {
                echo "  vendor/ … (this is the slow part)\n";
            } elseif (substr_count($rel, '/') === 0) {
                echo "  {$rel}/\n";
            }
            copyTree($src, $dstDir . DIRECTORY_SEPARATOR . $entry, $root, $noVendor, $stats);
            continue;
        }

        if (skipFile($entry)) {
            $stats['skipped']++;
            continue;
        }

        if (! @copy($src, $dstDir . DIRECTORY_SEPARATOR . $entry)) {
            fwrite(STDERR, "  ! could not copy {$rel}\n");
            continue;
        }

        $stats['files']++;
        $stats['bytes'] += (int) @filesize($src);
    }
}

function writeBuildInfo(string $root, string $target, array $stats, bool $noVendor): void
{
    $sha = '(not a git checkout)';
    if (is_dir($root . '/.git') && function_exists('exec')) {
        $out = [];
        @exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>&1', $out, $rc);
        if ($rc === 0 && ! empty($out[0])) {
            $sha = trim($out[0]);
        }
    }

    $body = "SmartEPT — on-premise client-side build\n"
        . str_repeat('-', 46) . "\n"
        . 'Built     : ' . date('Y-m-d H:i:s') . "\n"
        . 'Source    : ' . $root . "\n"
        . 'Commit    : ' . $sha . "\n"
        . 'PHP (build machine): ' . PHP_VERSION . "\n"
        . 'Files     : ' . $stats['files'] . "\n"
        . 'vendor    : ' . ($noVendor ? 'excluded' : 'included') . "\n"
        . "Encoded   : no — encode under app/ before handover if required.\n";

    file_put_contents($target . DIRECTORY_SEPARATOR . 'BUILD-INFO.txt', $body);
}

function writeReadme(string $target): void
{
    $body = <<<'MD'
# SmartEPT — on-premise installation

This folder is the complete application. There is nothing to build and no
internet connection is required at any point.

## Requirements

- PHP 8.2 or 8.3 with: `pdo_mysql`, `openssl`, `mbstring`, `curl`, `zip`, `gd`, `fileinfo`
- MySQL 8 or MariaDB
- A web server whose document root is this folder's `public` directory
  (Apache, Nginx, or IIS with the CGI and URL Rewrite modules)

## Install

1. Copy this folder onto the server.

2. Create the database:

       CREATE DATABASE smartept CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

3. Create `.env` from the template and fill in the database details and `APP_URL`:

       cp .env.example .env

   For an on-premise install these two must be set:

       SMARTEPT_ONPREM=true
       SMARTEPT_LICENCE_ENFORCE=true

4. From this folder:

       php artisan key:generate --force
       php artisan migrate --force
       php artisan db:seed --class=RolePermissionSeeder --force
       php artisan storage:link

5. Make `storage` and `bootstrap/cache` writable by the web server user.
   On Linux: `chmod -R ug+rw storage bootstrap/cache`

6. Create the company and its first administrator:

       php artisan smartept:client-provision

7. Point the web server at the `public` directory. On IIS, turn **WebDAV off** —
   it intercepts PUT and DELETE, which makes every Save and Delete return
   HTTP 405 while the rest of the console appears to work.

8. Open `http://<server>/admin` and sign in.

## Licensing

Open `http://<server>/activate`. The page shows this machine's fingerprint.
Send it to Ametecs; we issue a `.lic` file locked to that machine, which you
upload on the same page. The licence file is not included in this folder.

## Do not run

    php artisan optimize
    php artisan route:cache

The application registers closure-based routes, which cannot be cached — these
commands will break the site. Use `php artisan optimize:clear` instead.

## Support

Ametecs India — support@ametecsindia.com
MD;

    file_put_contents($target . DIRECTORY_SEPARATOR . 'README.md', $body . "\n");
}

function human(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($u) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 1) . ' ' . $u[$i];
}

/**
 * --sync: delete anything under OWNED_DIRS / OWNED_ROOT_FILES that no longer exists in
 * the source, so the client repo is a true mirror and a `git status` there shows exactly
 * what this release changed. Never touches .git, .env, storage, vendor or license.lic.
 */
function pruneStale(string $root, string $target, bool $noVendor, array &$stats): void
{
    foreach (OWNED_ROOT_FILES as $f) {
        $t = $target . DIRECTORY_SEPARATOR . $f;
        if (is_file($t) && ! is_file($root . DIRECTORY_SEPARATOR . $f) && ! in_array($f, ['BUILD-INFO.txt', 'README.md'], true)) {
            @unlink($t);
            $stats['pruned']++;
        }
    }

    foreach (OWNED_DIRS as $d) {
        $tDir = $target . DIRECTORY_SEPARATOR . $d;
        if (! is_dir($tDir)) {
            continue;
        }
        pruneDir($root . DIRECTORY_SEPARATOR . $d, $tDir, $stats);
    }
}

function pruneDir(string $srcDir, string $dstDir, array &$stats): void
{
    foreach (scandir($dstDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
            continue;
        }

        $dst = $dstDir . DIRECTORY_SEPARATOR . $entry;
        $src = $srcDir . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($dst)) {
            if (! is_dir($src)) {
                rmTree($dst, $stats);
                continue;
            }
            pruneDir($src, $dst, $stats);
            continue;
        }

        // A file that is gone from the source, or one the exclusion rules now reject
        // (e.g. a .bat that used to ship), must not linger in the client repo.
        if (! is_file($src) || skipFile($entry)) {
            @unlink($dst);
            $stats['pruned']++;
        }
    }
}

function rmTree(string $dir, array &$stats): void
{
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . DIRECTORY_SEPARATOR . $e;
        if (is_dir($p)) {
            rmTree($p, $stats);
        } else {
            @unlink($p);
            $stats['pruned']++;
        }
    }
    @rmdir($dir);
}
