<?php
/**
 * Apply GUGU account model:
 * - management (role_id 1??) vs member (role_id 4)
 * - Buyer/Seller only on listings & chat (not role_id)
 *
 * Run: http://localhost/gugu-app/database/migrations/migrate_account_model.php
 */
require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: text/html; charset=utf-8');
$db = getDB();
$msgs = [];

function columnExists(PDO $db, string $table, string $col): bool {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $stmt = $db->query('SHOW COLUMNS FROM `' . $safeTable . '` LIKE ' . $db->quote($col));
    return (bool) $stmt->fetch();
}

// account_kind: management | member
if (!columnExists($db, 'users', 'account_kind')) {
    $db->exec("
        ALTER TABLE users
        ADD COLUMN account_kind ENUM('management','member') NOT NULL DEFAULT 'member'
        COMMENT 'management = staff portals; member = marketplace buyers/sellers'
        AFTER role_id
    ");
    $msgs[] = 'Added users.account_kind';
}

// Keep role_id comment accurate
try {
    $db->exec("
        ALTER TABLE users
        MODIFY COLUMN role_id TINYINT UNSIGNED NOT NULL DEFAULT 4
        COMMENT '1 Super Admin, 2 Regional Manager, 3 Moderator/Support, 4 Member'
    ");
    $msgs[] = 'Updated role_id comment';
} catch (Throwable $e) {
    $msgs[] = 'role_id comment: ' . $e->getMessage();
}

// Sync account_kind from role_id
$n = $db->exec("
    UPDATE users SET account_kind = CASE
        WHEN role_id BETWEEN 1 AND 3 THEN 'management'
        ELSE 'member'
    END
");
$msgs[] = "Synced account_kind ({$n} rows)";

// Ensure new OTP users get member role explicitly on future inserts (default already 4)
$msgs[] = 'Model ready: management (1??) ??portals 路 member (4) ??marketplace 路 buyer/seller = deal roles';

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>GUGU Account Model</title>';
echo '<style>body{font-family:system-ui;max-width:640px;margin:40px auto;padding:0 16px}li{margin:8px 0}.ok{color:#20603D}</style></head><body>';
echo '<h1>Account model applied</h1><ul class="ok">';
foreach ($msgs as $m) {
    echo '<li>' . htmlspecialchars($m) . '</li>';
}
echo '</ul>';
echo '<p><strong>Flow</strong></p><pre style="background:#F2F3F6;padding:12px;border-radius:8px;font-size:13px">
Login ??users.role_id
  1 ??Super Admin Dashboard
  2 ??Regional Manager portal
  3 ??Moderator / Support portal
  4 ??Member marketplace
       ?斺? own listing = Seller 路 contact seller = Buyer
</pre>';
echo '<p><a href="/gugu-app/seed_roles.php">Re-seed demo users</a> 路 <a href="/gugu-app/app/?login=1">Login</a></p>';
echo '</body></html>';
