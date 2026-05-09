<?php
/**
 * PDO database connection — supports MySQL (production) and SQLite (local dev)
 * Driver is selected via DB_DRIVER env var: "mysql" (default) or "sqlite"
 *
 * Usage: require_once ROOT . '/config/database.php';
 * After inclusion, $pdo is available as a global.
 */

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}

$db_driver = getenv('DB_DRIVER') ?: 'mysql';

try {
    if ($db_driver === 'sqlite') {
        // ── SQLite (local development) ────────────────────────────────────────
        $db_path = getenv('DB_PATH') ?: ROOT . '/database.sqlite';

        $pdo = new PDO('sqlite:' . $db_path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Enable foreign key enforcement (off by default in SQLite)
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');

    } else {
        // ── MySQL (production) ────────────────────────────────────────────────
        $db_host = getenv('DB_HOST')     ?: 'localhost';
        $db_name = getenv('DB_NAME')     ?: 'sgir_feedback';
        $db_user = getenv('DB_USER')     ?: 'root';
        $db_pass = getenv('DB_PASSWORD') ?: '';
        $db_port = getenv('DB_PORT')     ?: '3306';

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $db_host,
            $db_port,
            $db_name
        );

        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
    }
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die(json_encode(['success' => false, 'message' => 'Database connection unavailable. Please try again later.']));
}
