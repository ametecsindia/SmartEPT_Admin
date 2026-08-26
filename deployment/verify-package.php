<?php

/**
 * Verify a SmartEPT server/on-prem package BEFORE it reaches a client.
 *
 *   php deployment/verify-package.php                 # check this working tree
 *   php deployment/verify-package.php --dist <path>   # check a staged/extracted install tree
 *   php deployment/verify-package.php --zip <file>    # check a built .zip without extracting it
 *   php deployment/verify-package.php --quick         # skip the vendor file-by-file diff
 *
 * WHY THIS EXISTS (19-Aug-2026)
 * A client install of SmartEPT-Admin-Server-Setup-1.0.zip died during `artisan migrate` with
 *
 *   file_get_contents(...\vendor\symfony\translation/Resources/data/parents.json):
 *   Failed to open stream: No such file or directory
 *
 * The packaging script was innocent — `vendor/symfony/translation/Resources/data/` was already
 * missing from the source tree, so `composer install` had produced an incomplete vendor and every
 * build since inherited it. Nothing in the build or the test suite could see that, because a
 * missing *data* file inside a third-party package breaks nothing until the one code path that
 * reads it runs — which here was an error being rendered, so it also masked the original error.
 *
 * Checks performed:
 *   1. VENDOR INTEGRITY — every installed package is diffed, file by file, against the dist
 *      archive Composer cached when it installed it. This is the check that catches the bug
 *      above, for every package, without touching the network.
 *   2. TRANSLATOR SMOKE TEST — forces the exact Symfony code path that crashed, in a subprocess.
 *   3. NOTHING CONFIDENTIAL SHIPS (--dist / --zip) — our docs, tests, DB backups, stored
 *      screenshots, machine fingerprint, build-machine bootstrap caches and commit scratch
 *      files must not be inside a client package.
 *   4. THE APPLICATION IS COMPLETE (--dist / --zip) — artisan, the front controller, the
 *      migrations, the runtime folders Laravel needs to boot.
 *
 * Exit code 0 = safe to ship. Non-zero = do not ship. Plain PHP on purpose: the same command
 * runs on the Windows boxes we build on and the Linux ones we occasionally do.
 */

// ---------------------------------------------------------------------------
// Things that must never be inside a client package.
// Kept deliberately in step with deployment/make-clientside.php's SKIP_* lists.
// ---------------------------------------------------------------------------

/**
 * Paths that must not appear, anchored at the APPLICATION ROOT.
 * Anchored on purpose: `vendor/doctrine/inflector/docs/` and `vendor/**\/tests/` are somebody
 * else's and are perfectly legitimate — stripping vendor tests would break the classmap. Only
 * OUR docs/ and tests/ are the problem.
 */
const FORBIDDEN_ROOT_PATHS = [
    'docs/'                 => 'our sales deck, bugfix reports and QA reports',
    'tests/'                => 'the test suite (DevLicenceKeyTest documents the licence bypass)',
    'storage/app/backups/'  => 'OUR DATABASE BACKUPS',
    'storage/app/smartept/' => "another customer's stored screenshots and webcam photos",
    'storage/app/evidence/' => 'stored violation evidence',
    'storage/app/tmp/'      => 'our scratch files',
];

/** Paths that must not appear ANYWHERE, including inside vendor. */
const FORBIDDEN_ANYWHERE = [
    '_cloudsync' => 'internal sync scratch',
    '_to_delete' => 'internal scratch',
    '/.git/'     => 'git metadata',
];

/**
 * Directories the client's Laravel needs in order to boot, but which must arrive EMPTY.
 * Shipping their contents leaks the build machine's compiled views, logs and package caches —
 * and a stale bootstrap/cache is live during `artisan migrate`, because INSTALL.bat does not
 * reach `optimize:clear` until step 6. Only .gitignore / .gitkeep are allowed inside.
 */
const MUST_BE_EMPTY_DIRS = [
    'bootstrap/cache'            => "the BUILD machine's package and service caches",
    'storage/logs'               => 'our application log',
    'storage/framework/cache'    => "the build machine's cache",
    'storage/framework/sessions' => "the build machine's sessions",
    'storage/framework/views'    => 'compiled Blade views from the build machine',
    'storage/app/private'        => 'employee archive ZIPs and stored media',
    'storage/app/public'         => 'stored media',
];

/** Exact filenames, anywhere in the tree. Any hit is a hard failure. */
const FORBIDDEN_FILES = [
    '.env'             => 'our credentials',
    '.env.bak'         => 'our credentials',
    '.env.backup'      => 'our credentials',
    '.env.production'  => 'our credentials',
    'license.lic'      => 'our licence file',
    '.machine_fp'      => "the BUILD machine's fingerprint (the dev licence is bound to it)",
    'licence-off.key'  => 'the developer licence-enforcement OFF key',
    'auth.json'        => 'Composer credentials',
    // Build tooling. NOTE deployment/install-helper.php is NOT here — INSTALL.bat and
    // install-linux.sh both run it, so it has to ship.
    'make-clientside.php'    => 'our client-build generator',
    'verify-package.php'     => 'this verifier',
    'make-zip.php'           => 'our packaging script',
    'rebuild-server-zip.bat' => 'our packaging script',
    'RUN-BUILD-LOGGED.bat'   => 'our packaging script',
    'INSTALL-GUIDE.md'       => 'our internal install/build notes',
];

/** Filename patterns (fnmatch), anywhere. */
const FORBIDDEN_PATTERNS = [
    'commit-*.txt'   => 'commit-message scratch files',
    'commit-*.php'   => 'commit helper scripts',
    'commit-*.bat'   => 'commit helper scripts',
    '*.commit.txt'   => 'commit-message scratch files',
    '*.fuse_hidden*' => 'editor scratch files',
    'build-log.txt'  => 'our build log',
    '*.lnk'          => 'Windows shortcuts (they point at paths on OUR machine)',
];

/** Files a client package cannot boot without. Paths relative to the app root. */
const REQUIRED_FILES = [
    'artisan',
    'composer.json',
    'bootstrap/app.php',
    'public/index.php',
    'config/app.php',
    'routes/web.php',
    'routes/api.php',
    'vendor/autoload.php',
    'vendor/composer/installed.json',
    'resources/views/admin.blade.php',
];

/** Directories a client package cannot boot without. */
const REQUIRED_DIRS = [
    'app', 'config', 'database/migrations', 'database/seeders', 'public', 'resources/views',
    'routes', 'vendor', 'storage/framework/cache', 'storage/framework/sessions',
    'storage/framework/views', 'storage/logs', 'bootstrap/cache',
];

/**
 * Load-bearing NON-PHP files inside vendor. These are the ones that break nothing at install
 * time and everything later, so a plain "does the package folder exist" check misses them.
 * The vendor diff (check 1) supersedes this list; it stays as the floor for when the Composer
 * cache is unavailable — on a build machine that has just been rebuilt, for instance.
 */
const CRITICAL_VENDOR_FILES = [
    // 19-Aug-2026: the file whose absence broke a client install.
    'vendor/symfony/translation/Resources/data/parents.json',
];

// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
$quick = in_array('--quick', $args, true);
$zip = optionValue($args, '--zip');
$dist = optionValue($args, '--dist');

$root = realpath(__DIR__ . '/..');
$problems = [];
$warnings = [];
$checks = 0;

echo "SmartEPT — verify package\n", str_repeat('=', 62), "\n";

if ($zip !== null) {
    // -------- ZIP MODE: list-based checks against an already-built archive ----------
    $zipPath = realpath($zip) ?: $zip;
    if (! is_file($zipPath)) {
        fwrite(STDERR, "No such file: {$zip}\n");
        exit(2);
    }
    echo "  Archive : {$zipPath}\n\n";

    $names = zipEntries($zipPath);
    if ($names === null) {
        fwrite(STDERR, "Could not read {$zipPath} as a ZIP archive.\n");
        fwrite(STDERR, "Either PHP's zip extension is disabled, or the file is not really a zip —\n");
        fwrite(STDERR, "GNU tar's -a only understands COMPRESSION suffixes, so `tar -a -c -f x.zip`\n");
        fwrite(STDERR, "on a machine where `tar` is GNU rather than the Windows bsdtar writes a plain\n");
        fwrite(STDERR, "tar file that merely ends in .zip. Do not publish it.\n");
        exit(2);
    }
    // The package wraps the application in a single folder, and the app sits under app/.
    $rel = array_map(fn ($n) => stripPackagePrefix(str_replace('\\', '/', $n)), $names);
    echo '  Entries : ' . count($rel) . "\n\n";

    $checks += checkForbidden($rel, $problems);
    $checks += checkRequiredFromList($rel, $problems);
    $checks += checkCriticalVendorFromList($rel, $problems);
    $warnings[] = 'ZIP mode cannot run the translator smoke test or diff vendor against the '
        . 'Composer cache. Run --dist against the staged tree for the full check.';
} else {
    // -------- TREE MODE ----------
    $app = $dist !== null ? (realpath($dist) ?: $dist) : $root;
    // An extracted install package wraps the application in app/ — accept either shape.
    if (! is_file($app . '/artisan') && is_file($app . '/app/artisan')) {
        $app .= '/app';
    }
    if (! is_file($app . '/artisan')) {
        fwrite(STDERR, "Not an application tree (no artisan): {$app}\n");
        exit(2);
    }
    echo "  Tree    : {$app}\n";
    echo '  Mode    : ' . ($dist !== null ? 'client package (strict)' : 'working tree') . "\n\n";

    $checks += checkCriticalVendorFiles($app, $problems);
    $checks += checkTranslatorSmokeTest($app, $problems);

    if (! $quick) {
        $checks += checkVendorAgainstComposerCache($app, $problems, $warnings);
    } else {
        $warnings[] = '--quick: the file-by-file vendor diff was skipped.';
    }

    if ($dist !== null) {
        $rel = treeEntries($app);
        $checks += checkForbidden($rel, $problems);
        $checks += checkRequiredFromList($rel, $problems);
    }
}

// ---------------------------------------------------------------------------

echo "\n", str_repeat('-', 62), "\n";
foreach ($warnings as $w) {
    echo "  note: {$w}\n";
}
if (! $problems) {
    echo "\n  PASS — {$checks} checks, no problems found.\n";
    exit(0);
}

echo "\n  FAIL — " . count($problems) . " problem(s) found in {$checks} checks:\n\n";
foreach ($problems as $i => $p) {
    echo '  ' . ($i + 1) . ") {$p}\n";
}
echo "\n  Do not ship this package.\n";
echo "  A damaged vendor tree is repaired with, in the project folder:\n";
echo "      composer clear-cache\n";
echo "      composer install --no-dev --optimize-autoloader\n\n";
exit(1);

// ---------------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------------

/**
 * THE check. Composer keeps the dist archive of every package it installs. Diffing the
 * installed folder against that archive finds any file that went missing after extraction —
 * which is exactly how parents.json disappeared — for every package, offline.
 */
function checkVendorAgainstComposerCache(string $app, array &$problems, array &$warnings): int
{
    $installedJson = $app . '/vendor/composer/installed.json';
    if (! is_file($installedJson)) {
        $problems[] = 'vendor/composer/installed.json is missing — vendor was never installed here.';
        return 1;
    }

    $cacheDir = composerFilesCacheDir();
    if ($cacheDir === null) {
        $warnings[] = "Composer's file cache was not found, so vendor could not be diffed "
            . 'package by package. Only the critical-file list was checked.';
        return 0;
    }
    echo "  Composer cache: {$cacheDir}\n";

    $data = json_decode((string) file_get_contents($installedJson), true);
    $packages = $data['packages'] ?? $data;
    if (! is_array($packages)) {
        $problems[] = 'vendor/composer/installed.json is not readable as JSON.';
        return 1;
    }

    $checked = 0;
    $uncached = 0;
    foreach ($packages as $pkg) {
        $name = $pkg['name'] ?? null;
        if (! $name) {
            continue;
        }
        $dir = $app . '/vendor/' . $name;
        if (! is_dir($dir)) {
            $problems[] = "vendor/{$name} is missing entirely (listed in installed.json).";
            continue;
        }

        $archive = newestCachedArchive($cacheDir, $name);
        if ($archive === null) {
            $uncached++;
            continue;
        }

        $expected = zipEntries($archive);
        if ($expected === null) {
            $uncached++;
            continue;
        }

        $missing = [];
        foreach ($expected as $entry) {
            if (str_ends_with($entry, '/')) {
                continue;                       // directory marker
            }
            $path = stripFirstSegment(str_replace('\\', '/', $entry));
            if ($path === '' || $path === null || isBuildMetadata($path)) {
                continue;
            }
            if (! is_file($dir . '/' . $path)) {
                $missing[] = $path;
            }
        }

        $checked++;
        if ($missing) {
            $shown = array_slice($missing, 0, 4);
            $more = count($missing) - count($shown);
            $problems[] = "vendor/{$name} is INCOMPLETE — " . count($missing)
                . ' file(s) present in the installed archive are missing on disk: '
                . implode(', ', $shown) . ($more > 0 ? " (+{$more} more)" : '');
        }
    }

    echo '  (CI config and editor dotfiles inside packages are not counted as missing.)' . "\n";
    echo "  Packages diffed against the cache: {$checked}"
        . ($uncached ? " ({$uncached} not in the cache — not verified)" : '') . "\n";
    if ($uncached > 0) {
        $warnings[] = "{$uncached} package(s) had no cached archive, so their files were not "
            . 'verified. `composer clear-cache` then `composer install` repopulates the cache.';
    }

    return max(1, $checked);
}

/** The floor: named files that break a client weeks after install if they go missing. */
function checkCriticalVendorFiles(string $app, array &$problems): int
{
    foreach (CRITICAL_VENDOR_FILES as $rel) {
        if (! is_file($app . '/' . $rel)) {
            $problems[] = "{$rel} is missing. Symfony reads it whenever it resolves a fallback "
                . 'locale, so its absence turns any error into an unreadable ErrorException '
                . '(this is the 19-Aug-2026 client install failure).';
        }
    }
    return count(CRITICAL_VENDOR_FILES);
}

/**
 * Force the exact code path that failed on the client, in a subprocess so a platform-check
 * abort inside the packaged autoloader is reported rather than killing this script.
 */
function checkTranslatorSmokeTest(string $app, array &$problems): int
{
    $autoload = $app . '/vendor/autoload.php';
    if (! is_file($autoload)) {
        $problems[] = 'vendor/autoload.php is missing.';
        return 1;
    }

    // Written to a temp FILE rather than passed with `php -r`. On Windows escapeshellarg()
    // replaces embedded double quotes with spaces and cmd re-splits the trailing argument, so
    // `-r '...$argv[1]...' <path>` arrived with no argv[1] at all and the smoke test reported
    // its own breakage as a package failure. A file has neither problem, on either OS.
    $script = "<?php\n"
        . 'require ' . var_export($autoload, true) . ";\n"
        . '$t = new Symfony\Component\Translation\Translator(' . var_export('en_GB', true) . ");\n"
        . '$t->setFallbackLocales([' . var_export('en', true) . "]);\n"
        // getCatalogue() walks computeFallbackLocales(), which reads Resources/data/parents.json.
        . '$t->getCatalogue(' . var_export('en_GB', true) . ");\n"
        . 'echo ' . var_export('SMOKE_OK', true) . ";\n";

    $tmp = tempnam(sys_get_temp_dir(), 'ept_smoke_') ;
    if ($tmp === false || @file_put_contents($tmp . '.php', $script) === false) {
        $problems[] = 'Could not write the translator smoke test to a temp file, so the package '
            . 'was NOT smoke-tested.';
        return 1;
    }
    @unlink($tmp);
    $tmp .= '.php';

    $cmd = escapeshellarg(PHP_BINARY) . ' -d error_reporting=E_ALL ' . escapeshellarg($tmp) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    @unlink($tmp);

    if (! str_contains($out, 'SMOKE_OK')) {
        $problems[] = "Translator smoke test FAILED — this package will break at runtime:\n     "
            . trim(preg_replace('/\s+/', ' ', $out) ?? '') ;
    }

    return 1;
}

/** Nothing of ours may be inside a client package. */
function checkForbidden(array $rel, array &$problems): int
{
    foreach (FORBIDDEN_ROOT_PATHS as $needle => $why) {
        $hits = array_values(array_filter($rel, fn ($p) => str_starts_with($p, $needle)));
        if ($hits) {
            $problems[] = 'Package contains ' . count($hits) . " path(s) under \"{$needle}\" — {$why}. "
                . 'First: ' . $hits[0];
        }
    }
    foreach (FORBIDDEN_ANYWHERE as $needle => $why) {
        $hits = array_values(array_filter($rel, fn ($p) => str_contains('/' . $p, $needle)));
        if ($hits) {
            $problems[] = 'Package contains ' . count($hits) . " path(s) matching \"{$needle}\" — {$why}. "
                . 'First: ' . $hits[0];
        }
    }
    foreach (FORBIDDEN_FILES as $file => $why) {
        $hits = array_values(array_filter($rel, fn ($p) => basename($p) === $file));
        if ($hits) {
            $problems[] = "Package contains {$file} — {$why}. First: " . $hits[0];
        }
    }
    foreach (FORBIDDEN_PATTERNS as $pattern => $why) {
        $hits = array_values(array_filter($rel, fn ($p) => fnmatch($pattern, basename($p))));
        if ($hits) {
            $problems[] = 'Package contains ' . count($hits) . " file(s) matching {$pattern} — {$why}. "
                . 'First: ' . $hits[0];
        }
    }
    foreach (MUST_BE_EMPTY_DIRS as $dir => $why) {
        $payload = array_values(array_filter($rel, function ($p) use ($dir) {
            if (! str_starts_with($p, $dir . '/') || str_ends_with($p, '/')) {
                return false;                       // not in here, or a directory marker
            }
            return ! in_array(basename($p), ['.gitignore', '.gitkeep'], true);
        }));
        if ($payload) {
            $problems[] = "{$dir}/ must ship EMPTY but contains " . count($payload)
                . " file(s) — {$why}. First: " . $payload[0];
        }
    }
    return count(FORBIDDEN_ROOT_PATHS) + count(FORBIDDEN_ANYWHERE) + count(FORBIDDEN_FILES)
        + count(FORBIDDEN_PATTERNS) + count(MUST_BE_EMPTY_DIRS);
}

function checkRequiredFromList(array $rel, array &$problems): int
{
    $set = array_flip($rel);
    foreach (REQUIRED_FILES as $f) {
        if (! isset($set[$f])) {
            $problems[] = "Required file is missing from the package: {$f}";
        }
    }
    foreach (REQUIRED_DIRS as $d) {
        $found = false;
        foreach ($rel as $p) {
            if (str_starts_with($p, $d . '/') || $p === $d . '/' || $p === $d) {
                $found = true;
                break;
            }
        }
        if (! $found) {
            $problems[] = "Required directory is missing from the package: {$d}/ "
                . '(Laravel will not boot without it)';
        }
    }
    // The migration set is what an install actually runs — an empty one means a silent no-op install.
    $migrations = array_filter($rel, fn ($p) => str_starts_with($p, 'database/migrations/') && str_ends_with($p, '.php'));
    if (count($migrations) < 50) {
        $problems[] = 'Only ' . count($migrations) . ' migrations are in the package — expected 80+. '
            . 'The database schema would be incomplete.';
    }
    return count(REQUIRED_FILES) + count(REQUIRED_DIRS) + 1;
}

function checkCriticalVendorFromList(array $rel, array &$problems): int
{
    $set = array_flip($rel);
    foreach (CRITICAL_VENDOR_FILES as $f) {
        if (! isset($set[$f])) {
            $problems[] = "{$f} is missing from the package — this is the 19-Aug-2026 client "
                . 'install failure (migrate dies with "Failed to open stream ... parents.json").';
        }
    }
    return count(CRITICAL_VENDOR_FILES);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function optionValue(array $args, string $flag): ?string
{
    $i = array_search($flag, $args, true);
    if ($i === false) {
        return null;
    }
    return $args[$i + 1] ?? '';
}

/** Entry list of a zip, or null when it cannot be read. */
function zipEntries(string $path): ?array
{
    if (! class_exists(\ZipArchive::class)) {
        return null;
    }
    $z = new \ZipArchive();
    if ($z->open($path) !== true) {
        return null;
    }
    $out = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $out[] = $z->getNameIndex($i);
    }
    $z->close();
    return $out;
}

/** Every file/dir under a tree, relative and forward-slashed. */
function treeEntries(string $root): array
{
    $out = [];
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    $len = strlen($root) + 1;
    foreach ($it as $item) {
        $p = str_replace('\\', '/', substr($item->getPathname(), $len));
        $out[] = $item->isDir() ? $p . '/' : $p;
    }
    return $out;
}

/** "SmartEPT-Admin-Server/app/artisan" -> "artisan"; tolerant of either wrapper being absent. */
function stripPackagePrefix(string $p): string
{
    $parts = explode('/', $p);
    if ($parts && $parts[0] !== '' && ! in_array($parts[0], ['app', 'vendor', 'config', 'public'], true)) {
        array_shift($parts);           // the "SmartEPT-Admin-Server" wrapper folder
    }
    if (($parts[0] ?? null) === 'app' && count($parts) > 1
        && in_array($parts[1] ?? '', ['vendor', 'artisan', 'composer.json', 'bootstrap', 'config',
            'database', 'public', 'resources', 'routes', 'storage', 'docs', 'tests', 'deployment',
            '.env', '.env.example', 'app'], true)) {
        array_shift($parts);           // the inner application folder
    }
    return implode('/', $parts);
}

/**
 * A package's CI config and editor dotfiles are never loaded at runtime, and packagers
 * legitimately strip them. Ignoring them keeps the vendor diff about files that actually
 * matter — otherwise every build reports .github/workflows/*.yml as "incomplete" and the
 * real signal (a missing Resources/data/parents.json) drowns.
 */
function isBuildMetadata(string $path): bool
{
    foreach (['.github/', '.gitlab/', '.circleci/'] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }
    return in_array(basename($path), [
        '.gitattributes', '.gitignore', '.editorconfig', '.travis.yml', '.styleci.yml',
        '.php_cs', '.php_cs.dist', '.php-cs-fixer.php', '.php-cs-fixer.dist.php',
        'phpunit.xml', 'phpunit.xml.dist', 'psalm.xml', 'phpstan.neon', 'phpstan.neon.dist',
    ], true);
}

function stripFirstSegment(string $p): string
{
    $i = strpos($p, '/');
    return $i === false ? '' : substr($p, $i + 1);
}

/** Composer's files cache, asked of Composer itself first, then the usual locations. */
function composerFilesCacheDir(): ?string
{
    $out = @shell_exec('composer config --global --no-interaction cache-files-dir 2>&1');
    if (is_string($out)) {
        $line = trim(strtok($out, "\r\n") ?: '');
        if ($line !== '' && is_dir($line)) {
            return $line;
        }
    }

    $candidates = [];
    if ($h = getenv('COMPOSER_HOME')) {
        $candidates[] = $h . '/cache/files';
    }
    if ($la = getenv('LOCALAPPDATA')) {
        $candidates[] = $la . '/Composer/files';
        $candidates[] = $la . '/Composer/cache/files';
    }
    if ($ap = getenv('APPDATA')) {
        $candidates[] = $ap . '/Composer/cache/files';
    }
    if ($home = getenv('HOME')) {
        $candidates[] = $home . '/.cache/composer/files';
        $candidates[] = $home . '/.composer/cache/files';
    }
    foreach ($candidates as $c) {
        if (is_dir($c)) {
            return $c;
        }
    }
    return null;
}

/** Newest cached dist archive for a package, or null. */
function newestCachedArchive(string $cacheDir, string $package): ?string
{
    $dir = $cacheDir . '/' . $package;
    if (! is_dir($dir)) {
        return null;
    }
    $best = null;
    $bestTime = -1;
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (! is_file($f)) {
            continue;
        }
        $t = filemtime($f) ?: 0;
        if ($t > $bestTime) {
            $bestTime = $t;
            $best = $f;
        }
    }
    return $best;
}
