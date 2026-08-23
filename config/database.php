<?php
/**
 * GUGU App - Database Configuration
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'GUGUapDB'); // GUGU App database — does NOT use ikaze databases
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'Gura & Gurisha App');
define('APP_TAGLINE', 'Gura no kugurisha mu Rwanda');
define('APP_URL', 'http://localhost/gugu-app');
define('UPLOAD_DIR', __DIR__ . '/../public/uploads/');
define('UPLOAD_URL', '/gugu-app/public/uploads/');
define('MAX_UPLOAD_SIZE', 12 * 1024 * 1024); // 12MB (phone photos)
define('SESSION_LIFETIME', 60 * 60 * 24 * 30); // 30 days

/** OTP / SMS — set OTP_DEV_MODE false + SMS_API_KEY when using real SMS (Africa's Talking, Twilio) */
define('OTP_DEV_MODE', true);           // true = return OTP in API for local XAMPP testing
define('OTP_LENGTH', 6);
define('OTP_TTL_SECONDS', 300);         // 5 minutes
define('OTP_MAX_ATTEMPTS', 5);
define('LOCATION_VERIFY_DAYS', 30);     // re-verify GPS every 30 days
define('SMS_API_KEY', '');              // optional Africa's Talking / Twilio key
define('SMS_SENDER', 'GuraGuri');
