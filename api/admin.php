<?php
/**
 * GUGU App - Admin API (backend dashboard)
 */

require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['ok' => true]);
}

$user = requireAuth();

// Admin phones allowed to access backend
$adminPhones = ['+250789999999'];
if (!in_array($user['phone'], $adminPhones, true)) {
    jsonError('Ntufite uburenganzira bwo kwinjira hano', 403);
}

$action = $_GET['action'] ?? 'stats';
$db = getDB();

switch ($action) {
    case 'stats':
        jsonResponse([
            'success' => true,
            'stats' => [
                'users' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                'listings' => (int) $db->query('SELECT COUNT(*) FROM listings')->fetchColumn(),
                'active_listings' => (int) $db->query('SELECT COUNT(*) FROM listings WHERE status = "active"')->fetchColumn(),
                'sold_listings' => (int) $db->query('SELECT COUNT(*) FROM listings WHERE status = "sold"')->fetchColumn(),
                'messages' => (int) $db->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
                'chat_rooms' => (int) $db->query('SELECT COUNT(*) FROM chat_rooms')->fetchColumn(),
            ]
        ]);
        break;

    case 'listings':
        $stmt = $db->query('
            SELECT l.*, u.full_name as seller_name, u.phone as seller_phone, c.name_rw as category_name,
                   (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as primary_image
            FROM listings l
            JOIN users u ON u.id = l.user_id
            JOIN categories c ON c.id = l.category_id
            ORDER BY l.created_at DESC
            LIMIT 100
        ');
        $listings = $stmt->fetchAll();
        foreach ($listings as &$l) {
            $l['price_formatted'] = formatPrice((int) $l['price']);
            if ($l['primary_image']) $l['primary_image'] = UPLOAD_URL . $l['primary_image'];
        }
        jsonResponse(['success' => true, 'listings' => $listings]);
        break;

    case 'users':
        $stmt = $db->query('
            SELECT id, full_name, phone, province, district, manner_score, manner_count, is_verified, created_at,
                   (SELECT COUNT(*) FROM listings WHERE user_id = users.id) as listing_count
            FROM users ORDER BY created_at DESC LIMIT 100
        ');
        jsonResponse(['success' => true, 'users' => $stmt->fetchAll()]);
        break;

    case 'delete-listing':
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) jsonError('ID required');
        $imgStmt = $db->prepare('SELECT image_path FROM listing_images WHERE listing_id = ?');
        $imgStmt->execute([$id]);
        foreach ($imgStmt->fetchAll() as $img) {
            $path = UPLOAD_DIR . $img['image_path'];
            if (file_exists($path)) unlink($path);
        }
        $db->prepare('DELETE FROM listings WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Listing deleted']);
        break;

    default:
        jsonError('Invalid action', 404);
}
