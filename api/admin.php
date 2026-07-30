<?php
/**
 * GUGU App - Administrative Portal API (secured access)
 *
 * Roles: moderator -> district_manager -> super_admin
 */

require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['ok' => true]);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'stats';
$db = getDB();

switch ($action) {
    case 'me':
        adminMe();
        break;
    case 'stats':
        adminStats();
        break;
    case 'moderation':
        moderationQueue();
        break;
    case 'approve-listing':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        reviewListing('approved');
        break;
    case 'reject-listing':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        reviewListing('rejected');
        break;
    case 'dismiss-report':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        dismissReport();
        break;
    case 'listings':
        adminListings();
        break;
    case 'delete-listing':
        if ($method !== 'DELETE') jsonError('Method not allowed', 405);
        adminDeleteListing();
        break;
    case 'users':
        adminUsers();
        break;
    case 'set-role':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        setUserRole();
        break;
    case 'ban-user':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        toggleBan();
        break;
    case 'verify-user':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        toggleVerify();
        break;
    case 'disputes':
        adminDisputes();
        break;
    case 'resolve-dispute':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        resolveDispute();
        break;
    case 'analytics':
        adminAnalytics();
        break;
    case 'management':
        adminManagement();
        break;
    case 'settings':
        adminSettings();
        break;
    case 'save-settings':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        saveSettings();
        break;
    case 'audit':
        auditLog();
        break;
    default:
        jsonError('Invalid action', 404);
}

/**
 * Super Admin may inspect a lower-role dashboard without changing identity.
 * This context only affects read queries; write permissions always use the
 * authenticated role from requireAdmin().
 */
function adminViewRole(array $admin): string {
    $actualRole = userRole($admin);
    if ($actualRole === 'moderator') return 'moderator';

    $requested = $_GET['view_role'] ?? $actualRole;
    $allowed = $actualRole === 'super_admin'
        ? ['moderator', 'district_manager', 'super_admin']
        : ['moderator', 'district_manager'];
    return in_array($requested, $allowed, true) ? $requested : $actualRole;
}

function adminViewScope(array $admin): ?string {
    if (userRole($admin) !== 'super_admin') {
        return adminDistrictScope($admin);
    }

    if (adminViewRole($admin) === 'super_admin') return null;
    $district = trim($_GET['view_district'] ?? '');
    return $district !== '' ? $district : null;
}

/**
 * Adds "AND <column> = district" for role-scoped read queries.
 */
function scopeClause(array $admin, string $column, array &$params): string {
    $scope = adminViewScope($admin);
    if ($scope === null || $scope === '') return '';
    $params[] = $scope;
    return " AND $column = ?";
}

function getAdminDistricts(): array {
    $stmt = getDB()->query('
        SELECT district FROM users WHERE district IS NOT NULL AND district <> ""
        UNION
        SELECT district FROM listings WHERE district IS NOT NULL AND district <> ""
        ORDER BY district
    ');
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function adminMe(): void {
    $admin = requireAdmin();
    $canViewAs = in_array($admin['role'], ['district_manager', 'super_admin'], true);
    $districts = $admin['role'] === 'super_admin'
        ? getAdminDistricts()
        : array_values(array_filter([adminDistrictScope($admin)]));
    jsonResponse([
        'success' => true,
        'admin' => [
            'id' => (int) $admin['id'],
            'full_name' => $admin['full_name'],
            'phone' => $admin['phone'],
            'role' => $admin['role'],
            'district_scope' => adminDistrictScope($admin),
            'permissions' => rolePermissions($admin['role']),
            'can_view_as' => $canViewAs,
            'districts' => $districts,
        ],
    ]);
}

function adminStats(): void {
    $admin = requireAdmin('view_dashboard');
    $db = getDB();
    $scope = adminViewScope($admin);
    $viewRole = adminViewRole($admin);

    $count = function (string $sql, array $params = []) use ($db): int {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    };

    $listingWhere = $scope ? ' WHERE district = ?' : '';
    $listingParams = $scope ? [$scope] : [];

    $stats = [
        'users' => $count('SELECT COUNT(*) FROM users' . ($scope ? ' WHERE district = ?' : ''), $listingParams),
        'listings' => $count('SELECT COUNT(*) FROM listings' . $listingWhere, $listingParams),
        'active_listings' => $count(
            'SELECT COUNT(*) FROM listings WHERE status = "active"' . ($scope ? ' AND district = ?' : ''),
            $listingParams
        ),
        'sold_listings' => $count(
            'SELECT COUNT(*) FROM listings WHERE status = "sold"' . ($scope ? ' AND district = ?' : ''),
            $listingParams
        ),
        'messages' => $count('SELECT COUNT(*) FROM messages'),
        'chat_rooms' => $count('SELECT COUNT(*) FROM chat_rooms'),
    ];

    $stats['pending_listings'] = tableHasColumn('listings', 'approval_status')
        ? $count(
            'SELECT COUNT(*) FROM listings WHERE approval_status = "pending"' . ($scope ? ' AND district = ?' : ''),
            $listingParams
        )
        : 0;

    $reportParams = [];
    $reportScope = '';
    if ($scope) {
        $reportScope = ' AND l.district = ?';
        $reportParams[] = $scope;
    }
    $stats['open_reports'] = $count(
        'SELECT COUNT(*) FROM reports r
         JOIN listings l ON l.id = r.target_id AND r.target_type = "listing"
         WHERE r.status = "open"' . $reportScope,
        $reportParams
    );

    $scopedOrderJoin = ' FROM orders o JOIN listings l ON l.id = o.listing_id' . ($scope ? ' WHERE l.district = ?' : '');
    $stats['open_disputes'] = $count(
        'SELECT COUNT(*) FROM disputes d JOIN orders o ON o.id = d.order_id JOIN listings l ON l.id = o.listing_id
         WHERE d.status IN ("open", "in_review")' . ($scope ? ' AND l.district = ?' : ''),
        $listingParams
    );
    $stats['orders'] = $count('SELECT COUNT(*)' . $scopedOrderJoin, $listingParams);

    $escrowStmt = $db->prepare('
        SELECT COALESCE(SUM(e.amount), 0)
        FROM escrow_ledger e
        JOIN orders o ON o.id = e.order_id
        JOIN listings l ON l.id = o.listing_id
        WHERE e.direction = "hold" AND e.status = "success"
          AND o.status NOT IN ("completed", "cancelled", "refunded")' . ($scope ? ' AND l.district = ?' : '')
    );
    $escrowStmt->execute($listingParams);
    $stats['escrow_held'] = (int) $escrowStmt->fetchColumn();
    $stats['escrow_held_formatted'] = formatPrice($stats['escrow_held']);

    jsonResponse([
        'success' => true,
        'stats' => $stats,
        'role' => $admin['role'],
        'view_role' => $viewRole,
        'district_scope' => $scope,
    ]);
}

function moderationQueue(): void {
    $admin = requireAdmin('view_moderation');
    $db = getDB();

    $pending = [];
    if (tableHasColumn('listings', 'approval_status')) {
        $params = [];
        $clause = scopeClause($admin, 'l.district', $params);
        $stmt = $db->prepare("
            SELECT l.id, l.title, l.price, l.district, l.created_at, l.approval_status,
                   u.full_name as seller_name, u.phone as seller_phone
            FROM listings l
            JOIN users u ON u.id = l.user_id
            WHERE l.approval_status = 'pending'$clause
            ORDER BY l.created_at ASC
            LIMIT 100
        ");
        $stmt->execute($params);
        $pending = $stmt->fetchAll();
        foreach ($pending as &$p) {
            $p['price_formatted'] = formatPrice((int) $p['price']);
            $p['queue_type'] = 'approval';
        }
    }

    $params = [];
    $clause = scopeClause($admin, 'l.district', $params);
    $stmt = $db->prepare("
        SELECT r.id as report_id, r.reason, r.details, r.created_at,
               l.id, l.title, l.price, l.district,
               u.full_name as seller_name, u.phone as seller_phone,
               rp.full_name as reporter_name
        FROM reports r
        JOIN listings l ON l.id = r.target_id
        JOIN users u ON u.id = l.user_id
        LEFT JOIN users rp ON rp.id = r.reporter_id
        WHERE r.status = 'open' AND r.target_type = 'listing'$clause
        ORDER BY r.created_at ASC
        LIMIT 100
    ");
    $stmt->execute($params);
    $reports = $stmt->fetchAll();
    foreach ($reports as &$r) {
        $r['price_formatted'] = formatPrice((int) $r['price']);
        $r['queue_type'] = 'report';
    }

    jsonResponse(['success' => true, 'queue' => array_merge($pending, $reports)]);
}

function reviewListing(string $decision): void {
    $admin = requireAdmin($decision === 'approved' ? 'approve_listing' : 'reject_listing');
    $db = getDB();
    $data = getJsonInput();

    $id = (int) ($data['listing_id'] ?? 0);
    if (!$id) jsonError('Listing ID required');
    if (!tableHasColumn('listings', 'approval_status')) {
        jsonError('Ongera ukoreshe setup.php kugira ngo listing approval ikore');
    }

    $stmt = $db->prepare('SELECT l.*, u.full_name FROM listings l JOIN users u ON u.id = l.user_id WHERE l.id = ?');
    $stmt->execute([$id]);
    $listing = $stmt->fetch();
    if (!$listing) jsonError('Igicuruzwa ntikibonetse', 404);

    $scope = adminDistrictScope($admin);
    if ($scope !== null && $listing['district'] !== $scope) {
        jsonError('Iki gicuruzwa ntikiri mu karere ushinzwe', 403);
    }

    $reason = trim($data['reason'] ?? '');
    $db->prepare('UPDATE listings SET approval_status = ?, rejection_reason = ? WHERE id = ?')
       ->execute([$decision, $decision === 'rejected' ? ($reason ?: 'Ntibyujuje ibisabwa') : null, $id]);

    if ($decision === 'rejected') {
        $db->prepare('UPDATE listings SET status = "reserved" WHERE id = ?')->execute([$id]);
    }

    $db->prepare('
        UPDATE reports SET status = "resolved", handled_by = ?
        WHERE target_type = "listing" AND target_id = ? AND status = "open"
    ')->execute([$admin['id'], $id]);

    notify(
        (int) $listing['user_id'],
        'moderation',
        $decision === 'approved' ? 'Igicuruzwa cyawe cyemejwe' : 'Igicuruzwa cyawe cyanzwe',
        $listing['title'] . ($decision === 'rejected' && $reason ? ' — ' . $reason : ''),
        'listing',
        $id
    );

    logAdminAction((int) $admin['id'], 'listing_' . $decision, 'listing', $id, $reason ?: null);
    jsonResponse(['success' => true, 'message' => $decision === 'approved' ? 'Cyemejwe' : 'Cyanzwe']);
}

function dismissReport(): void {
    $admin = requireAdmin('view_moderation');
    $data = getJsonInput();
    $reportId = (int) ($data['report_id'] ?? 0);
    if (!$reportId) jsonError('Report ID required');

    getDB()->prepare('UPDATE reports SET status = "dismissed", handled_by = ? WHERE id = ?')
           ->execute([$admin['id'], $reportId]);
    logAdminAction((int) $admin['id'], 'report_dismissed', 'report', $reportId);
    jsonResponse(['success' => true, 'message' => 'Raporo yavanyweho']);
}

function adminListings(): void {
    $admin = requireAdmin('view_listings');
    $db = getDB();

    $params = [];
    $clause = scopeClause($admin, 'l.district', $params);
    $approvalCol = tableHasColumn('listings', 'approval_status') ? 'l.approval_status' : "'approved' as approval_status";

    $stmt = $db->prepare("
        SELECT l.id, l.title, l.price, l.status, l.district, l.created_at, $approvalCol,
               u.full_name as seller_name, u.phone as seller_phone, c.name_rw as category_name,
               (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as primary_image
        FROM listings l
        JOIN users u ON u.id = l.user_id
        JOIN categories c ON c.id = l.category_id
        WHERE 1 = 1$clause
        ORDER BY l.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $listings = $stmt->fetchAll();

    foreach ($listings as &$l) {
        $l['price_formatted'] = formatPrice((int) $l['price']);
        if ($l['primary_image']) $l['primary_image'] = UPLOAD_URL . $l['primary_image'];
    }

    jsonResponse(['success' => true, 'listings' => $listings, 'can_delete' => roleCan($admin['role'], 'delete_listing')]);
}

function adminDeleteListing(): void {
    $admin = requireAdmin('delete_listing');
    $db = getDB();

    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('ID required');

    $stmt = $db->prepare('SELECT * FROM listings WHERE id = ?');
    $stmt->execute([$id]);
    $listing = $stmt->fetch();
    if (!$listing) jsonError('Igicuruzwa ntikibonetse', 404);

    $scope = adminDistrictScope($admin);
    if ($scope !== null && $listing['district'] !== $scope) {
        jsonError('Iki gicuruzwa ntikiri mu karere ushinzwe', 403);
    }

    $imgStmt = $db->prepare('SELECT image_path FROM listing_images WHERE listing_id = ?');
    $imgStmt->execute([$id]);
    foreach ($imgStmt->fetchAll() as $img) {
        $path = UPLOAD_DIR . $img['image_path'];
        if (file_exists($path)) unlink($path);
    }

    $db->prepare('DELETE FROM listings WHERE id = ?')->execute([$id]);
    logAdminAction((int) $admin['id'], 'listing_deleted', 'listing', $id, $listing['title']);

    jsonResponse(['success' => true, 'message' => 'Listing deleted']);
}

function adminUsers(): void {
    $admin = requireAdmin('view_users');
    $db = getDB();

    $roleCol = tableHasColumn('users', 'role') ? 'u.role' : "'member' as role";
    $bannedCol = tableHasColumn('users', 'is_banned') ? 'u.is_banned' : '0 as is_banned';
    $managedCol = tableHasColumn('users', 'managed_district') ? 'u.managed_district' : 'NULL as managed_district';

    $params = [];
    $clause = scopeClause($admin, 'u.district', $params);

    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.phone, u.province, u.district, u.manner_score,
               u.manner_count, u.is_verified, u.created_at, $roleCol, $bannedCol, $managedCol,
               (SELECT COUNT(*) FROM listings WHERE user_id = u.id) as listing_count
        FROM users u
        WHERE 1 = 1$clause
        ORDER BY u.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);

    jsonResponse([
        'success' => true,
        'users' => $stmt->fetchAll(),
        'can_manage_roles' => roleCan($admin['role'], 'manage_roles'),
        'can_ban' => roleCan($admin['role'], 'ban_user'),
        'roles' => ['member', 'moderator', 'district_manager', 'super_admin'],
    ]);
}

function setUserRole(): void {
    $admin = requireAdmin('manage_roles');
    $db = getDB();
    $data = getJsonInput();

    $userId = (int) ($data['user_id'] ?? 0);
    $role = $data['role'] ?? '';
    if (!$userId || !in_array($role, ['member', 'moderator', 'district_manager', 'super_admin'], true)) {
        jsonError('Amakuru atari yo');
    }
    if (!tableHasColumn('users', 'role')) {
        jsonError('Ongera ukoreshe setup.php kugira ngo roles zikore');
    }
    if ($userId === (int) $admin['id']) {
        jsonError('Ntushobora guhindura uruhare rwawe');
    }

    $managedDistrict = trim($data['managed_district'] ?? '');
    if (tableHasColumn('users', 'managed_district')) {
        $db->prepare('UPDATE users SET role = ?, managed_district = ? WHERE id = ?')
           ->execute([$role, $managedDistrict !== '' ? $managedDistrict : null, $userId]);
    } else {
        $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
    }

    notify($userId, 'system', 'Uruhare rwawe rwahindutse', 'Uruhare rushya: ' . $role);
    logAdminAction((int) $admin['id'], 'role_changed', 'user', $userId, $role . ($managedDistrict ? ' @ ' . $managedDistrict : ''));

    jsonResponse(['success' => true, 'message' => 'Uruhare rwahinduwe']);
}

function toggleBan(): void {
    $admin = requireAdmin('ban_user');
    $db = getDB();
    $data = getJsonInput();

    $userId = (int) ($data['user_id'] ?? 0);
    if (!$userId) jsonError('User ID required');
    if (!tableHasColumn('users', 'is_banned')) {
        jsonError('Ongera ukoreshe setup.php');
    }
    if ($userId === (int) $admin['id']) jsonError('Ntushobora kwihagarika');

    $stmt = $db->prepare('SELECT id, district, is_banned FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) jsonError('Umukoresha ntibonetse', 404);

    $scope = adminDistrictScope($admin);
    if ($scope !== null && $target['district'] !== $scope) {
        jsonError('Uyu mukoresha ntari mu karere ushinzwe', 403);
    }

    $newValue = (int) $target['is_banned'] === 1 ? 0 : 1;
    $db->prepare('UPDATE users SET is_banned = ? WHERE id = ?')->execute([$newValue, $userId]);

    if ($newValue === 1) {
        $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
    }

    logAdminAction((int) $admin['id'], $newValue ? 'user_banned' : 'user_unbanned', 'user', $userId);
    jsonResponse(['success' => true, 'banned' => (bool) $newValue]);
}

function toggleVerify(): void {
    $admin = requireAdmin('verify_user');
    $db = getDB();
    $data = getJsonInput();

    $userId = (int) ($data['user_id'] ?? 0);
    if (!$userId) jsonError('User ID required');

    $stmt = $db->prepare('SELECT id, district, is_verified FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) jsonError('Umukoresha ntibonetse', 404);

    $scope = adminDistrictScope($admin);
    if ($scope !== null && $target['district'] !== $scope) {
        jsonError('Uyu mukoresha ntari mu karere ushinzwe', 403);
    }

    $newValue = (int) $target['is_verified'] === 1 ? 0 : 1;
    $db->prepare('UPDATE users SET is_verified = ? WHERE id = ?')->execute([$newValue, $userId]);

    logAdminAction((int) $admin['id'], $newValue ? 'user_verified' : 'user_unverified', 'user', $userId);
    jsonResponse(['success' => true, 'verified' => (bool) $newValue]);
}

function adminDisputes(): void {
    $admin = requireAdmin('view_disputes');
    $db = getDB();

    $params = [];
    $clause = scopeClause($admin, 'l.district', $params);

    $stmt = $db->prepare("
        SELECT d.*, o.amount, o.status as order_status, o.buyer_id, o.seller_id,
               l.title as listing_title, l.district,
               rb.full_name as raised_by_name,
               buyer.full_name as buyer_name, seller.full_name as seller_name,
               h.full_name as handled_by_name
        FROM disputes d
        JOIN orders o ON o.id = d.order_id
        JOIN listings l ON l.id = o.listing_id
        JOIN users rb ON rb.id = d.opener_id
        JOIN users buyer ON buyer.id = o.buyer_id
        JOIN users seller ON seller.id = o.seller_id
        LEFT JOIN users h ON h.id = d.assigned_admin_id
        WHERE 1 = 1$clause
        ORDER BY FIELD(d.status, 'open', 'in_review', 'resolved_buyer', 'resolved_seller', 'closed'), d.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $disputes = $stmt->fetchAll();

    foreach ($disputes as &$d) {
        $d['amount_formatted'] = formatPrice((int) $d['amount']);
        $d['time_ago'] = timeAgo($d['created_at']);
        $d['escrow_status'] = orderEscrowStatus((int) $d['order_id']);
        $d['against_name'] = (int) $d['opener_id'] === (int) $d['buyer_id'] ? $d['seller_name'] : $d['buyer_name'];
    }

    jsonResponse([
        'success' => true,
        'disputes' => $disputes,
        'can_handle' => roleCan($admin['role'], 'handle_dispute'),
    ]);
}

function resolveDispute(): void {
    $admin = requireAdmin('handle_dispute');
    $db = getDB();
    $data = getJsonInput();

    $disputeId = (int) ($data['dispute_id'] ?? 0);
    $decision = $data['decision'] ?? '';
    if (!$disputeId || !in_array($decision, ['in_review', 'resolved_buyer', 'resolved_seller', 'closed'], true)) {
        jsonError('Amakuru atari yo');
    }

    $stmt = $db->prepare('
        SELECT d.*, o.amount, o.listing_id, o.buyer_id, o.seller_id,
               l.title as listing_title, l.district
        FROM disputes d
        JOIN orders o ON o.id = d.order_id
        JOIN listings l ON l.id = o.listing_id
        WHERE d.id = ?
    ');
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch();
    if (!$dispute) jsonError('Iki kibazo ntikibonetse', 404);

    $scope = adminDistrictScope($admin);
    if ($scope !== null && $dispute['district'] !== $scope) {
        jsonError('Iki kibazo ntikiri mu karere ushinzwe', 403);
    }

    $resolution = trim($data['resolution'] ?? '');
    $amount = (int) $dispute['amount'];
    $escrowStatus = orderEscrowStatus((int) $dispute['order_id']);

    if ($decision === 'resolved_buyer') {
        $db->prepare('UPDATE orders SET status = "refunded" WHERE id = ?')->execute([$dispute['order_id']]);
        if ($escrowStatus === 'held') {
            escrowEntry((int) $dispute['buyer_id'], 'refund', $amount, (int) $dispute['order_id'], 'sandbox', null, 'Dispute refund — ' . $dispute['listing_title']);
        }
        $db->prepare('UPDATE listings SET status = "active" WHERE id = ? AND status = "reserved"')
           ->execute([$dispute['listing_id']]);
    } elseif ($decision === 'resolved_seller') {
        $db->prepare('UPDATE orders SET status = "completed" WHERE id = ?')->execute([$dispute['order_id']]);
        if ($escrowStatus === 'held') {
            escrowEntry((int) $dispute['seller_id'], 'release', $amount, (int) $dispute['order_id'], 'sandbox', null, 'Dispute release — ' . $dispute['listing_title']);
        }
        $db->prepare('UPDATE listings SET status = "sold" WHERE id = ?')->execute([$dispute['listing_id']]);
    } elseif ($decision === 'closed') {
        $db->prepare('UPDATE orders SET status = "paid" WHERE id = ? AND status = "disputed"')
           ->execute([$dispute['order_id']]);
    }

    $db->prepare('UPDATE disputes SET status = ?, resolution_note = ?, assigned_admin_id = ? WHERE id = ?')
       ->execute([$decision, $resolution ?: null, $admin['id'], $disputeId]);

    $message = match ($decision) {
        'in_review' => 'Ikibazo cyawe kiri gusuzumwa',
        'resolved_buyer' => 'Ikibazo cyakemuwe — amafaranga yasubijwe umuguzi',
        'resolved_seller' => 'Ikibazo cyakemuwe — amafaranga yahawe umugurisha',
        default => 'Ikibazo cyafunzwe',
    };
    notify((int) $dispute['buyer_id'], 'dispute', $message, $dispute['listing_title'], 'order', (int) $dispute['order_id']);
    notify((int) $dispute['seller_id'], 'dispute', $message, $dispute['listing_title'], 'order', (int) $dispute['order_id']);

    logAdminAction((int) $admin['id'], 'dispute_' . $decision, 'dispute', $disputeId, $resolution ?: null);
    jsonResponse(['success' => true, 'message' => $message]);
}

function adminAnalytics(): void {
    $admin = requireAdmin('view_analytics');
    $db = getDB();
    $scope = adminViewScope($admin);

    $params = [];
    $clause = $scope ? ' WHERE district = ?' : '';
    if ($scope) $params[] = $scope;

    $byDistrict = $db->prepare("
        SELECT district as label, COUNT(*) as value
        FROM listings$clause
        GROUP BY district ORDER BY value DESC LIMIT 12
    ");
    $byDistrict->execute($params);

    $byMonth = $db->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as label, COUNT(*) as value
        FROM listings$clause
        GROUP BY label ORDER BY label DESC LIMIT 6
    ");
    $byMonth->execute($params);

    $byOrderStatus = $db->prepare('
        SELECT o.status as label, COUNT(*) as value
        FROM orders o
        JOIN listings l ON l.id = o.listing_id
        ' . ($scope ? 'WHERE l.district = ?' : '') . '
        GROUP BY o.status ORDER BY value DESC
    ');
    $byOrderStatus->execute($params);

    $byCategory = $db->prepare("
        SELECT c.name_rw as label, COUNT(*) as value
        FROM listings l
        JOIN categories c ON c.id = l.category_id
        " . ($scope ? 'WHERE l.district = ?' : '') . "
        GROUP BY c.name_rw ORDER BY value DESC LIMIT 8
    ");
    $byCategory->execute($params);

    $revenue = $db->prepare('
        SELECT COALESCE(SUM(e.amount), 0)
        FROM escrow_ledger e
        JOIN orders o ON o.id = e.order_id
        JOIN listings l ON l.id = o.listing_id
        WHERE e.direction = "release" AND e.status = "success"' . ($scope ? ' AND l.district = ?' : '')
    );
    $revenue->execute($params);
    $totalRevenue = (int) $revenue->fetchColumn();

    jsonResponse([
        'success' => true,
        'district_scope' => $scope,
        'by_district' => $byDistrict->fetchAll(),
        'by_month' => array_reverse($byMonth->fetchAll()),
        'by_order_status' => $byOrderStatus->fetchAll(),
        'by_category' => $byCategory->fetchAll(),
        'revenue' => $totalRevenue,
        'revenue_formatted' => formatPrice($totalRevenue),
    ]);
}

/**
 * Regional management: district workload plus moderator performance.
 */
function adminManagement(): void {
    $admin = requireAdmin('view_regional_report');
    $db = getDB();
    $scope = adminViewScope($admin);

    $managedExpression = tableHasColumn('users', 'managed_district')
        ? 'COALESCE(NULLIF(u.managed_district, ""), u.district)'
        : 'u.district';

    $staffParams = [];
    $staffScope = '';
    if ($scope) {
        $staffScope = " AND $managedExpression = ?";
        $staffParams[] = $scope;
    }

    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.role, u.district,
               $managedExpression as managed_district,
               COALESCE(actions.actions_30d, 0) as actions_30d,
               COALESCE(actions.approvals_30d, 0) as approvals_30d,
               COALESCE(actions.disputes_30d, 0) as disputes_30d,
               COALESCE(open_work.open_items, 0) as open_items
        FROM users u
        LEFT JOIN (
            SELECT actor_id,
                   COUNT(*) as actions_30d,
                   SUM(action IN ('listing_approved', 'listing_rejected')) as approvals_30d,
                   SUM(action LIKE 'dispute_%') as disputes_30d
            FROM admin_audit_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY actor_id
        ) actions ON actions.actor_id = u.id
        LEFT JOIN (
            SELECT assigned_admin_id, COUNT(*) as open_items
            FROM disputes
            WHERE status IN ('open', 'in_review') AND assigned_admin_id IS NOT NULL
            GROUP BY assigned_admin_id
        ) open_work ON open_work.assigned_admin_id = u.id
        WHERE u.role IN ('moderator', 'district_manager')$staffScope
        ORDER BY FIELD(u.role, 'district_manager', 'moderator'), actions_30d DESC, u.full_name
    ");
    $stmt->execute($staffParams);
    $staff = $stmt->fetchAll();

    $districtParams = [];
    $districtWhere = '';
    if ($scope) {
        $districtWhere = ' WHERE d.district = ?';
        $districtParams[] = $scope;
    }

    $stmt = $db->prepare("
        SELECT d.district,
               COALESCE(l.active_listings, 0) as active_listings,
               COALESCE(l.pending_listings, 0) as pending_listings,
               COALESCE(r.open_reports, 0) as open_reports,
               COALESCE(x.open_disputes, 0) as open_disputes,
               COALESCE(m.moderators, 0) as moderators
        FROM (
            SELECT district FROM users WHERE district IS NOT NULL AND district <> ''
            UNION
            SELECT district FROM listings WHERE district IS NOT NULL AND district <> ''
        ) d
        LEFT JOIN (
            SELECT district,
                   SUM(status = 'active') as active_listings,
                   SUM(approval_status = 'pending') as pending_listings
            FROM listings GROUP BY district
        ) l ON l.district = d.district
        LEFT JOIN (
            SELECT li.district, COUNT(*) as open_reports
            FROM reports rp
            JOIN listings li ON li.id = rp.target_id AND rp.target_type = 'listing'
            WHERE rp.status = 'open' GROUP BY li.district
        ) r ON r.district = d.district
        LEFT JOIN (
            SELECT li.district, COUNT(*) as open_disputes
            FROM disputes dp
            JOIN orders o ON o.id = dp.order_id
            JOIN listings li ON li.id = o.listing_id
            WHERE dp.status IN ('open', 'in_review') GROUP BY li.district
        ) x ON x.district = d.district
        LEFT JOIN (
            SELECT $managedExpression as district, COUNT(*) as moderators
            FROM users u WHERE u.role = 'moderator'
            GROUP BY $managedExpression
        ) m ON m.district = d.district
        $districtWhere
        ORDER BY d.district
    ");
    $stmt->execute($districtParams);

    jsonResponse([
        'success' => true,
        'district_scope' => $scope,
        'view_role' => adminViewRole($admin),
        'staff' => $staff,
        'districts' => $stmt->fetchAll(),
    ]);
}

function adminSettings(): void {
    $admin = requireAdmin('system_controls');
    $db = getDB();
    $stmt = $db->query('SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key');
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    jsonResponse(['success' => true, 'settings' => $settings, 'role' => $admin['role']]);
}

function saveSettings(): void {
    $admin = requireAdmin('system_controls');
    $data = getJsonInput();
    $allowed = ['require_listing_approval', 'escrow_enabled', 'momo_sandbox', 'platform_fee_percent', 'maintenance_mode'];

    $saved = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            setSetting($key, (string) $data[$key]);
            $saved[] = $key;
        }
    }

    if (!$saved) jsonError('Nta genamiterere ryahinduwe');

    logAdminAction((int) $admin['id'], 'settings_updated', 'system', null, implode(', ', $saved));
    jsonResponse(['success' => true, 'message' => 'System controls zabitswe']);
}

function auditLog(): void {
    $admin = requireAdmin('view_audit_log');
    $stmt = getDB()->query('
        SELECT a.*, u.full_name as admin_name
        FROM admin_audit_logs a
        LEFT JOIN users u ON u.id = a.actor_id
        ORDER BY a.created_at DESC
        LIMIT 100
    ');
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['time_ago'] = timeAgo($r['created_at']);
        $r['details'] = $r['meta_json'];
    }
    jsonResponse(['success' => true, 'entries' => $rows, 'role' => $admin['role']]);
}
