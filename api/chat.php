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
    $uid = (int) $user['id'];
    $isMember = ((int) ($user['role_id'] ?? 4) === 4);

    // Members see marketplace chats with members; Jobs Apply chats may include staff posters
    $staffFilter = $isMember
        ? ' AND (
              (CASE WHEN cr.buyer_id = ? THEN seller.role_id ELSE buyer.role_id END) = 4
              OR l.category_id = 11
            ) '
        : '';

    $sql = "
        SELECT cr.*, 
               l.title as listing_title, l.price, l.status as listing_status, l.category_id,
               (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1) as listing_image,
               CASE WHEN cr.buyer_id = ? THEN COALESCE(NULLIF(seller.nickname, ''), seller.full_name)
                    ELSE COALESCE(NULLIF(buyer.nickname, ''), buyer.full_name) END as other_name,
               CASE WHEN cr.buyer_id = ? THEN seller.avatar ELSE buyer.avatar END as other_avatar,
               CASE WHEN cr.buyer_id = ? THEN seller.id ELSE buyer.id END as other_id,
               (SELECT content FROM messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) as last_message,
               (SELECT COUNT(*) FROM messages WHERE room_id = cr.id AND sender_id != ? AND is_read = 0) as unread_count
        FROM chat_rooms cr
        JOIN listings l ON l.id = cr.listing_id
        JOIN users buyer ON buyer.id = cr.buyer_id
        JOIN users seller ON seller.id = cr.seller_id
        WHERE (cr.buyer_id = ? OR cr.seller_id = ?)
        {$staffFilter}
        ORDER BY COALESCE(cr.last_message_at, cr.created_at) DESC
    ";

    $stmt = $db->prepare($sql);
    if ($isMember) {
        $stmt->execute([$uid, $uid, $uid, $uid, $uid, $uid, $uid]);
    } else {
        $stmt->execute([$uid, $uid, $uid, $uid, $uid, $uid]);
    }
    $rooms = $stmt->fetchAll();

    foreach ($rooms as &$room) {
        $room['price_formatted'] = formatPrice((int) $room['price']);
        if ($room['listing_image']) {
            $room['listing_image'] = publicUploadUrl($room['listing_image']);
        }
        $room['time_ago'] = timeAgo($room['last_message_at']);
        $isJob = (int) ($room['category_id'] ?? 0) === 11;
        $isApplicant = ((int) $room['buyer_id'] === $uid);
        $room['my_deal_role'] = $isApplicant ? 'buyer' : 'seller';
        if ($isJob) {
            $room['my_deal_label'] = $isApplicant ? 'Applicant' : 'Poster';
            $room['is_job'] = true;
        } else {
            $room['my_deal_label'] = $isApplicant ? 'Buyer' : 'Seller';
            $room['is_job'] = false;
        }
    }

    jsonResponse(['success' => true, 'rooms' => $rooms]);
}

function createRoom(): void {
    $user = requireAuth();
    $data = getJsonInput();
    $listingId = (int) ($data['listing_id'] ?? 0);

    if (!$listingId) jsonError('Listing ID required');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT l.*, u.role_id as seller_role_id,
               COALESCE(NULLIF(u.nickname, ''), u.full_name) as seller_nick
        FROM listings l
        JOIN users u ON u.id = l.user_id
        WHERE l.id = ?
    ");
    $stmt->execute([$listingId]);
    $listing = $stmt->fetch();

    if (!$listing) jsonError('Igicuruzwa ntikibonetse', 404);
    if ((int) $listing['user_id'] === (int) $user['id']) {
        jsonError('Ntushobora kwiyandikisha ku gicuruzwa cyawe');
    }

    // Members can only buy/chat items in their confirmed stay district
    $isMember = (int) ($user['role_id'] ?? 4) === 4;
    $memberDistrict = $isMember ? trim((string) ($user['district'] ?? '')) : '';
    $listingDistrict = trim((string) ($listing['district'] ?? ''));
    if ($isMember && $memberDistrict !== '' && $listingDistrict !== '' && strcasecmp($memberDistrict, $listingDistrict) !== 0) {
        jsonError('Urashobora kugura / kuvugana gusa mu Akarere hawe. Hindura ahantu mu igenamiterere (GPS).', 403);
    }

    $isJob = (int) ($listing['category_id'] ?? 0) === 11;
    $moderation = $listing['moderation_status'] ?? 'approved';
    if ($moderation !== 'approved') {
        jsonError($isJob
            ? 'This job is not open for applications yet (waiting for Admin approval).'
            : 'This post is not available for chat yet.', 403);
    }

    // Marketplace items: chat with members only.
    // Jobs (Karrot-style Apply): allow chat with the poster even if staff posted the job.
    if (!$isJob && (int) $listing['seller_role_id'] !== 4) {
        jsonError('This item is not available for member chat');
    }

    $stmt = $db->prepare('SELECT id FROM chat_rooms WHERE listing_id = ? AND buyer_id = ?');
    $stmt->execute([$listingId, $user['id']]);
    $existing = $stmt->fetch();

    if ($existing) {
        jsonResponse([
            'success' => true,
            'room_id' => (int) $existing['id'],
            'is_job' => $isJob,
            'existing' => true,
        ]);
        return;
    }

    $db->prepare('INSERT INTO chat_rooms (listing_id, buyer_id, seller_id) VALUES (?, ?, ?)')
       ->execute([$listingId, $user['id'], $listing['user_id']]);

    $db->prepare('UPDATE listings SET chat_count = chat_count + 1 WHERE id = ?')->execute([$listingId]);

    jsonResponse([
        'success' => true,
        'room_id' => (int) $db->lastInsertId(),
        'is_job' => $isJob,
        'existing' => false,
    ], 201);
}

function getMessages(int $roomId): void {
    $user = requireAuth();
    $db = getDB();

    $stmt = $db->prepare("
        SELECT cr.*,
               l.title as listing_title, l.price, l.status as listing_status, l.category_id,
               (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1) as listing_image,
               CASE WHEN cr.buyer_id = ? THEN COALESCE(NULLIF(seller.nickname, ''), seller.full_name)
                    ELSE COALESCE(NULLIF(buyer.nickname, ''), buyer.full_name) END as other_name,
               CASE WHEN cr.buyer_id = ? THEN seller.avatar ELSE buyer.avatar END as other_avatar
        FROM chat_rooms cr
        JOIN listings l ON l.id = cr.listing_id
        JOIN users buyer ON buyer.id = cr.buyer_id
        JOIN users seller ON seller.id = cr.seller_id
        WHERE cr.id = ? AND (cr.buyer_id = ? OR cr.seller_id = ?)
    ");
    $uid = $user['id'];
    $stmt->execute([$uid, $uid, $roomId, $uid, $uid]);
    $room = $stmt->fetch();
    if (!$room) jsonError('Ubutumwa ntibubonetse', 404);

    if ($room['listing_image']) {
        $room['listing_image'] = publicUploadUrl($room['listing_image']);
    }
    $isJob = (int) ($room['category_id'] ?? 0) === 11;
    $isApplicant = ((int) $room['buyer_id'] === (int) $uid);
    $room['my_deal_role'] = $isApplicant ? 'buyer' : 'seller';
    $room['my_deal_label'] = $isJob
        ? ($isApplicant ? 'Applicant' : 'Poster')
        : ($isApplicant ? 'Buyer' : 'Seller');
    $room['is_job'] = $isJob;
    $room['price_formatted'] = formatPrice((int) $room['price']);

    $db->prepare('UPDATE messages SET is_read = 1 WHERE room_id = ? AND sender_id != ?')
       ->execute([$roomId, $user['id']]);

    $stmt = $db->prepare('
        SELECT m.*, u.full_name as sender_name, u.nickname as sender_nickname, u.avatar as sender_avatar
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.room_id = ?
        ORDER BY m.created_at ASC
    ');
    $stmt->execute([$roomId]);
    $messages = $stmt->fetchAll();

    foreach ($messages as &$msg) {
        $msg['is_mine'] = ((int) $msg['sender_id'] === (int) $user['id']);
        $msg['is_read'] = (int) ($msg['is_read'] ?? 0);
        $msg['time_ago'] = timeAgo($msg['created_at']);
        $msg['sender_name'] = $msg['sender_nickname'] ?: $msg['sender_name'];
    }
    unset($msg);

    jsonResponse(['success' => true, 'room' => $room, 'messages' => $messages]);
}

function sendMessage(int $roomId): void {
    $user = requireAuth();
    $data = getJsonInput();
    $content = trim($data['content'] ?? '');

    if ($content === '') {
        jsonErrorKey('fill_all');
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM chat_rooms WHERE id = ? AND (buyer_id = ? OR seller_id = ?)');
    $stmt->execute([$roomId, $user['id'], $user['id']]);
    if (!$stmt->fetch()) {
        jsonErrorKey('generic_error', 404);
    }

    $db->prepare('INSERT INTO messages (room_id, sender_id, content, is_read) VALUES (?, ?, ?, 0)')
       ->execute([$roomId, $user['id'], $content]);
    $msgId = (int) $db->lastInsertId();

    $db->prepare('UPDATE chat_rooms SET last_message_at = NOW() WHERE id = ?')->execute([$roomId]);

    $createdAt = date('Y-m-d H:i:s');
    jsonResponse([
        'success' => true,
        'message' => [
            'id' => $msgId,
            'room_id' => $roomId,
            'sender_id' => (int) $user['id'],
            'content' => $content,
            'is_read' => 0,
            'is_mine' => true,
            'created_at' => $createdAt,
            'time_ago' => timeAgo($createdAt),
        ],
    ], 201);
}
