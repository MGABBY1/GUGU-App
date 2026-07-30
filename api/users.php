<?php
/**
 * GUGU App - Users, Favorites, Categories, Locations API
 */

require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['ok' => true]);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'profile':
        if ($method === 'GET') getProfile();
        elseif ($method === 'PUT') updateProfile();
        else jsonError('Method not allowed', 405);
        break;
    case 'user':
        $userId = (int) ($_GET['id'] ?? 0);
        getUserProfile($userId);
        break;
    case 'favorites':
        handleFavorites($method);
        break;
    case 'categories':
        getCategories();
        break;
    case 'locations':
        getLocations();
        break;
    case 'review':
        if ($method === 'POST') submitReview();
        else jsonError('Method not allowed', 405);
        break;
    case 'report-listing':
        if ($method === 'POST') reportListing();
        else jsonError('Method not allowed', 405);
        break;
    default:
        jsonError('Invalid action', 404);
}

function getProfile(): void {
    $user = requireAuth();
    $db = getDB();

    $stmt = $db->prepare('SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = "active"');
    $stmt->execute([$user['id']]);
    $user['active_listings'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = "sold"');
    $stmt->execute([$user['id']]);
    $user['sold_listings'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $user['favorites_count'] = (int) $stmt->fetchColumn();

    jsonResponse(['success' => true, 'user' => $user]);
}

function updateProfile(): void {
    $user = requireAuth();
    $data = getJsonInput();
    $db = getDB();

    $fields = [];
    $params = [];

    foreach (['full_name', 'bio', 'province', 'district', 'sector'] as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = ?";
            $params[] = trim($data[$field]);
        }
    }

    if (empty($fields)) jsonError('Nta makuru yo guhindura');

    $params[] = $user['id'];
    $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $updated = $stmt->fetch();
    unset($updated['password_hash']);

    jsonResponse(['success' => true, 'user' => $updated]);
}

function getUserProfile(int $userId): void {
    if (!$userId) jsonError('User ID required');

    $db = getDB();
    $stmt = $db->prepare('
        SELECT id, full_name, avatar, province, district, sector, bio,
               manner_score, manner_count, is_verified, created_at
        FROM users WHERE id = ?
    ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) jsonError('Umukoresha ntibonetse', 404);

    $user['member_since'] = timeAgo($user['created_at']);

    $stmt = $db->prepare('
        SELECT l.*, 
               (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as primary_image
        FROM listings l
        WHERE l.user_id = ? AND l.status = "active"
        ORDER BY l.created_at DESC
    ');
    $stmt->execute([$userId]);
    $listings = $stmt->fetchAll();

    foreach ($listings as &$l) {
        $l['price_formatted'] = formatPrice((int) $l['price']);
        if ($l['primary_image']) $l['primary_image'] = UPLOAD_URL . $l['primary_image'];
    }

    jsonResponse(['success' => true, 'user' => $user, 'listings' => $listings]);
}

function handleFavorites(string $method): void {
    $user = requireAuth();
    $db = getDB();

    if ($method === 'GET') {
        $stmt = $db->prepare('
            SELECT l.*, f.created_at as favorited_at,
                   u.full_name as seller_name,
                   (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as primary_image
            FROM favorites f
            JOIN listings l ON l.id = f.listing_id
            JOIN users u ON u.id = l.user_id
            WHERE f.user_id = ?
            ORDER BY f.created_at DESC
        ');
        $stmt->execute([$user['id']]);
        $favorites = $stmt->fetchAll();

        foreach ($favorites as &$f) {
            $f['price_formatted'] = formatPrice((int) $f['price']);
            if ($f['primary_image']) $f['primary_image'] = UPLOAD_URL . $f['primary_image'];
        }

        jsonResponse(['success' => true, 'favorites' => $favorites]);
    }

    if ($method === 'POST') {
        $data = getJsonInput();
        $listingId = (int) ($data['listing_id'] ?? 0);
        if (!$listingId) jsonError('Listing ID required');

        $stmt = $db->prepare('SELECT id FROM favorites WHERE user_id = ? AND listing_id = ?');
        $stmt->execute([$user['id'], $listingId]);

        if ($stmt->fetch()) {
            $db->prepare('DELETE FROM favorites WHERE user_id = ? AND listing_id = ?')
               ->execute([$user['id'], $listingId]);
            $db->prepare('UPDATE listings SET like_count = GREATEST(0, like_count - 1) WHERE id = ?')
               ->execute([$listingId]);
            jsonResponse(['success' => true, 'favorited' => false]);
        } else {
            $db->prepare('INSERT INTO favorites (user_id, listing_id) VALUES (?, ?)')
               ->execute([$user['id'], $listingId]);
            $db->prepare('UPDATE listings SET like_count = like_count + 1 WHERE id = ?')
               ->execute([$listingId]);
            jsonResponse(['success' => true, 'favorited' => true]);
        }
    }
}

function getCategories(): void {
    $db = getDB();
    $stmt = $db->query('SELECT * FROM categories ORDER BY sort_order');
    jsonResponse(['success' => true, 'categories' => $stmt->fetchAll()]);
}

function getLocations(): void {
    jsonResponse(['success' => true, 'locations' => getRwandaLocations()]);
}

/**
 * Member report -> Administrative Portal moderation queue.
 */
function reportListing(): void {
    $user = requireAuth();
    $data = getJsonInput();

    $listingId = (int) ($data['listing_id'] ?? 0);
    $reason = trim($data['reason'] ?? '');
    if (!$listingId || $reason === '') jsonError('Sobanura impamvu yo gutanga raporo');

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM listings WHERE id = ?');
    $stmt->execute([$listingId]);
    if (!$stmt->fetch()) jsonError('Igicuruzwa ntikibonetse', 404);

    $dup = $db->prepare('
        SELECT id FROM reports
        WHERE target_type = "listing" AND target_id = ? AND reporter_id = ? AND status = "open"
    ');
    $dup->execute([$listingId, $user['id']]);
    if ($dup->fetch()) jsonError('Wasanzwe watanze raporo kuri iki gicuruzwa');

    $db->prepare('INSERT INTO reports (reporter_id, target_type, target_id, reason, details) VALUES (?, "listing", ?, ?, ?)')
       ->execute([$user['id'], $listingId, $reason, trim($data['details'] ?? '') ?: null]);

    jsonResponse(['success' => true, 'message' => 'Raporo yoherejwe ku bagenzuzi'], 201);
}

function submitReview(): void {
    $user = requireAuth();
    $data = getJsonInput();

    $reviewedId = (int) ($data['user_id'] ?? 0);
    $listingId = (int) ($data['listing_id'] ?? 0);
    $rating = $data['rating'] ?? '';
    $comment = trim($data['comment'] ?? '');

    if (!$reviewedId || !in_array($rating, ['good', 'bad'])) {
        jsonError('Amakuru atari yo');
    }
    if ($reviewedId == $user['id']) {
        jsonError('Ntushobora kwisuzuma');
    }

    $db = getDB();
    try {
        $db->prepare('INSERT INTO reviews (reviewer_id, reviewed_id, listing_id, rating, comment) VALUES (?, ?, ?, ?, ?)')
           ->execute([$user['id'], $reviewedId, $listingId ?: null, $rating, $comment ?: null]);
        updateMannerScore($reviewedId);
        jsonResponse(['success' => true, 'message' => 'Urwego rwatanzwe neza']);
    } catch (PDOException $e) {
        jsonError('Wasanzwe watanze urwego kuri uyu mukoresha');
    }
}
