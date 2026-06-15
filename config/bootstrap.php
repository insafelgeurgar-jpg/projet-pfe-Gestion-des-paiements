<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ── Load helpers FIRST ────────────────────────────────────────────────────
require BASE_PATH . '/app/helpers/functions.php';

// ── Load .env ────────────────────────────────────────────────────────────
(function () {
    $envFile = BASE_PATH . '/.env';
    if (!file_exists($envFile)) {
        die('Missing .env file. Copy .env.example to .env and configure it.');
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\"'");
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
})();

// ── Timezone ──────────────────────────────────────────────────────────────
date_default_timezone_set(env('APP_TIMEZONE', 'UTC'));

// ── Session ───────────────────────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (env('APP_ENV') === 'production') {
    ini_set('session.cookie_secure', '1');
}
session_start();

// ── PSR-4 Autoloader ──────────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $prefixes = [
        'App\\Config\\' => BASE_PATH . '/config/',
        'App\\'         => APP_PATH . '/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        $len = strlen($prefix);
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, $len)) . '.php';
            if (file_exists($file)) { require $file; return; }
        }
    }
});

// ── Storage directories ───────────────────────────────────────────────────
foreach ([
    BASE_PATH . '/storage/logs',
    BASE_PATH . '/storage/invoices',
    BASE_PATH . '/storage/tmp',
] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

ini_set('error_log', BASE_PATH . '/storage/logs/php_errors.log');