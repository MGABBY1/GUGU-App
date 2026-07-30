<?php
/**
 * GUGU App - Notifications (Member Portal bell)
 */

require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['ok' => true]);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        if ($method !== 'GET') jsonError('Method not allowed', 405);
        listNotifications();
        break;
    case 'count':
        if ($method !== 'GET') jsonError('Method not allowed', 405);
        unreadCount();
        break;
    case 'read':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        markRead();
        break;
    case 'read-all':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        markAllRead();
        break;
    default:
        jsonError('Invalid action', 404);
}

function listNotifications(): void {
    $user = requireAuth();
    $stmt = getDB()->prepare('
        SELECT * FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ');
    $stmt->execute([$user['id']]);
    $items = $stmt->fetchAll();
    foreach ($items as &$n) {
        $n['time_ago'] = timeAgo($n['created_at']);
        // link is stored as "order:12" / "listing:5"
        $parts = explode(':', (string) ($n['link'] ?? ''), 2);
        $n['link_type'] = $parts[0] ?? '';
        $n['link_id'] = isset($parts[1]) ? (int) $parts[1] : 0;
    }
    jsonResponse(['success' => true, 'notifications' => $items]);
}

function unreadCount(): void {
    $user = getAuthUser();
    if (!$user) {
        jsonResponse(['success' => true, 'unread' => 0]);
    }
    $stmt = getDB()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$user['id']]);
    jsonResponse(['success' => true, 'unread' => (int) $stmt->fetchColumn()]);
}

function markRead(): void {
    $user = requireAuth();
    $data = getJsonInput();
    $id = (int) ($data['id'] ?? 0);
    if (!$id) jsonError('Notification ID required');

    getDB()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
           ->execute([$id, $user['id']]);
    jsonResponse(['success' => true]);
}

function markAllRead(): void {
    $user = requireAuth();
    getDB()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$user['id']]);
    jsonResponse(['success' => true, 'message' => 'Byose byasomwe']);
}
