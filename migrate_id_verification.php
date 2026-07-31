<?php
/**
 * Member ID verification columns (NIDA / national ID + document).
 * Run: http://localhost/gugu-app/migrate_id_verification.php
 */
require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: text/html; charset=utf-8');
$db = getDB();
$msgs = [];

function addCol(PDO $db, string $sql, string $ok, array &$msgs): void {
    try {
        $db->exec($sql);
        $msgs[] = $ok;
    } catch (Throwable $e) {
        $msgs[] = 'Skip: ' . $e->getMessage();
    }
}

addCol($db, "
  ALTER TABLE users
  ADD COLUMN id_number VARCHAR(20) NULL COMMENT 'NIDA / national ID number'
  AFTER is_verified
", 'Added id_number', $msgs);

addCol($db, "
  ALTER TABLE users
  ADD COLUMN id_document_path VARCHAR(255) NULL COMMENT 'Uploaded ID image'
  AFTER id_number
", 'Added id_document_path', $msgs);

addCol($db, "
  ALTER TABLE users
  ADD COLUMN id_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'
  COMMENT 'ID verification status'
  AFTER id_document_path
", 'Added id_status', $msgs);

addCol($db, "
  ALTER TABLE users
  ADD COLUMN id_verified_at DATETIME NULL
  AFTER id_status
", 'Added id_verified_at', $msgs);

addCol($db, "
  ALTER TABLE users
  ADD COLUMN id_reject_reason VARCHAR(255) NULL
  AFTER id_verified_at
", 'Added id_reject_reason', $msgs);

// Demo / seeded accounts: already trusted for local testing
try {
    $db->exec("
      UPDATE users
      SET id_status = 'approved', id_verified_at = COALESCE(id_verified_at, NOW())
      WHERE role_id BETWEEN 1 AND 3
         OR phone IN ('+250784444444','+250783333333','+250782222222','+250781111111')
    ");
    $msgs[] = 'Demo accounts marked ID-approved for testing';
} catch (Throwable $e) {
    $msgs[] = 'Demo approve skip: ' . $e->getMessage();
}

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>ID verification migrate</title>';
echo '<style>body{font-family:system-ui;max-width:640px;margin:40px auto;padding:0 16px}li{margin:6px 0}</style></head><body>';
echo '<h1>ID verification ready</h1><ul>';
foreach ($msgs as $m) {
    echo '<li>' . htmlspecialchars($m) . '</li>';
}
echo '</ul>';
echo '<p><a href="/gugu-app/app/?login=1">Login</a> · <a href="/gugu-app/admin/dashboard.php">Admin</a></p>';
echo '</body></html>';
