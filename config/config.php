<?php
/**
 * Global configuration
 * GPS-Enabled QR Code-Based Attendance System for Educational Institutions
 * Strathmore University | BBIT 4B | Reg. No. 158667
 */

/* ------------------------- Application ------------------------- */
define('APP_NAME',    'GPS-Enabled QR Code-Based Attendance System');
define('APP_SHORT',   'QR Attend');
define('APP_ROOT',    dirname(__DIR__));
define('TIMEZONE',    'Africa/Nairobi');
define('DEBUG_MODE',  true);          // set to false before the defence/demo

/* ------------------------- Database ------------------------- */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'gps_qr_attendance');
define('DB_USER', 'root');
define('DB_PASS', '');                // XAMPP default MySQL root password is empty

/* ------------------------- QR code ------------------------- */
define('QR_TOKEN_TTL',     60);       // seconds a QR token stays valid, then it rotates
define('QR_GRACE_SECONDS', 15);       // tolerance for slow phone cameras / clock skew

/* ------------------------- Geofence ------------------------- */
define('DEFAULT_GEOFENCE_RADIUS', 50);  // metres (Zandbergen & Barbeau, 2011)
define('LATE_AFTER_MINUTES',      15);  // scan later than this = 'late'

/* ------------------------- Optional ------------------------- */
/* Leave empty for automatic detection. Set only if automatic detection fails,
   e.g. define('BASE_URL_OVERRIDE', 'http://192.168.1.5/gps_qr_attendance'); */
define('BASE_URL_OVERRIDE', '');
