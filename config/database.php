<?php
/**
 * AutoCare Hub — Database Configuration
 * Adjust credentials for your XAMPP/WAMP/Laragon environment.
 */

// Use 127.0.0.1 (not "localhost") on Windows/XAMPP to avoid IPv6/DNS delays
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'autocare_hub');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'AutoCare Hub');
define('APP_URL', ''); // e.g. 'http://localhost/autocarhub'
define('APP_VERSION', '2.2.2');
define('SCHEMA_VERSION', '2.2.0');

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        try {
            $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
            $pdo->exec('SET SESSION wait_timeout=30');
        } catch (Throwable $e) {
            // ignore
        }
    }
    return $pdo;
}
