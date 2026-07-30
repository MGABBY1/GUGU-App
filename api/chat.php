<?php
/**
 * GUGU App - Chat & Messages API
 */

require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['ok' => true]);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'rooms';

switch ($action) {
    case 'rooms':
        if ($method === 'GET') getRooms();
        elseif ($method === 'POST') createRoom();
        else jsonError('Method not allowed', 405);
        break;
    case 'messages':
        $roomId = (int) ($_GET['room_id'] ?? 0);
        if ($method === 'GET') getMessages($roomId);
        elseif ($method === 'POST') sendMessage($roomId);
        else jsonError('Method not allowed', 405);
        break;
    default:
        jsonError('Invalid action', 404);
}

function getRooms(): void {
    $user = requireAuth();
    $db = getDB();

    $stmt = $db->prepare('
        SELECT cr.*, 
               l.title as listing_title, l.price, l.status as listing_status,
               (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as listing_image,
               CASE WHEN cr.buyer_id = ? THEN seller.full_name ELSE buyer.full_name END as other_name,
               CASE WHEN cr.buyer_id = ? THEN seller.avatar ELSE buyer.avatar END as other_avatar,
               CASE WHEN cr.buyer_id = ? THEN seller.id ELSE buyer.id END as other_id,
               (SELECT content FROM messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) as last_message,
               (SELECT COUNT(*) FROM messages WHERE room_id = cr.id AND sender_id != ? AND is_read = 0) as unread_count
        FROM chat_rooms cr
        JOIN listings l ON l.id = cr.listing_id
        JOIN users buyer ON buyer.id = cr.buyer_id
        JOIN users seller ON seller.id = cr.seller_id
        WHERE cr.buyer_id = ? OR cr.seller_id = ?
        ORDER BY cr.last_message_at DESC
    ');
    $uid = $user['id'];
    $stmt->execute([$uid, $uid, $uid, $uid, $uid, $uid]);
    $rooms = $stmt->fetchAll();

    foreach ($rooms as &$room) {
        $room['price_formatted'] = formatPrice((int) $room['price']);
        if ($room['listing_image']) {
            $room['listing_image'] = UPLOAD_URL . $room['listing_image'];
        }
        $room['time_ago'] = timeAgo($room['last_message_at']);
    }

    jsonResponse(['success' => true, 'rooms' => $rooms]);
}

function createRoom(): void {
    $user = requireAuth();
    $data = getJsonInput();
    $listingId = (int) ($data['listing_id'] ?? 0);

    if (!$listingId) jsonError('Listing ID required');

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM listings WHERE id = ?');
    $stmt->execute([$listingId]);
    $listing = $stmt->fetch();

    if (!$listing) jsonError('Igicuruzwa ntikibonetse', 404);
    if ($listing['user_id'] == $user['id']) jsonError('Ntushobora kwiyandikisha ku gicuruzwa cyawe');

    $stmt = $db->prepare('SELECT id FROM chat_rooms WHERE listing_id = ? AND buyer_id = ?');
    $stmt->execute([$listingId, $user['id']]);
    $existing = $stmt->fetch();

    if ($existing) {
        jsonResponse(['success' => true, 'room_id' => $existing['id']]);
        return;
    }

    $db->prepare('INSERT INTO chat_rooms (listing_id, buyer_id, seller_id) VALUES (?, ?, ?)')
       ->execute([$listingId, $user['id'], $listing['user_id']]);

    $db->prepare('UPDATE listings SET chat_count = chat_count + 1 WHERE id = ?')->execute([$listingId]);

    jsonResponse(['success' => true, 'room_id' => (int) $db->lastInsertId()], 201);
}

function getMessages(int $roomId): void {
    $user = requireAuth();
    $db = getDB();

    $stmt = $db->prepare('SELECT * FROM chat_rooms WHERE id = ? AND (buyer_id = ? OR seller_id = ?)');
    $stmt->execute([$roomId, $user['id'], $user['id']]);
    if (!$stmt->fetch()) jsonError('Ubutumwa ntibubonetse', 404);

    $db->prepare('UPDATE messages SET is_read = 1 WHERE room_id = ? AND sender_id != ?')
       ->execute([$roomId, $user['id']]);

    $stmt = $db->prepare('
        SELECT m.*, u.full_name as sender_name, u.avatar as sender_avatar
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.room_id = ?
        ORDER BY m.created_at ASC
    ');
    $stmt->execute([$roomId]);
    $messages = $stmt->fetchAll();

    foreach ($messages as &$msg) {
        $msg['is_mine'] = $msg['sender_id'] == $user['id'];
        $msg['time_ago'] = timeAgo($msg['created_at']);
    }

    jsonResponse(['success' => true, 'messages' => $messages]);
}

function sendMessage(int $roomId): void {
    $user = requireAuth();
    $data = getJsonInput();
    $content = trim($data['content'] ?? '');

    if (empty($content)) jsonError('Ubutumwa ntibushobora kuba ubusa');

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM chat_rooms WHERE id = ? AND (buyer_id = ? OR seller_id = ?)');
    $stmt->execute([$roomId, $user['id'], $user['id']]);
    if (!$stmt->fetch()) jsonError('Ubutumwa ntibubonetse', 404);

    $db->prepare('INSERT INTO messages (room_id, sender_id, content) VALUES (?, ?, ?)')
       ->execute([$roomId, $user['id'], $content]);

    $db->prepare('UPDATE chat_rooms SET last_message_at = NOW() WHERE id = ?')->execute([$roomId]);

    jsonResponse(['success' => true, 'message' => 'Ubutumwa bwoherejwe'], 201);
}
