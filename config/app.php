<?php
/**
 * Application bootstrap / constants
 * This file MUST be the first require in every entry point.
 */

// ── Root path ───────────────────────────────────────────────────────────────
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}

// ── Load .env from project root (sgir_php directory) ────────────────────────
$_env_file = ROOT . '/.env';
if (file_exists($_env_file)) {
    $lines = file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments and blank lines
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        // Only process lines that contain '='
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Remove surrounding quotes if present
        if (
            strlen($value) >= 2 &&
            (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            $_ENV[$key]  = $value;
            putenv("{$key}={$value}");
        }
    }
}
unset($_env_file, $lines, $line, $key, $value);

// ── Base URL (auto-detect) ───────────────────────────────────────────────────
if (!defined('BASE_URL')) {
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Path to the sgir_php directory relative to document root
    $script   = $_SERVER['SCRIPT_NAME'] ?? '';
    // Walk up from the script path until we reach the sgir_php directory
    $base     = '';
    $parts    = explode('/', trim($script, '/'));
    $path     = ROOT;
    $docroot  = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($docroot && str_starts_with($path, $docroot)) {
        $rel  = substr($path, strlen($docroot));
        $base = rtrim($rel, '/');
    }
    define('BASE_URL', $scheme . '://' . $host . $base);
}

// ── App meta ────────────────────────────────────────────────────────────────
define('APP_NAME',    getenv('COMPANY_NAME')    ?: 'SGIR RIGS');
define('APP_TAGLINE', getenv('COMPANY_TAGLINE') ?: 'Oil Rig Feedback Portal');

// ── Upload directory ────────────────────────────────────────────────────────
define('UPLOAD_DIR', ROOT . '/uploads/');

// ── Asset URL ───────────────────────────────────────────────────────────────
define('ASSET_URL', BASE_URL . '/assets');

// ── Timezone ────────────────────────────────────────────────────────────────
date_default_timezone_set('Africa/Lagos');

// ── Error reporting (disable display in production) ─────────────────────────
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
