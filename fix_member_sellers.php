<?php
/**
 * Move marketplace listings off SuperAdmin onto a real member seller,
 * so chats show member nicknames (Karrot-style), not SuperAdmin.
 */
require __DIR__ . '/includes/helpers.php';

$db = getDB();

$member = $db->query("SELECT id, nickname, district, sector FROM users WHERE role_id = 4 AND account_kind = 'member' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$member) {
    // fallback: any role 4
    $member = $db->query('SELECT id, nickname, district, sector FROM users WHERE role_id = 4 ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
}
if (!$member) {
    echo "No member user found.\n";
    exit(1);
}

$sellerId = (int) $member['id'];
echo "Member seller: #{$sellerId} {$member['nickname']}\n";

// Reassign listings owned by management (role_id 1-3)
$stmt = $db->prepare("
    UPDATE listings l
    JOIN users u ON u.id = l.user_id
    SET l.user_id = ?
    WHERE u.role_id IN (1, 2, 3)
");
$stmt->execute([$sellerId]);
echo "Listings reassigned: " . $stmt->rowCount() . "\n";

// Fix chat rooms: seller should be listing owner (member)
$db->exec("
    UPDATE chat_rooms cr
    JOIN listings l ON l.id = cr.listing_id
    SET cr.seller_id = l.user_id
    WHERE cr.seller_id != l.user_id
");
echo "Chat rooms seller synced.\n";

// Ensure member seller has a neighbourhood for meet-up display
if (empty($member['district'])) {
    $db->prepare('UPDATE users SET district = ?, sector = ?, province = ? WHERE id = ?')
       ->execute(['Gasabo', 'Remera', 'Kigali', $sellerId]);
    echo "Seller location set to Gasabo / Remera\n";
}

$count = $db->query('SELECT COUNT(*) FROM listings WHERE user_id = ' . $sellerId)->fetchColumn();
echo "Listings now owned by member: {$count}\n";
echo "Done.\n";
