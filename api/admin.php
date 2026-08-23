<?php
/**
 * GUGU App — Admin / Dashboard API (role-based)
 */

require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['ok' => true]);
}

$actor = requireAuth();
$action = $_GET['action'] ?? 'overview';
$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

// Reports can be created by any logged-in user
if ($action === 'create-report') {
    if ($method !== 'POST') jsonError('Method not allowed', 405);
    $data = getJsonInput();
    $targetType = $data['target_type'] ?? '';
    $targetId = (int) ($data['target_id'] ?? 0);
    $reason = trim($data['reason'] ?? '');
    $details = trim($data['details'] ?? '');
    if (!in_array($targetType, ['listing', 'user', 'chat'], true) || !$targetId || $reason === '') {
        jsonError('Invalid report');
    }
    $db->prepare('
        INSERT INTO reports (reporter_id, target_type, target_id, reason, details)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([$actor['id'], $targetType, $targetId, $reason, $details ?: null]);
    jsonResponse(['success' => true, 'message' => 'Report submitted']);
}

requireStaff($actor);
$roleId = (int) $actor['role_id'];
$scopeDistrict = in_array($roleId, [2, 3], true)
    ? trim((string) ($actor['admin_district'] ?? $actor['district'] ?? ''))
    : null;

switch ($action) {
    case 'overview':
        overview($db, $actor, $scopeDistrict);
        break;
    case 'users':
        listUsers($db, $actor, $scopeDistrict);
        break;
    case 'set-role':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        setRole($db, $actor);
        break;
    case 'set-status':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        setStatus($db, $actor, $scopeDistrict);
        break;
    case 'listings':
        listListings($db, $actor, $scopeDistrict);
        break;
    case 'moderate-listing':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        moderateListing($db, $actor, $scopeDistrict);
        break;
    case 'reports':
        listReports($db, $actor, $scopeDistrict);
        break;
    case 'resolve-report':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        resolveReport($db, $actor, $scopeDistrict);
        break;
    case 'my-stats':
        // Allowed for staff too; useful for user dashboard
        myStats($db, $actor);
        break;
    case 'staff-directory':
        staffDirectory($db, $actor);
        break;
    default:
        jsonError('Invalid action', 404);
}

function overview(PDO $db, array $actor, ?string $scopeDistrict): void {
    $roleId = (int) $actor['role_id'];
    $usersTotalSql = 'SELECT COUNT(*) FROM users';
    $listingsActiveSql = 'SELECT COUNT(*) FROM listings WHERE status = "active"';
    $listingsReviewSql = 'SELECT COUNT(*) FROM listings WHERE moderation_status IN ("pending","flagged")';
    $paramsUsers = [];
    $paramsListings = [];
    $paramsReview = [];

    if ($scopeDistrict) {
        $usersTotalSql .= ' WHERE district = ?';
        $paramsUsers[] = $scopeDistrict;
        $listingsActiveSql .= ' AND district = ?';
        $paramsListings[] = $scopeDistrict;
        $listingsReviewSql .= ' AND district = ?';
        $paramsReview[] = $scopeDistrict;
    }

    $stmt = $db->prepare($usersTotalSql);
    $stmt->execute($paramsUsers);
    $usersTotal = (int) $stmt->fetchColumn();

    $stmt = $db->prepare($listingsActiveSql);
    $stmt->execute($paramsListings);
    $listingsActive = (int) $stmt->fetchColumn();

    $stmt = $db->prepare($listingsReviewSql);
    $stmt->execute($paramsReview);
    $listingsReview = (int) $stmt->fetchColumn();

    if ($scopeDistrict) {
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM reports r
            LEFT JOIN listings l ON r.target_type = "listing" AND l.id = r.target_id
            LEFT JOIN users u ON r.target_type = "user" AND u.id = r.target_id
            WHERE r.status IN ("open","reviewing") AND (l.district = ? OR u.district = ?)
        ');
        $stmt->execute([$scopeDistrict, $scopeDistrict]);
        $reportsOpen = (int) $stmt->fetchColumn();
    } else {
        $reportsOpen = (int) $db->query('SELECT COUNT(*) FROM reports WHERE status IN ("open","reviewing")')->fetchColumn();
    }

    $byRole = [];
    // Role matrix is Admin only (national). Support skips user census.
    if ($roleId === 1 || $scopeDistrict) {
        $roleSql = 'SELECT role_id, COUNT(*) as count FROM users';
        $roleParams = [];
        if ($scopeDistrict) {
            $roleSql .= ' WHERE district = ?';
            $roleParams[] = $scopeDistrict;
        }
        $roleSql .= ' GROUP BY role_id ORDER BY role_id';
        $stmt = $db->prepare($roleSql);
        $stmt->execute($roleParams);
        foreach ($stmt->fetchAll() as $row) {
            $rid = (int) $row['role_id'];
            $byRole[] = [
                'role_id' => $rid,
                'role_name' => roleName($rid),
                'count' => (int) $row['count'],
            ];
        }
    }

    // Support portal is queue-first — hide national user census from overview
    if ($roleId === 3) {
        $usersTotal = 0;
        $byRole = [];
    }

    jsonResponse([
        'success' => true,
        'overview' => [
            'users_total' => $usersTotal,
            'listings_active' => $listingsActive,
            'listings_needs_review' => $listingsReview,
            'reports_open' => $reportsOpen,
            'by_role' => $byRole,
            'scope_district' => $scopeDistrict,
            'actor_role' => roleName($roleId),
        ],
    ]);
}

function listUsers(PDO $db, array $actor, ?string $scopeDistrict): void {
    $actorRole = (int) $actor['role_id'];
    // Support desk has no user directory — Super (national) + Regional (scoped) only
    if ($actorRole === 3) {
        jsonError('Moderator / Support portal has no user directory — use listing queue / reports', 403);
    }
    $where = ['1=1'];
    $params = [];
    if ($scopeDistrict) {
        $where[] = 'u.district = ?';
        $params[] = $scopeDistrict;
    }
    if (!empty($_GET['role_id'])) {
        $where[] = 'u.role_id = ?';
        $params[] = (int) $_GET['role_id'];
    }
    if (!empty($_GET['q'])) {
        $where[] = '(u.nickname LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ? OR u.district LIKE ?)';
        $q = '%' . $_GET['q'] . '%';
        array_push($params, $q, $q, $q, $q);
    }
    $sql = '
        SELECT u.id, u.phone, u.nickname, u.full_name, u.district, u.sector,
               u.role_id, u.account_status, u.admin_district, u.manner_score
        FROM users u
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY u.created_at DESC
        LIMIT 100
    ';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    foreach ($users as &$u) {
        $u['role_id'] = (int) $u['role_id'];
        $u['role_name'] = roleName((int) $u['role_id']);
        $u['manner_score'] = (float) $u['manner_score'];
        // Only Admin sees phones of others
        if ((int) $actor['role_id'] !== 1) {
            $u['phone'] = substr($u['phone'], 0, 6) . '****';
            unset($u['full_name']);
        }
    }
    jsonResponse(['success' => true, 'users' => $users]);
}

function setRole(PDO $db, array $actor): void {
    if ((int) $actor['role_id'] !== 1) {
        jsonError('Admin only', 403);
    }
    $data = getJsonInput();
    $userId = (int) ($data['user_id'] ?? 0);
    $newRole = (int) ($data['role_id'] ?? 0);
    $adminDistrict = trim($data['admin_district'] ?? '');
    if (!$userId || $newRole < 1 || $newRole > 4) {
        jsonError('Invalid role');
    }
    if ($userId === (int) $actor['id'] && $newRole !== 1) {
        jsonError('Cannot demote yourself');
    }
    // Only one Admin — never assign role 1 to another account
    if ($newRole === 1 && $userId !== (int) $actor['id']) {
        jsonError('Admin role is reserved — assign District Manager or Moderator only');
    }
    if (in_array($newRole, [2, 3], true) && $adminDistrict === '') {
        jsonError('District Manager and Moderator need admin_district (Akarere)');
    }
    $db->prepare('UPDATE users SET role_id = ?, admin_district = ? WHERE id = ?')->execute([
        $newRole,
        in_array($newRole, [2, 3], true) ? $adminDistrict : null,
        $userId,
    ]);
    syncAccountKind($db, $userId, $newRole);
    writeAuditLog((int) $actor['id'], 'set-role', 'user', $userId, [
        'role_id' => $newRole,
        'admin_district' => $adminDistrict ?: null,
    ]);
    jsonResponse(['success' => true, 'message' => 'Role updated']);
}

function setStatus(PDO $db, array $actor, ?string $scopeDistrict): void {
    $data = getJsonInput();
    $userId = (int) ($data['user_id'] ?? 0);
    $status = $data['account_status'] ?? '';
    if (!$userId || !in_array($status, ['active', 'suspended', 'banned'], true)) {
        jsonError('Invalid status');
    }
    if ($userId === (int) $actor['id']) {
        jsonError('Cannot change your own status');
    }
    $stmt = $db->prepare('SELECT id, role_id, district FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) jsonError('User not found', 404);

    $actorRole = (int) $actor['role_id'];
    // Admin (role 1) — full control over members and staff (except self)
    if ($actorRole === 1) {
        // nationwide — no further limits
    } elseif ($actorRole === 3) {
        // Trust & Safety may suspend or ban members (not staff)
        if ((int) $target['role_id'] <= 3) jsonError('Cannot change staff accounts', 403);
    } elseif ($actorRole === 2) {
        if ((int) $target['role_id'] <= 2) jsonError('Cannot change this account', 403);
        if ($status === 'banned') jsonError('District Manager may suspend only — escalate bans to Trust & Safety', 403);
        if ($scopeDistrict && $target['district'] !== $scopeDistrict) {
            jsonError('Outside your district', 403);
        }
    } else {
        jsonError('Staff access only', 403);
    }

    $db->prepare('UPDATE users SET account_status = ? WHERE id = ?')->execute([$status, $userId]);
    writeAuditLog((int) $actor['id'], 'set-status', 'user', $userId, ['account_status' => $status]);
    jsonResponse(['success' => true, 'message' => 'Status updated']);
}

function listListings(PDO $db, array $actor, ?string $scopeDistrict): void {
    $where = ['1=1'];
    $params = [];
    if ($scopeDistrict) {
        $where[] = 'l.district = ?';
        $params[] = $scopeDistrict;
    }
    if (!empty($_GET['moderation_status'])) {
        $where[] = 'l.moderation_status = ?';
        $params[] = $_GET['moderation_status'];
    }
    if (!empty($_GET['needs_review'])) {
        $where[] = 'l.moderation_status IN ("pending","flagged")';
    }
    $sql = '
        SELECT l.id, l.title, l.price, l.district, l.sector, l.status, l.moderation_status,
               l.created_at, l.user_id as seller_id, u.nickname, u.phone
        FROM listings l
        JOIN users u ON u.id = l.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY l.created_at DESC
        LIMIT 100
    ';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $listings = $stmt->fetchAll();
    foreach ($listings as &$l) {
        if ((int) $actor['role_id'] !== 1) {
            $l['phone'] = isset($l['phone']) ? substr($l['phone'], 0, 6) . '****' : '';
        }
    }
    jsonResponse(['success' => true, 'listings' => $listings]);
}

function moderateListing(PDO $db, array $actor, ?string $scopeDistrict): void {
    $data = getJsonInput();
    $listingId = (int) ($data['listing_id'] ?? 0);
    $status = $data['moderation_status'] ?? '';
    if (!$listingId || !in_array($status, ['approved', 'pending', 'flagged', 'rejected'], true)) {
        jsonError('Invalid moderation status');
    }
    $stmt = $db->prepare('SELECT id, district FROM listings WHERE id = ?');
    $stmt->execute([$listingId]);
    $listing = $stmt->fetch();
    if (!$listing) jsonError('Listing not found', 404);
    if ($scopeDistrict && $listing['district'] !== $scopeDistrict) {
        jsonError('Outside your district', 403);
    }
    $db->prepare('UPDATE listings SET moderation_status = ? WHERE id = ?')->execute([$status, $listingId]);
    if ($status === 'approved') {
        $db->prepare('UPDATE listings SET payment_status = "paid", paid_at = COALESCE(paid_at, NOW()), status = "active" WHERE id = ?')
           ->execute([$listingId]);
    }
    if ($status === 'rejected') {
        $db->prepare('UPDATE listings SET status = "sold" WHERE id = ?')->execute([$listingId]);
    }
    writeAuditLog((int) $actor['id'], 'moderate-listing', 'listing', $listingId, [
        'moderation_status' => $status,
    ]);
    jsonResponse(['success' => true, 'message' => 'Listing updated']);
}

function listReports(PDO $db, array $actor, ?string $scopeDistrict): void {
    $status = $_GET['status'] ?? 'open';
    $where = ['1=1'];
    $params = [];
    if ($status && $status !== 'all') {
        if ($status === 'open') {
            $where[] = 'r.status IN ("open","reviewing")';
        } else {
            $where[] = 'r.status = ?';
            $params[] = $status;
        }
    }
    $join = '';
    if ($scopeDistrict) {
        $join = '
            LEFT JOIN listings l ON r.target_type = "listing" AND l.id = r.target_id
            LEFT JOIN users u ON r.target_type = "user" AND u.id = r.target_id
        ';
        $where[] = '(l.district = ? OR u.district = ?)';
        $params[] = $scopeDistrict;
        $params[] = $scopeDistrict;
    }
    $stmt = $db->prepare('
        SELECT r.*
        FROM reports r
        ' . $join . '
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY r.created_at DESC
        LIMIT 100
    ');
    $stmt->execute($params);
    jsonResponse(['success' => true, 'reports' => $stmt->fetchAll()]);
}

function resolveReport(PDO $db, array $actor, ?string $scopeDistrict): void {
    $data = getJsonInput();
    $reportId = (int) ($data['report_id'] ?? 0);
    $status = $data['status'] ?? '';
    $note = trim($data['resolution_note'] ?? '');
    if (!$reportId || !in_array($status, ['resolved', 'dismissed', 'reviewing'], true)) {
        jsonError('Invalid report status');
    }
    if ($scopeDistrict) {
        $chk = $db->prepare('
            SELECT r.id FROM reports r
            LEFT JOIN listings l ON r.target_type = "listing" AND l.id = r.target_id
            LEFT JOIN users u ON r.target_type = "user" AND u.id = r.target_id
            WHERE r.id = ? AND (l.district = ? OR u.district = ?)
            LIMIT 1
        ');
        $chk->execute([$reportId, $scopeDistrict, $scopeDistrict]);
        if (!$chk->fetch()) {
            jsonError('Report outside your region', 403);
        }
    }
    $db->prepare('
        UPDATE reports SET status = ?, handled_by = ?, resolution_note = ? WHERE id = ?
    ')->execute([$status, $actor['id'], $note ?: null, $reportId]);
    writeAuditLog((int) $actor['id'], 'resolve-report', 'report', $reportId, ['status' => $status]);
    jsonResponse(['success' => true, 'message' => 'Report updated']);
}

function myStats(PDO $db, array $actor): void {
    $uid = (int) $actor['id'];
    $s = $db->prepare('SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = "active"');
    $s->execute([$uid]);
    $active = (int) $s->fetchColumn();
    $s = $db->prepare('SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = "sold"');
    $s->execute([$uid]);
    $sold = (int) $s->fetchColumn();
    $s = $db->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
    $s->execute([$uid]);
    $fav = (int) $s->fetchColumn();
    jsonResponse([
        'success' => true,
        'stats' => [
            'active_listings' => $active,
            'sold_listings' => $sold,
            'favorites_count' => $fav,
        ],
    ]);
}

/** Team roster for My GUGU — any management user can see staff (no member PII). */
function staffDirectory(PDO $db, array $actor): void {
    $actorRole = (int) $actor['role_id'];
    $sql = '
        SELECT id, nickname, district, sector, role_id, account_status, admin_district
        FROM users
        WHERE role_id BETWEEN 1 AND 3
        ORDER BY role_id ASC, nickname ASC
        LIMIT 50
    ';
    $staff = $db->query($sql)->fetchAll();
    foreach ($staff as &$row) {
        $rid = (int) $row['role_id'];
        $row['role_id'] = $rid;
        $row['role_name'] = roleName($rid);
        $row['role_label'] = roleLabel($rid);
        $row['is_you'] = ((int) $row['id'] === (int) $actor['id']);
        // Only Admin sees phones elsewhere; directory stays name/role only
        unset($row['phone'], $row['email'], $row['full_name']);
    }
    jsonResponse([
        'success' => true,
        'staff' => $staff,
        'viewer_role_id' => $actorRole,
        'viewer_role_label' => roleLabel($actorRole),
    ]);
}
