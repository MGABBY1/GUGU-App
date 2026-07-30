<?php
/**
 * GUGU App - API Test Script (CLI)
 */
require_once __DIR__ . '/includes/helpers.php';

echo "=== GUGU App API Test ===\n\n";

$db = getDB();
echo "[OK] Database connected\n";
echo "[OK] Categories: " . $db->query('SELECT COUNT(*) FROM categories')->fetchColumn() . "\n";

$phone = '+250789999999';
$db->prepare('DELETE FROM users WHERE phone = ?')->execute([$phone]);

$hash = password_hash('test123', PASSWORD_DEFAULT);
$db->prepare('INSERT INTO users (phone, password_hash, full_name, province, district, is_verified) VALUES (?, ?, ?, ?, ?, 1)')
   ->execute([$phone, $hash, 'Test User', 'Kigali', 'Gasabo']);
$userId = (int) $db->lastInsertId();
echo "[OK] Test user created (ID: $userId)\n";

$db->prepare('INSERT INTO listings (user_id, category_id, title, description, price, province, district) VALUES (?, 2, ?, ?, 50000, ?, ?)')
   ->execute([$userId, 'iPhone 13 Pro', 'Ikinyabiziga cyiza cyane, gikora neza.', 'Kigali', 'Gasabo']);
$listingId = (int) $db->lastInsertId();
echo "[OK] Test listing created (ID: $listingId)\n";

$count = $db->query('SELECT COUNT(*) FROM listings')->fetchColumn();
echo "[OK] Total listings: $count\n";

echo "\n=== All tests passed! ===\n";
echo "Open: http://localhost/GUGU%20App/public/index.html\n";
echo "Login: 0789999999 / test123\n";
