<?php
/**
 * GUGU App - Database Configuration
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'GUGUapDB'); // GUGU App database — does NOT use ikaze databases
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'GUGU App');
define('APP_TAGLINE', 'GuraCyangwaGurisha - 3G Market');
define('APP_URL', 'http://localhost/gugu-app');
define('UPLOAD_DIR', __DIR__ . '/../public/uploads/');
define('UPLOAD_URL', '/gugu-app/public/uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('SESSION_LIFETIME', 60 * 60 * 24 * 30); // 30 days
