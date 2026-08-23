<?php
/**
 * Ensure system_settings exists (Super Admin platform flags).
 * Run once: http://localhost/gugu-app/database/migrations/migrate_system_settings.php
 */
require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();
    $db->exec("
      CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(60) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB
    ");
    $db->exec("
      INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
      ('require_listing_approval', '0'),
      ('escrow_enabled', '1'),
      ('momo_sandbox', '1'),
      ('platform_fee_percent', '0'),
      ('maintenance_mode', '0')
    ");
    // Keep account_kind aligned with role_id
    $n = $db->exec("
      UPDATE users SET account_kind = CASE
        WHEN role_id BETWEEN 1 AND 3 THEN 'management'
        ELSE 'member'
      END
    ");
    $count = (int) $db->query('SELECT COUNT(*) FROM system_settings')->fetchColumn();
    echo "OK system_settings={$count} account_kind_synced={$n}\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'FAIL ' . $e->getMessage() . "\n";
}
