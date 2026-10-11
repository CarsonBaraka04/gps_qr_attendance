<?php
/**
 * Single entry point for every page: configuration, session, database, helpers.
 * Every page starts with:  require_once __DIR__ . '/../includes/bootstrap.php';
 */

require_once dirname(__DIR__) . '/config/config.php';

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

date_default_timezone_set(TIMEZONE);
ini_set('default_charset', 'UTF-8');

/* Session hardening */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('QRAttendSID');
    session_start();
}

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/includes/geolocation.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/queries.php';
