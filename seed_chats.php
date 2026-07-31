<?php
/**
 * Seed demo chat rooms so My GUGU / Chat tab has visible conversations (Karrot-style).
 * Safe to re-run: skips rooms that already exist for the same listing+buyer.
 */
require __DIR__ . '/includes/helpers.php';

$db = getDB();

$sellerId = 1; // SuperAdmin owns demo listings
$buyers = [2, 4, 7]; // MUNYANEZA, TestUser, DemoMember

$listings = $db->query('SELECT id, title FROM listings WHERE status = "active" ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
if (!$listings) {
    echo "No active listings — nothing to seed.\n";
    exit(0);
}

$samples = [
    ['Muraho! Iki gicuruzwa kiracyariho?', 'Yego, kiracyariho. Uri he?', 'Ndi i Remera. Twahura ryari?'],
    ['Igiciro gishobora kugabanuka?', 'Twashobora kuvugana kuri 5%.', 'OK, ndaza ejo.'],
    ['Still available?', 'Yes — cash only.', 'Great, see you at the market.'],
];

$created = 0;
$i = 0;
foreach ($listings as $listing) {
    $buyerId = $buyers[$i % count($buyers)];
    if ((int) $buyerId === (int) $sellerId) {
        $i++;
        continue;
    }

    $check = $db->prepare('SELECT id FROM chat_rooms WHERE listing_id = ? AND buyer_id = ?');
    $check->execute([$listing['id'], $buyerId]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $roomId = (int) $existing['id'];
    } else {
        $db->prepare('INSERT INTO chat_rooms (listing_id, buyer_id, seller_id, last_message_at) VALUES (?, ?, ?, NOW())')
           ->execute([$listing['id'], $buyerId, $sellerId]);
        $roomId = (int) $db->lastInsertId();
        $db->prepare('UPDATE listings SET chat_count = chat_count + 1 WHERE id = ?')->execute([$listing['id']]);
        $created++;
    }

    $cstmt = $db->prepare('SELECT COUNT(*) FROM messages WHERE room_id = ?');
    $cstmt->execute([$roomId]);
    $msgCount = (int) $cstmt->fetchColumn();

    if ($msgCount === 0) {
        $thread = $samples[$i % count($samples)];
        $pair = [
            [$buyerId, $thread[0]],
            [$sellerId, $thread[1]],
            [$buyerId, $thread[2]],
        ];
        $ins = $db->prepare('INSERT INTO messages (room_id, sender_id, content, is_read, created_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))');
        foreach ($pair as $idx => [$sender, $text]) {
            $ins->execute([$roomId, $sender, $text, $sender === $sellerId ? 0 : 1, $idx]);
        }
        $db->prepare('UPDATE chat_rooms SET last_message_at = NOW() WHERE id = ?')->execute([$roomId]);
    }

    echo "Room #{$roomId} listing={$listing['title']} buyer={$buyerId}\n";
    $i++;
}

echo "Done. New rooms created: {$created}\n";
