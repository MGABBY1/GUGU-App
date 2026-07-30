<?php
/**
 * GUGU App - Listings API
 */

require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['ok' => true]);
}

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($method) {
    case 'GET':
        if ($id) {
            getListing($id);
        } else {
            getListings();
        }
        break;
    case 'POST':
        createListing();
        break;
    case 'PUT':
        if (!$id) jsonError('Listing ID required');
        updateListing($id);
        break;
    case 'DELETE':
        if (!$id) jsonError('Listing ID required');
        deleteListing($id);
        break;
    default:
        jsonError('Method not allowed', 405);
}

function getListings(): void {
    $db = getDB();
    $user = getAuthUser();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    // Default: only active listings on public feed
    if (!empty($_GET['user_id'])) {
        $where[] = 'l.user_id = ?';
        $params[] = (int) $_GET['user_id'];
        if (!empty($_GET['status']) && in_array($_GET['status'], ['active', 'reserved', 'sold'])) {
            $where[] = 'l.status = ?';
            $params[] = $_GET['status'];
        }
    } else {
        $where[] = 'l.status = "active"';
        if (tableHasColumn('listings', 'approval_status')) {
            $where[] = 'l.approval_status = "approved"';
        }
    }

    if (!empty($_GET['category'])) {
        $where[] = 'l.category_id = ?';
        $params[] = (int) $_GET['category'];
    }
    if (!empty($_GET['district'])) {
        $where[] = 'l.district = ?';
        $params[] = $_GET['district'];
    }
    if (!empty($_GET['province'])) {
        $where[] = 'l.province = ?';
        $params[] = $_GET['province'];
    }
    if (!empty($_GET['search'])) {
        $where[] = '(l.title LIKE ? OR l.description LIKE ?)';
        $search = '%' . $_GET['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }
    if (isset($_GET['min_price'])) {
        $where[] = 'l.price >= ?';
        $params[] = (int) $_GET['min_price'];
    }
    if (isset($_GET['max_price'])) {
        $where[] = 'l.price <= ?';
        $params[] = (int) $_GET['max_price'];
    }
    if (!empty($_GET['free'])) {
        $where[] = 'l.is_free = 1';
    }

    $whereClause = implode(' AND ', $where);
    $sort = match($_GET['sort'] ?? 'recent') {
        'price_low' => 'l.price ASC',
        'price_high' => 'l.price DESC',
        'popular' => 'l.like_count DESC',
        default => 'l.created_at DESC'
    };

    $countStmt = $db->prepare("SELECT COUNT(*) FROM listings l WHERE $whereClause");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT l.*, u.full_name as seller_name, u.avatar as seller_avatar,
               u.manner_score as seller_manner, u.district as seller_district,
               c.name_rw as category_name, c.icon as category_icon,
               (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as primary_image
        FROM listings l
        JOIN users u ON u.id = l.user_id
        JOIN categories c ON c.id = l.category_id
        WHERE $whereClause
        ORDER BY $sort
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $listings = $stmt->fetchAll();

    foreach ($listings as &$listing) {
        $listing['price_formatted'] = formatPrice((int) $listing['price']);
        $listing['time_ago'] = timeAgo($listing['created_at']);
        if ($listing['primary_image']) {
            $listing['primary_image'] = UPLOAD_URL . $listing['primary_image'];
        }
        if ($user) {
            $favStmt = $db->prepare('SELECT id FROM favorites WHERE user_id = ? AND listing_id = ?');
            $favStmt->execute([$user['id'], $listing['id']]);
            $listing['is_favorited'] = (bool) $favStmt->fetch();
        }
    }

    jsonResponse([
        'success' => true,
        'listings' => $listings,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function getListing(int $id): void {
    $db = getDB();
    $user = getAuthUser();

    $stmt = $db->prepare('
        SELECT l.*, u.full_name as seller_name, u.avatar as seller_avatar,
               u.manner_score as seller_manner, u.manner_count as seller_manner_count,
               u.phone as seller_phone, u.district as seller_district,
               u.province as seller_province, u.created_at as seller_since,
               c.name_rw as category_name, c.icon as category_icon
        FROM listings l
        JOIN users u ON u.id = l.user_id
        JOIN categories c ON c.id = l.category_id
        WHERE l.id = ?
    ');
    $stmt->execute([$id]);
    $listing = $stmt->fetch();

    if (!$listing) {
        jsonError('Icyo gicuruzwa ntikibonetse', 404);
    }

    $db->prepare('UPDATE listings SET view_count = view_count + 1 WHERE id = ?')->execute([$id]);

    $imgStmt = $db->prepare('SELECT * FROM listing_images WHERE listing_id = ? ORDER BY sort_order, is_primary DESC');
    $imgStmt->execute([$id]);
    $images = $imgStmt->fetchAll();
    foreach ($images as &$img) {
        $img['url'] = UPLOAD_URL . $img['image_path'];
    }

    $listing['images'] = $images;
    $listing['price_formatted'] = formatPrice((int) $listing['price']);
    $listing['time_ago'] = timeAgo($listing['created_at']);
    $listing['seller_since'] = timeAgo($listing['seller_since']);

    if ($user) {
        $favStmt = $db->prepare('SELECT id FROM favorites WHERE user_id = ? AND listing_id = ?');
        $favStmt->execute([$user['id'], $id]);
        $listing['is_favorited'] = (bool) $favStmt->fetch();
        $listing['is_owner'] = $user['id'] == $listing['user_id'];
    }

    jsonResponse(['success' => true, 'listing' => $listing]);
}

function createListing(): void {
    $user = requireAuth();
    $db = getDB();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = (int) ($_POST['price'] ?? 0);
    $categoryId = (int) ($_POST['category_id'] ?? 9);
    $isFree = isset($_POST['is_free']) && $_POST['is_free'] === '1';
    $province = trim($_POST['province'] ?? $user['province']);
    $district = trim($_POST['district'] ?? $user['district']);
    $sector = trim($_POST['sector'] ?? $user['sector'] ?? '');

    if (empty($title) || empty($description)) {
        jsonError('Uzuza izina n\'ibisobanuro');
    }
    if (strlen($title) > 150) {
        jsonError('Izina rigomba kuba munsi y\'inyuguti 150');
    }
    if ($isFree) {
        $price = 0;
    } elseif ($price < 0) {
        jsonError('Igiciro nticyemewe');
    }

    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

    $columns = ['user_id', 'category_id', 'title', 'description', 'price', 'is_free', 'province', 'district', 'sector'];
    $values = [$user['id'], $categoryId, $title, $description, $price, $isFree ? 1 : 0, $province, $district, $sector ?: null];

    if (tableHasColumn('listings', 'quantity')) {
        $columns[] = 'quantity';
        $values[] = $quantity;
    }

    // Listing Approval is a System Control — off by default so posting keeps working as before
    $needsApproval = tableHasColumn('listings', 'approval_status') && getSetting('require_listing_approval', '0') === '1';
    if (tableHasColumn('listings', 'approval_status')) {
        $columns[] = 'approval_status';
        $values[] = $needsApproval ? 'pending' : 'approved';
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $db->prepare('INSERT INTO listings (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
    $stmt->execute($values);
    $listingId = (int) $db->lastInsertId();

    if (!empty($_FILES['images'])) {
        $files = $_FILES['images'];
        $count = is_array($files['name']) ? count($files['name']) : 1;

        for ($i = 0; $i < $count; $i++) {
            $file = is_array($files['name']) ? [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i]
            ] : $files;

            if ($file['error'] !== UPLOAD_ERR_OK) continue;

            $filename = handleImageUpload($file);
            if ($filename) {
                $db->prepare('
                    INSERT INTO listing_images (listing_id, image_path, is_primary, sort_order)
                    VALUES (?, ?, ?, ?)
                ')->execute([$listingId, $filename, $i === 0 ? 1 : 0, $i]);
            }
        }
    }

    jsonResponse([
        'success' => true,
        'message' => $needsApproval
            ? 'Igicuruzwa cyoherejwe — gitegereje kwemezwa n\'abagenzuzi'
            : 'Igicuruzwa cyashyizweho neza!',
        'pending_approval' => $needsApproval,
        'listing_id' => $listingId
    ], 201);
}

function updateListing(int $id): void {
    $user = requireAuth();
    $db = getDB();
    $data = getJsonInput();

    $stmt = $db->prepare('SELECT * FROM listings WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    if (!$stmt->fetch()) {
        jsonError('Ntushobora guhindura iki gicuruzwa', 403);
    }

    $fields = [];
    $params = [];

    if (isset($data['title'])) {
        $fields[] = 'title = ?';
        $params[] = trim($data['title']);
    }
    if (isset($data['description'])) {
        $fields[] = 'description = ?';
        $params[] = trim($data['description']);
    }
    if (isset($data['price'])) {
        $fields[] = 'price = ?';
        $params[] = (int) $data['price'];
    }
    if (isset($data['status']) && in_array($data['status'], ['active', 'reserved', 'sold'])) {
        $fields[] = 'status = ?';
        $params[] = $data['status'];
    }
    if (isset($data['category_id'])) {
        $fields[] = 'category_id = ?';
        $params[] = (int) $data['category_id'];
    }
    if (isset($data['quantity']) && tableHasColumn('listings', 'quantity')) {
        $fields[] = 'quantity = ?';
        $params[] = max(0, (int) $data['quantity']);
    }

    if (empty($fields)) {
        jsonError('Nta makuru yo guhindura');
    }

    $params[] = $id;
    $db->prepare('UPDATE listings SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    jsonResponse(['success' => true, 'message' => 'Byahinduwe neza']);
}

function deleteListing(int $id): void {
    $user = requireAuth();
    $db = getDB();

    $stmt = $db->prepare('SELECT * FROM listings WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    if (!$stmt->fetch()) {
        jsonError('Ntushobora gusiba iki gicuruzwa', 403);
    }

    $imgStmt = $db->prepare('SELECT image_path FROM listing_images WHERE listing_id = ?');
    $imgStmt->execute([$id]);
    foreach ($imgStmt->fetchAll() as $img) {
        $path = UPLOAD_DIR . $img['image_path'];
        if (file_exists($path)) unlink($path);
    }

    $db->prepare('DELETE FROM listings WHERE id = ?')->execute([$id]);
    jsonResponse(['success' => true, 'message' => 'Igicuruzwa cyasibwe']);
}
