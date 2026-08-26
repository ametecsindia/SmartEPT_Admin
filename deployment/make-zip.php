<?php

/**
 * Build a ZIP from a staged folder, using PHP's own ZipArchive.
 *
 *   php deployment/make-zip.php <source-dir> <output.zip> [<root-name-inside-zip>]
 *
 * WHY THIS EXISTS (19-Aug-2026)
 * `rebuild-server-zip.bat` used to shell out to `tar -a -c -f`, and on a real build machine that
 * failed twice over:
 *
 *   1. `tar -a -c -f "C:\...\out.zip"` → "Cannot connect to C: resolve failed".
 *      bsdtar reads any argument containing a colon as host:path (scp style) and has no
 *      --force-local.
 *   2. Worse, once that was worked around, `tar` on the build machine turned out to be GNU tar
 *      (Cmder / Git-for-Windows put it on PATH ahead of Windows' own System32\tar.exe), and
 *      **GNU tar's -a only understands COMPRESSION suffixes** — .gz, .bz2, .xz. Given a .zip
 *      suffix it silently writes a plain TAR file named .zip. No error, no warning; the client
 *      is the one who finds out.
 *
 * Whichever `tar` is first on PATH is not something a release should depend on. PHP is already a
 * hard requirement of the build script, ext-zip is already required by phpoffice/phpspreadsheet,
 * and ZipArchive produces a real zip identically on every machine. So the tar and PowerShell
 * branches are gone and this is the one code path.
 */

$src = $argv[1] ?? null;
$out = $argv[2] ?? null;
$rootName = $argv[3] ?? null;

if ($src === null || $out === null) {
    fwrite(STDERR, "usage: php deployment/make-zip.php <source-dir> <output.zip> [<root-name>]\n");
    exit(2);
}

if (! class_exists(\ZipArchive::class)) {
    fwrite(STDERR, "PHP's zip extension is not enabled, so the package cannot be built.\n");
    fwrite(STDERR, "Enable extension=zip in php.ini (Laragon: Menu > PHP > php.ini).\n");
    exit(2);
}

$src = realpath($src);
if ($src === false || ! is_dir($src)) {
    fwrite(STDERR, "Source folder not found: {$argv[1]}\n");
    exit(2);
}
$rootName = $rootName ?: basename($src);

// ZipArchive writes through a temp file next to the destination; without this the failure is a
// bare "Failure to create temporary file", which says nothing about the missing folder.
$outDir = dirname($out);
if (! is_dir($outDir) && ! @mkdir($outDir, 0777, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Output folder does not exist and could not be created: {$outDir}\n");
    exit(2);
}
if (! is_writable($outDir)) {
    fwrite(STDERR, "Output folder is not writable: {$outDir}\n");
    exit(2);
}

// Build to a temp name beside the destination and rename at the end, so an interrupted run
// can never leave a half-written archive sitting where the portal serves it.
$partial = $out . '.partial';
@unlink($partial);
@unlink($out);

$zip = new \ZipArchive();
if ($zip->open($partial, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create {$partial}\n");
    exit(1);
}

$files = 0;
$dirs = 0;
$bytes = 0;
$sinceFlush = 0;

$it = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::SELF_FIRST
);

foreach ($it as $item) {
    $rel = substr($item->getPathname(), strlen($src) + 1);
    $entry = $rootName . '/' . str_replace('\\', '/', $rel);

    if ($item->isDir()) {
        // Explicit entries so EMPTY runtime folders survive — storage/logs, bootstrap/cache and
        // friends must exist in the extracted tree or Laravel will not boot on the client.
        $zip->addEmptyDir($entry);
        $dirs++;
        continue;
    }
    if (! $item->isFile()) {
        continue;                       // symlink / other — nothing a package should carry
    }

    if (! $zip->addFile($item->getPathname(), $entry)) {
        fwrite(STDERR, "Failed to add {$rel}\n");
        $zip->close();
        @unlink($partial);
        exit(1);
    }
    $files++;
    $bytes += $item->getSize();
    $sinceFlush++;

    // ZipArchive holds every added file open until close(). At ~10,000 files that risks the
    // per-process handle limit, so commit periodically: close, reopen, carry on.
    if ($sinceFlush >= 500) {
        if ($zip->close() !== true) {
            fwrite(STDERR, "Failed while writing the archive after {$files} files.\n");
            @unlink($partial);
            exit(1);
        }
        if ($zip->open($partial) !== true) {
            fwrite(STDERR, "Could not reopen {$partial} to continue.\n");
            exit(1);
        }
        $sinceFlush = 0;
        echo '.';                       // one dot per 500 files, so a long build looks alive
    }
}

if ($zip->close() !== true) {
    fwrite(STDERR, "Failed to finalise the archive.\n");
    @unlink($partial);
    exit(1);
}

echo "\n";

// Prove it before it is given the real name: a file that cannot be reopened as a zip must never
// reach the downloads folder, whatever produced it.
$check = new \ZipArchive();
if ($check->open($partial) !== true) {
    fwrite(STDERR, "The archive just written is not readable as a ZIP.\n");
    @unlink($partial);
    exit(1);
}
$entries = $check->numFiles;
$check->close();

if (! @rename($partial, $out)) {
    fwrite(STDERR, "Could not move the finished archive to {$out}\n");
    exit(1);
}

printf(
    "  %d files + %d folders (%.1f MB uncompressed) -> %d entries, %.1f MB zipped\n",
    $files,
    $dirs,
    $bytes / 1048576,
    $entries,
    (filesize($out) ?: 0) / 1048576
);

exit(0);
