<?php
// SmartEPT install helper (pure PHP + PDO — no Laravel needed).
// 1) Ensures a .env exists with sane on-premise defaults (MySQL, not the stock SQLite).
// 2) Creates the MySQL/MariaDB database if it does not exist yet.
// Run by INSTALL.bat before key:generate / migrate.

$root = dirname(__DIR__);
$envPath = $root . DIRECTORY_SEPARATOR . '.env';
$examplePath = $root . DIRECTORY_SEPARATOR . '.env.example';

$fresh = false;
if (!file_exists($envPath)) {
    if (!file_exists($examplePath)) {
        fwrite(STDERR, "[env] Neither .env nor .env.example found in $root\n");
        exit(1);
    }
    copy($examplePath, $envPath);
    $fresh = true;
    echo "[env] created .env from .env.example\n";
}

$env = file_get_contents($envPath);

$getv = function ($env, $key, $default = '') {
    if (preg_match('/^\s*' . preg_quote($key, '/') . '=(.*)$/m', $env, $m)) {
        return trim($m[1], " \t\"'");
    }
    return $default;
};
$setv = function (&$env, $key, $value) {
    $line = $key . '=' . $value;
    if (preg_match('/^\s*' . preg_quote($key, '/') . '=.*$/m', $env)) {
        $env = preg_replace('/^\s*' . preg_quote($key, '/') . '=.*$/m', $line, $env);
    } elseif (preg_match('/^\s*#\s*' . preg_quote($key, '/') . '=.*$/m', $env)) {
        $env = preg_replace('/^\s*#\s*' . preg_quote($key, '/') . '=.*$/m', $line, $env);
    } else {
        $env = rtrim($env, "\r\n") . "\n" . $line . "\n";
    }
};

// On-prem runs on MySQL/MariaDB (Laragon). If the stock SQLite (or blank) is set, switch it.
if ($getv($env, 'DB_CONNECTION') !== 'mysql') {
    $setv($env, 'DB_CONNECTION', 'mysql');
    $setv($env, 'DB_HOST', $getv($env, 'DB_HOST', '') ?: '127.0.0.1');
    $setv($env, 'DB_PORT', $getv($env, 'DB_PORT', '') ?: '3306');
    $curDb = $getv($env, 'DB_DATABASE');
    if ($curDb === '' || $curDb === 'laravel') {
        $setv($env, 'DB_DATABASE', 'smartept');
    }
    if ($getv($env, 'DB_USERNAME') === '') {
        $setv($env, 'DB_USERNAME', 'root');
    }
    echo "[env] set DB to MySQL (database '" . $getv($env, 'DB_DATABASE', 'smartept') . "')\n";
}

// A fresh install uses file-based session & cache so the panel can boot BEFORE the
// sessions/cache tables are migrated — removing a common first-run 500.
if ($fresh) {
    $setv($env, 'SESSION_DRIVER', 'file');
    $setv($env, 'CACHE_STORE', 'file');
    echo "[env] fresh install: SESSION_DRIVER=file, CACHE_STORE=file\n";
}

file_put_contents($envPath, $env);

// Create the database if it isn't there yet.
$host = $getv($env, 'DB_HOST', '127.0.0.1');
$port = $getv($env, 'DB_PORT', '3306');
$db   = $getv($env, 'DB_DATABASE', 'smartept');
$user = $getv($env, 'DB_USERNAME', 'root');
$pass = $getv($env, 'DB_PASSWORD', '');

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "[db] PHP extension 'pdo_mysql' is not enabled. Enable it in php.ini and retry.\n");
    exit(2);
}

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "[db] database '{$db}' is ready on {$host}:{$port}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[db] Could not connect / create the database: " . $e->getMessage() . "\n");
    fwrite(STDERR, "     - Is MySQL running? (Laragon -> Start All)\n");
    fwrite(STDERR, "     - Check DB_USERNAME / DB_PASSWORD in .env (Laragon default: root / blank)\n");
    exit(2);
}

echo "[ok] environment prepared.\n";
