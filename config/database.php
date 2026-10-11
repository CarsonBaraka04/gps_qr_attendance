<?php
/**
 * Database connection (PDO, singleton).
 * Every query in this project uses prepared statements.
 */

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        /* Align the database clock with the PHP clock. Without this, MySQL can run
           on a different time zone and then NOW() would disagree with the PHP
           timestamps used for QR expiry times and late detection. */
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } catch (PDOException $e) {
        http_response_code(500);
        $detail = DEBUG_MODE ? $e->getMessage() : 'Check that Apache and MySQL are running in XAMPP.';
        echo '<div style="font-family:system-ui;padding:24px;border-left:5px solid #C0392B;background:#FDEDEC">'
           . '<h3 style="margin:0 0 8px">Database connection failed</h3>'
           . '<p style="margin:0">' . htmlspecialchars($detail, ENT_QUOTES) . '</p>'
           . '<p style="margin:8px 0 0">Database: <code>' . DB_NAME . '</code> on <code>' . DB_HOST . '</code></p>'
           . '</div>';
        exit;
    }

    return $pdo;
}
