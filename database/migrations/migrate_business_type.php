<?php
/**
 * Separate marketplace Items and Jobs in the database.
 * Adds listings.business_type = item | job and backfills from category_id.
 */
require __DIR__ . '/includes/helpers.php';

$db = getDB();
const JOB_CATEGORY_ID = 11;

echo "Migrating listings.business_type …\n";

$cols = $db->query('SHOW COLUMNS FROM listings')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('business_type', $cols, true)) {
    $db->exec("
        ALTER TABLE listings
        ADD COLUMN business_type ENUM('item','job') NOT NULL DEFAULT 'item'
        AFTER category_id
    ");
    echo "Added column business_type\n";
} else {
    echo "Column business_type already exists\n";
}

// Backfill from category (Jobs = category 11)
$updatedJobs = $db->exec('UPDATE listings SET business_type = "job" WHERE category_id = ' . (int) JOB_CATEGORY_ID);
$updatedItems = $db->exec('UPDATE listings SET business_type = "item" WHERE category_id <> ' . (int) JOB_CATEGORY_ID . ' OR category_id IS NULL');
echo "Backfilled jobs≈{$updatedJobs}, items≈{$updatedItems}\n";

// Index for Admin queues / earnings
try {
    $db->exec('CREATE INDEX idx_listings_business_mod ON listings (business_type, moderation_status, payment_status)');
    echo "Index idx_listings_business_mod created\n";
} catch (Throwable $e) {
    echo "Index note: " . $e->getMessage() . "\n";
}

foreach ($db->query('SELECT business_type, COUNT(*) c FROM listings GROUP BY business_type') as $r) {
    echo "{$r['business_type']} = {$r['c']}\n";
}
echo "DONE\n";
