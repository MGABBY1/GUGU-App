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
        if (!empty($_GET['action']) && $_GET['action'] === 'add-images' && $id) {
            addListingImages($id);
        } else {
            createListing();
        }
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

    $where = ["l.status IN ('active','sold')"];
    $params = [];

    // Public feed: only Admin-approved posts (pending wait for payment + approval)
    // Sold stays visible with a Sold badge (Karrot-style).
    // include_own_pending: approved for everyone + my pending/flagged (Jobs portal etc.)
    $mineOnly = !empty($_GET['mine']) || !empty($_GET['include_pending']);
    $includeOwnPending = !empty($_GET['include_own_pending']) && $user;
    $ownerId = $user ? (int) $user['id'] : 0;

    if ($mineOnly) {
        if (!$user) {
            jsonError('Login required', 401);
        }
        // Owner tracker: all of my posts (waiting / live / sold / rejected)
        $where = ["l.status IN ('active','sold','reserved')", 'l.user_id = ?'];
        $params = [$ownerId];
    } elseif ($includeOwnPending) {
        $where[] = '(l.moderation_status = "approved" OR (l.user_id = ? AND l.moderation_status IN ("pending","flagged")))';
        $params[] = $ownerId;
    } else {
        $where[] = 'l.moderation_status = "approved"';
    }

    if (!empty($_GET['category'])) {
        $where[] = 'l.category_id = ?';
        $params[] = (int) $_GET['category'];
    } else {
        // Jobs (Akazi / category 11) stay in Part-time Jobs portal only — never mix into Items
        $where[] = 'l.category_id <> 11';
    }
    // Location filters
    // Members (role 4): always scoped to their confirmed living district for marketplace browse.
    // Guests/staff may pass optional district/sector/province query params.
    $roleId = $user ? (int) ($user['role_id'] ?? 4) : 0;
    $isMember = $user && $roleId === 4;
    $memberDistrict = $isMember ? trim((string) ($user['district'] ?? '')) : '';

    if ($isMember && $memberDistrict !== '' && !$mineOnly) {
        // Force member feed to their stay district (ignore client "all locations")
        $loc = ['l.district = ?'];
        $locParams = [$memberDistrict];
        // Optional sector narrow within their district
        if (!empty($_GET['sector'])) {
            $loc[] = 'l.sector = ?';
            $locParams[] = $_GET['sector'];
        }
        if ($includeOwnPending) {
            $where[] = '(l.user_id = ? OR (' . implode(' AND ', $loc) . '))';
            $params[] = $ownerId;
            array_push($params, ...$locParams);
        } else {
            foreach ($loc as $i => $clause) {
                $where[] = $clause;
                $params[] = $locParams[$i];
            }
        }
    } elseif (!empty($_GET['district']) || !empty($_GET['sector']) || !empty($_GET['province'])) {
        $loc = [];
        $locParams = [];
        if (!empty($_GET['district'])) {
            $loc[] = 'l.district = ?';
            $locParams[] = $_GET['district'];
        }
        if (!empty($_GET['sector'])) {
            $loc[] = 'l.sector = ?';
            $locParams[] = $_GET['sector'];
        }
        if (!empty($_GET['province'])) {
            $loc[] = 'l.province = ?';
            $locParams[] = $_GET['province'];
        }
        if ($includeOwnPending) {
            $where[] = '(l.user_id = ? OR (' . implode(' AND ', $loc) . '))';
            $params[] = $ownerId;
            array_push($params, ...$locParams);
        } else {
            foreach ($loc as $i => $clause) {
                $where[] = $clause;
                $params[] = $locParams[$i];
            }
        }
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
    if (!empty($_GET['user_id'])) {
        $where[] = 'l.user_id = ?';
        $params[] = (int) $_GET['user_id'];
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
        SELECT l.*, u.avatar as seller_avatar,
               u.manner_score as seller_manner, u.district as seller_district,
               COALESCE(NULLIF(u.nickname, ''), SUBSTRING_INDEX(u.full_name, ' ', 1)) as seller_name,
               CONCAT(
                 COALESCE(NULLIF(u.nickname, ''), SUBSTRING_INDEX(u.full_name, ' ', 1)),
                 ' • ',
                 COALESCE(NULLIF(u.sector, ''), u.district)
               ) as seller_display,
               c.name_rw as category_name, c.name_rw as category_name_rw, c.name_en as category_name_en, c.icon as category_icon,
               (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1) as primary_image
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
            $listing['primary_image'] = publicUploadUrl($listing['primary_image']);
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

    $stmt = $db->prepare("
        SELECT l.*, u.full_name as seller_name_private,
               COALESCE(NULLIF(u.nickname, ''), SUBSTRING_INDEX(u.full_name, ' ', 1)) as seller_name,
               CONCAT(
                 COALESCE(NULLIF(u.nickname, ''), SUBSTRING_INDEX(u.full_name, ' ', 1)),
                 ' • ',
                 COALESCE(NULLIF(u.sector, ''), u.district)
               ) as seller_display,
               u.manner_score as seller_manner, u.manner_count as seller_manner_count,
               u.phone as seller_phone, u.district as seller_district,
               u.sector as seller_sector,
               u.province as seller_province, u.created_at as seller_since,
               c.name_rw as category_name, c.name_rw as category_name_rw, c.name_en as category_name_en, c.icon as category_icon
        FROM listings l
        JOIN users u ON u.id = l.user_id
        JOIN categories c ON c.id = l.category_id
        WHERE l.id = ?
    ");
    $stmt->execute([$id]);
    $listing = $stmt->fetch();

    if (!$listing) {
        jsonError('Icyo gicuruzwa ntikibonetse', 404);
    }

    $isOwner = $user && (int) $user['id'] === (int) $listing['user_id'];
    $isStaff = $user && (int) ($user['role_id'] ?? 4) <= 3;
    if (($listing['moderation_status'] ?? '') !== 'approved' && !$isOwner && !$isStaff) {
        jsonError('This post is waiting for Admin approval after the 1000 RWF fee.', 403);
    }

    // Members may only open listings in their confirmed stay district (except own posts)
    $isMember = $user && (int) ($user['role_id'] ?? 4) === 4;
    $memberDistrict = $isMember ? trim((string) ($user['district'] ?? '')) : '';
    $listingDistrict = trim((string) ($listing['district'] ?? ''));
    if ($isMember && !$isOwner && $memberDistrict !== '' && $listingDistrict !== '' && strcasecmp($memberDistrict, $listingDistrict) !== 0) {
        jsonError('Iki gicuruzwa kiri mu karere kindi. Hindura Akarere mu igenamiterere (GPS) mbere.', 403);
    }

    $db->prepare('UPDATE listings SET view_count = view_count + 1 WHERE id = ?')->execute([$id]);

    $imgStmt = $db->prepare('SELECT * FROM listing_images WHERE listing_id = ? ORDER BY sort_order, is_primary DESC');
    $imgStmt->execute([$id]);
    $images = $imgStmt->fetchAll();
    foreach ($images as &$img) {
        $img['url'] = publicUploadUrl($img['image_path'] ?? '');
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
        // Deal role on this listing (Member activity — not role_id)
        $listing['my_deal_role'] = $listing['is_owner'] ? 'seller' : 'buyer';
        $listing['my_deal_label'] = $listing['is_owner'] ? 'Seller' : 'Buyer';
    } else {
        $listing['my_deal_role'] = null;
        $listing['my_deal_label'] = null;
    }

    // Phone only for logged-in members on Jobs (Contact button). Hide on marketplace items.
    $isJob = (($listing['business_type'] ?? '') === 'job')
        || (int) ($listing['category_id'] ?? 0) === guguJobCategoryId();
    if (!$user || !$isJob) {
        unset($listing['seller_phone']);
    }

    jsonResponse(['success' => true, 'listing' => $listing]);
}

function createListing(): void {
    try {
        $user = requireAuth();
        requireMemberIdApproved($user);
        $db = getDB();

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (int) ($_POST['price'] ?? 0);
        $categoryId = (int) ($_POST['category_id'] ?? 9);
        $isFree = isset($_POST['is_free']) && ($_POST['is_free'] === '1' || $_POST['is_free'] === 'true');
        $province = trim($_POST['province'] ?? $user['province'] ?? '');
        $district = trim($_POST['district'] ?? $user['district'] ?? '');
        $sector = trim($_POST['sector'] ?? $user['sector'] ?? '');

        if ($categoryId <= 1) {
            $categoryId = 10; // "Others" — never store "All"
        }
        if ($province === '') $province = 'Kigali';
        if ($district === '') $district = 'Gasabo';

        $roleId = (int) ($user['role_id'] ?? 4);
        $isStaff = $roleId >= 1 && $roleId <= 3;
        // Members must post in their confirmed stay district only
        if (!$isStaff) {
            $homeDistrict = trim((string) ($user['district'] ?? ''));
            $homeSector = trim((string) ($user['sector'] ?? ''));
            $homeProvince = trim((string) ($user['province'] ?? ''));
            if ($homeDistrict === '') {
                jsonError('Banza wemeze Akarere hawe (GPS) mu igenamiterere mbere yo kugurisha.', 400);
            }
            $district = $homeDistrict;
            if ($homeProvince !== '') $province = $homeProvince;
            $sector = $sector !== '' ? $sector : $homeSector;
        }

        if ($title === '' || $description === '') {
            jsonError('Uzuza izina n\'ibisobanuro / Fill title and description');
        }
        if (strlen($title) > 150) {
            jsonError('Izina rigomba kuba munsi y\'inyuguti 150');
        }
        if ($isFree) {
            $price = 0;
        } elseif ($price < 0) {
            jsonError('Igiciro nticyemewe / Invalid price');
        }

        $feeAck = isset($_POST['fee_acknowledged']) && ($_POST['fee_acknowledged'] === '1' || $_POST['fee_acknowledged'] === 'true');
        $businessType = guguBusinessTypeFromCategory((int) $categoryId);
        $fee = guguAnnounceFeeForBusiness($businessType);

        // Members must acknowledge the announce fee (separate for Items vs Jobs)
        if (!$isStaff && !$feeAck) {
            jsonError(
                'Pay ' . $fee . ' RWF ' . guguBusinessLabel($businessType) . ' announce fee (MoMo ' . GUGU_MOMO_NUMBER . '), then Admin will approve your post.',
                400
            );
        }

        // Staff posts: live immediately. Members: pending until Admin confirms payment.
        $moderation = $isStaff ? 'approved' : 'pending';
        $paymentStatus = $isStaff ? 'waived' : 'unpaid';

        $stmt = $db->prepare('
            INSERT INTO listings (
                user_id, category_id, business_type, title, description, price, is_free,
                province, district, sector, moderation_status,
                announce_fee_rwf, payment_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $user['id'], $categoryId, $businessType, $title, $description, $price,
            $isFree ? 1 : 0, $province, $district, $sector !== '' ? $sector : null,
            $moderation, $fee, $paymentStatus,
        ]);
        $listingId = (int) $db->lastInsertId();

        $uploadFiles = collectListingUploadFiles();
        $savedImages = 0;
        $uploadErrors = 0;

        foreach ($uploadFiles as $i => $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE
                || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
                || ((int) ($file['size'] ?? 0) > MAX_UPLOAD_SIZE)) {
                $uploadErrors++;
                continue;
            }
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $uploadErrors++;
                continue;
            }
            $filename = handleImageUpload($file);
            if ($filename) {
                $db->prepare('
                    INSERT INTO listing_images (listing_id, image_path, is_primary, sort_order)
                    VALUES (?, ?, ?, ?)
                ')->execute([$listingId, $filename, $savedImages === 0 ? 1 : 0, $savedImages]);
                $savedImages++;
            } else {
                $uploadErrors++;
            }
        }

        // Items must have at least one photo so Admin / market can show the product
        $isJobPost = ((int) $categoryId === guguJobCategoryId()) || $businessType === 'job';
        if (!$isJobPost && $savedImages === 0) {
            $db->prepare('DELETE FROM listings WHERE id = ? AND user_id = ?')->execute([$listingId, $user['id']]);
            if ($uploadErrors > 0 || count($uploadFiles) > 0) {
                jsonError('Photo upload failed. Use JPG/PNG/WebP under 12MB and try again.', 400);
            }
            jsonError('Add at least one photo of the item before posting.', 400);
        }

        jsonResponse([
            'success' => true,
            'message' => $isStaff
                ? 'Published'
                : ('Submitted! Pay ' . $fee . ' RWF to ' . GUGU_MOMO_NAME . ' (' . GUGU_MOMO_NUMBER . '). Admin will approve after payment.'),
            'listing_id' => $listingId,
            'moderation_status' => $moderation,
            'payment_status' => $paymentStatus,
            'announce_fee_rwf' => $fee,
            'momo_number' => GUGU_MOMO_NUMBER,
            'momo_name' => GUGU_MOMO_NAME,
            'pending_approval' => !$isStaff,
            'image_count' => $savedImages,
        ], 201);
    } catch (Throwable $e) {
        jsonError('Ntibyakunze gushyira igicuruzwa: ' . $e->getMessage(), 500);
    }
}

function updateListing(int $id): void {
    $user = requireAuth();
    $db = getDB();
    $data = getJsonInput();

    $stmt = $db->prepare('SELECT * FROM listings WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    $listing = $stmt->fetch();
    if (!$listing) {
        jsonError('Ntushobora guhindura iki gicuruzwa', 403);
    }

    $fields = [];
    $params = [];
    $newStatus = null;

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
    if (isset($data['status']) && in_array($data['status'], ['active', 'reserved', 'sold'], true)) {
        $fields[] = 'status = ?';
        $params[] = $data['status'];
        $newStatus = $data['status'];
    }
    if (isset($data['category_id'])) {
        $fields[] = 'category_id = ?';
        $params[] = (int) $data['category_id'];
    }

    if (empty($fields)) {
        jsonError('Nta makuru yo guhindura');
    }

    $params[] = $id;
    $db->prepare('UPDATE listings SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    $message = 'Updated';
    if ($newStatus === 'sold') {
        $message = 'Marked as sold';
    } elseif ($newStatus === 'active') {
        $message = 'Listed again for sale';
    }

    jsonResponse([
        'success' => true,
        'message' => $message,
        'status' => $newStatus ?: ($listing['status'] ?? 'active'),
    ]);
}

function addListingImages(int $id): void {
    $user = requireAuth();
    $db = getDB();

    $stmt = $db->prepare('SELECT id, user_id, category_id, business_type FROM listings WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    $listing = $stmt->fetch();
    if (!$listing) {
        jsonError('Listing not found', 404);
    }

    $uploadFiles = collectListingUploadFiles();
    if (!$uploadFiles) {
        jsonError('Add at least one photo (JPG, PNG, or WebP).', 400);
    }

    $countStmt = $db->prepare('SELECT COUNT(*) FROM listing_images WHERE listing_id = ?');
    $countStmt->execute([$id]);
    $existing = (int) $countStmt->fetchColumn();

    $saved = 0;
    foreach ($uploadFiles as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $filename = handleImageUpload($file);
        if (!$filename) {
            continue;
        }
        $db->prepare('
            INSERT INTO listing_images (listing_id, image_path, is_primary, sort_order)
            VALUES (?, ?, ?, ?)
        ')->execute([
            $id,
            $filename,
            $existing + $saved === 0 ? 1 : 0,
            $existing + $saved,
        ]);
        $saved++;
    }

    if ($saved === 0) {
        jsonError('Photo upload failed. Use JPG/PNG/WebP under 12MB.', 400);
    }

    $imgStmt = $db->prepare('SELECT image_path FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1');
    $imgStmt->execute([$id]);
    $primary = (string) ($imgStmt->fetchColumn() ?: '');

    jsonResponse([
        'success' => true,
        'message' => 'Photos saved',
        'image_count' => $existing + $saved,
        'primary_image' => publicUploadUrl($primary),
    ]);
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
    jsonResponse(['success' => true, 'message' => 'Post deleted']);
}
