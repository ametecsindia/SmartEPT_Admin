<?php
/**
 * The product's version number, read and bumped (Ejaz, 1-Sep-2026).
 *
 *   php deployment/version.php read      prints the current version
 *   php deployment/version.php bump      increments it, saves, prints the new one
 *   php deployment/version.php selftest  runs the checks below
 *
 * It lives in its own file rather than as a one-liner inside
 * BUILD-CLIENT-PACKAGE.bat because nested quotes in a cmd `for /f` block are
 * how every other inline snippet in this folder eventually broke.
 *
 * version.json is deliberately the ONLY place the number lives. The running
 * server reports that same file to SmartEPT Central when a client presses
 * "Check for Update", so a package named 1.2 whose version.json still said 1.1
 * would never be offered as an update and would never report having installed.
 */

/**
 * Add one to the last segment, carrying at 10: 1.1 -> 1.2, 1.9 -> 2.0, 1.5.9 -> 1.6.0.
 * The leading segment just keeps counting, so 9.9 -> 10.0 rather than rolling off the end.
 */
function nextVersion(string $version): string
{
    $parts = explode('.', trim($version));
    foreach ($parts as $part) {
        if (! ctype_digit($part)) {
            throw new InvalidArgumentException('"' . $version . '" is not all numbers and dots.');
        }
    }

    for ($i = count($parts) - 1; $i >= 0; $i--) {
        $n = (int) $parts[$i] + 1;
        if ($n < 10 || $i === 0) {
            $parts[$i] = (string) $n;
            break;
        }
        $parts[$i] = '0';                       // carry into the segment before it
    }

    return implode('.', $parts);
}

$mode = $argv[1] ?? 'read';

if ($mode === 'selftest') {
    assert(nextVersion('1.1') === '1.2');
    assert(nextVersion('1.2') === '1.3');
    assert(nextVersion('1.9') === '2.0');       // 8-Sep-2026, Ejaz: after 1.9 comes 2
    assert(nextVersion('1.5.0') === '1.5.1');
    assert(nextVersion('1.5.9') === '1.6.0');
    assert(nextVersion('1.9.9') === '2.0.0');
    assert(nextVersion('9.9') === '10.0');      // the first segment keeps counting
    assert(nextVersion('2') === '3');
    try {
        nextVersion('1.2-beta');
        assert(false, 'a non-numeric tail must be refused');
    } catch (InvalidArgumentException $e) {
    }
    echo "version.php: all checks passed\n";
    exit(0);
}

$file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'version.json';
$data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
$data = is_array($data) ? $data : [];
$version = trim((string) ($data['version'] ?? ''));

if ($version === '') {
    fwrite(STDERR, "version.json is missing or has no \"version\".\n");
    exit(1);
}

if ($mode === 'read') {
    echo $version;
    exit(0);
}

if ($mode !== 'bump') {
    fwrite(STDERR, "usage: version.php read|bump|selftest\n");
    exit(1);
}

try {
    $data['version'] = nextVersion($version);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'Cannot bump the version: ' . $e->getMessage() . "\n");
    exit(1);
}

// Keep the other fields the update client depends on, whatever else is in there.
$data['product'] = $data['product'] ?? 'smartept';
$data['channel'] = $data['channel'] ?? 'stable';

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    fwrite(STDERR, "Could not write version.json.\n");
    exit(1);
}

echo $data['version'];
exit(0);
