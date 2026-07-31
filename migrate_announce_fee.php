<?php
/**
 * Announce fee (1000 RWF) + payment tracking columns.
 * New posts stay pending until Admin approves (after payment).
 */
require __DIR__ . '/includes/helpers.php';

$db = getDB();

function addCol(PDO $db, string $sql): void {
    try {
        $db->exec($sql);
        echo "OK: $sql\n";
    } catch (Throwable $e) {
        echo "SKIP/EXISTS: " . $e->getMessage() . "\n";
    }
}

addCol($db, "ALTER TABLE listings ADD COLUMN announce_fee_rwf INT NOT NULL DEFAULT 1000 AFTER moderation_status");
addCol($db, "ALTER TABLE listings ADD COLUMN payment_status ENUM('unpaid','paid','waived') NOT NULL DEFAULT 'unpaid' AFTER announce_fee_rwf");
addCol($db, "ALTER TABLE listings ADD COLUMN payment_note VARCHAR(255) DEFAULT NULL AFTER payment_status");
addCol($db, "ALTER TABLE listings ADD COLUMN paid_at DATETIME DEFAULT NULL AFTER payment_note");

// Existing live listings: treat as already paid + approved so marketplace stays visible
$db->exec("
  UPDATE listings
  SET payment_status = 'waived',
      announce_fee_rwf = 1000
  WHERE moderation_status = 'approved'
    AND (payment_status = 'unpaid' OR payment_status IS NULL OR payment_status = '')
");

echo "Done. Announce fee ready.\n";
