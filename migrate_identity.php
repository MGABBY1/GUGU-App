<?php
/** One-time identity migration — open once: /gugu-app/migrate_identity.php */
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$cols = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
$alters = [
    'nickname' => "ADD COLUMN nickname VARCHAR(50) DEFAULT NULL AFTER full_name",
    'email' => "ADD COLUMN email VARCHAR(120) DEFAULT NULL AFTER nickname",
    'location_lat' => "ADD COLUMN location_lat DECIMAL(10,8) DEFAULT NULL AFTER sector",
    'location_lng' => "ADD COLUMN location_lng DECIMAL(11,8) DEFAULT NULL AFTER location_lat",
    'location_verified_at' => "ADD COLUMN location_verified_at DATETIME DEFAULT NULL AFTER location_lng",
];
foreach ($alters as $col => $sql) {
    if (!in_array($col, $cols, true)) {
        $db->exec("ALTER TABLE users {$sql}");
        echo "Added users.{$col}\n";
    } else {
        echo "OK users.{$col}\n";
    }
}
try {
    $db->exec('ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL');
    echo "OK password_hash nullable\n";
} catch (Throwable $e) {
    echo "password_hash: already ok\n";
}
$db->exec("
    CREATE TABLE IF NOT EXISTS otp_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(20) NOT NULL,
        code VARCHAR(6) NOT NULL,
        purpose ENUM('login', 'register', 'verify') DEFAULT 'login',
        attempts INT DEFAULT 0,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_otp_phone (phone)
    ) ENGINE=InnoDB
");
echo "OK otp_codes\n";
$db->exec("UPDATE users SET nickname = SUBSTRING_INDEX(full_name, ' ', 1) WHERE nickname IS NULL OR nickname = ''");
echo "OK nickname backfill\n";
echo "DONE — open http://localhost/gugu-app/\n";
