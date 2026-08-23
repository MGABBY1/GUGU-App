<?php
/**
 * Seed GUGU role demo accounts.
 * Username = phone (078…) · Password = email (role@gugu.rw)
 * Run: http://localhost/gugu-app/seed_roles.php
 */
require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: text/html; charset=utf-8');

$db = getDB();

$cols = $db->query("SHOW COLUMNS FROM users LIKE 'role_id'")->fetch();
if (!$cols) {
    $db->exec("ALTER TABLE users ADD COLUMN role_id TINYINT UNSIGNED NOT NULL DEFAULT 4 AFTER is_verified");
}
$cols = $db->query("SHOW COLUMNS FROM users LIKE 'account_status'")->fetch();
if (!$cols) {
    $db->exec("ALTER TABLE users ADD COLUMN account_status ENUM('active','suspended','banned') NOT NULL DEFAULT 'active' AFTER role_id");
}
$cols = $db->query("SHOW COLUMNS FROM users LIKE 'admin_district'")->fetch();
if (!$cols) {
    $db->exec("ALTER TABLE users ADD COLUMN admin_district VARCHAR(50) NULL AFTER account_status");
}

// Account model: management vs member
$cols = $db->query("SHOW COLUMNS FROM users LIKE 'account_kind'")->fetch();
if (!$cols) {
    $db->exec("
        ALTER TABLE users
        ADD COLUMN account_kind ENUM('management','member') NOT NULL DEFAULT 'member'
        COMMENT 'management = staff portals; member = marketplace'
        AFTER role_id
    ");
}
try {
    $db->exec("
        ALTER TABLE users MODIFY COLUMN role_id TINYINT UNSIGNED NOT NULL DEFAULT 4
        COMMENT '1 System Administrator, 2 District Manager, 3 Moderator/Support, 4 Member'
    ");
} catch (Throwable $e) { /* ignore */ }
$cols = $db->query("SHOW COLUMNS FROM listings LIKE 'moderation_status'")->fetch();
if (!$cols) {
    $db->exec("ALTER TABLE listings ADD COLUMN moderation_status ENUM('approved','pending','flagged','rejected') NOT NULL DEFAULT 'approved' AFTER status");
}

$db->exec("
    CREATE TABLE IF NOT EXISTS reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reporter_id INT NULL,
        target_type ENUM('listing','user','chat') NOT NULL,
        target_id INT NOT NULL,
        reason VARCHAR(100) NOT NULL,
        details TEXT NULL,
        status ENUM('open','reviewing','resolved','dismissed') NOT NULL DEFAULT 'open',
        handled_by INT NULL,
        resolution_note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_reports_status (status)
    ) ENGINE=InnoDB
");
$db->exec("
    CREATE TABLE IF NOT EXISTS admin_audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        actor_id INT NOT NULL,
        action VARCHAR(80) NOT NULL,
        target_type VARCHAR(40) NULL,
        target_id INT NULL,
        meta_json TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_actor (actor_id)
    ) ENGINE=InnoDB
");

$accounts = [
    [
        'phone' => '+250781111111',
        'local' => '0781111111',
        'nickname' => 'SystemAdmin',
        'full_name' => 'GUGU System Administrator',
        'email' => 'Admin@gugu.rw',
        'password' => 'Admin@gugu.rw',
        'role_id' => 1,
        'province' => 'Kigali',
        'district' => 'Gasabo',
        'sector' => 'Remera',
        'admin_district' => null,
        'label' => 'System Administrator',
    ],
    [
        'phone' => '+250782222222',
        'local' => '0782222222',
        'nickname' => 'DistrictManager',
        'full_name' => 'Gasabo District Manager',
        'email' => 'Manager@gugu.rw',
        'password' => 'Manager@gugu.rw',
        'role_id' => 2,
        'province' => 'Kigali',
        'district' => 'Gasabo',
        'sector' => 'Kimironko',
        'admin_district' => 'Gasabo',
        'label' => 'District Manager',
    ],
    [
        'phone' => '+250783333333',
        'local' => '0783333333',
        'nickname' => 'Moderator',
        'full_name' => 'GUGU Moderator Support',
        'email' => 'Support@gugu.rw',
        'password' => 'Support@gugu.rw',
        'role_id' => 3,
        'province' => 'Kigali',
        'district' => 'Kicukiro',
        'sector' => 'Niboye',
        'admin_district' => null,
        'label' => 'Moderator / Support',
    ],
    [
        'phone' => '+250784444444',
        'local' => '0784444444',
        'nickname' => 'DemoMember',
        'full_name' => 'Demo Member',
        'email' => 'Member@gugu.rw',
        'password' => 'Member@gugu.rw',
        'role_id' => 4,
        'province' => 'Kigali',
        'district' => 'Nyarugenge',
        'sector' => 'Nyamirambo',
        'admin_district' => null,
        'label' => 'Member',
    ],
];

// Remove obsolete demo phones/emails so roles do not collide
$oldPhones = ['+250789999999']; // former System Admin demo
$oldEmails = ['super@gugu.rw', 'regional@gugu.rw', 'support@gugu.rw', 'member@gugu.rw'];
try {
    $db->prepare('DELETE FROM users WHERE phone = ?')->execute($oldPhones);
} catch (Throwable $e) { /* ignore */ }
foreach ($oldEmails as $em) {
    try {
        $db->prepare('DELETE FROM users WHERE email = ? AND phone NOT IN (?,?,?,?)')->execute([
            $em,
            '+250781111111', '+250782222222', '+250783333333', '+250784444444',
        ]);
    } catch (Throwable $e) { /* ignore */ }
}

$upsert = $db->prepare('
    INSERT INTO users (phone, password_hash, full_name, nickname, email, province, district, sector, role_id, account_kind, account_status, admin_district, is_verified, location_verified_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, 1, NOW())
    ON DUPLICATE KEY UPDATE
        password_hash = VALUES(password_hash),
        full_name = VALUES(full_name),
        nickname = VALUES(nickname),
        email = VALUES(email),
        province = VALUES(province),
        district = VALUES(district),
        sector = VALUES(sector),
        role_id = VALUES(role_id),
        account_kind = VALUES(account_kind),
        account_status = "active",
        admin_district = VALUES(admin_district),
        is_verified = 1,
        location_verified_at = NOW()
');

foreach ($accounts as $a) {
    // Username = phone · Password = email (as given)
    $hash = password_hash($a['password'], PASSWORD_DEFAULT);
    $kind = ((int) $a['role_id'] >= 1 && (int) $a['role_id'] <= 3) ? 'management' : 'member';
    $upsert->execute([
        $a['phone'], $hash, $a['full_name'], $a['nickname'], $a['email'],
        $a['province'], $a['district'], $a['sector'], $a['role_id'], $kind, $a['admin_district'],
    ]);
}

// Sync any other rows
$db->exec("UPDATE users SET account_kind = IF(role_id BETWEEN 1 AND 3, 'management', 'member')");

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>GUGU Role Seed</title>';
echo '<style>body{font-family:system-ui;max-width:720px;margin:40px auto;padding:0 16px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #eee;padding:10px;text-align:left}th{background:#00A1DE;color:#fff}.ok{color:#20603D;font-weight:700}code{background:#F2F3F6;padding:2px 6px;border-radius:4px}</style></head><body>';
echo '<h1>GUGU roles seeded</h1>';
echo '<p class="ok">Login rule: <strong>Username = phone</strong> · <strong>Password = email</strong></p>';
echo '<p>Management (role 1–3) → portals · Member (role 4) → marketplace · Buyer/Seller = deal only</p>';
echo '<table><tr><th>Role</th><th>Kind</th><th>Username (phone)</th><th>Password (email)</th></tr>';
foreach ($accounts as $a) {
    $kind = ((int) $a['role_id'] <= 3) ? 'management' : 'member';
    echo '<tr><td>' . htmlspecialchars($a['label']) . '</td><td><code>' . $kind . '</code></td><td><code>' . htmlspecialchars($a['local']) . '</code></td><td><code>' . htmlspecialchars($a['email']) . '</code></td></tr>';
}
echo '</table>';
echo '<p><a href="/gugu-app/app/?login=1">Open login</a> · <a href="/gugu-app/app/">Marketplace</a> · <a href="/gugu-app/database/migrations/migrate_account_model.php">Account model migrate</a></p>';
echo '</body></html>';
