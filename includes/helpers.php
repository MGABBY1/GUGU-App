<?php
/**
 * GUGU App - Helper Functions
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_i18n.php';

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Gugu-Token, X-Gugu-Lang');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    jsonResponse(['success' => false, 'error' => $message], $code);
}

function getJsonInput(): array {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

function generateToken(): string {
    return bin2hex(random_bytes(32));
}

function formatPhone(string $phone): string {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (str_starts_with($phone, '0')) {
        $phone = '+250' . substr($phone, 1);
    } elseif (str_starts_with($phone, '250')) {
        $phone = '+' . $phone;
    } elseif (!str_starts_with($phone, '+')) {
        $phone = '+250' . $phone;
    }
    return $phone;
}

function validateRwandaPhone(string $phone): bool {
    $phone = formatPhone($phone);
    return (bool) preg_match('/^\+2507[2389]\d{7}$/', $phone);
}

/** Digits-only OTP, fixed length (rejects empty / short codes). */
function normalizeOtp(string $code): string {
    $digits = preg_replace('/\D/', '', $code) ?? '';
    $len = defined('OTP_LENGTH') ? (int) OTP_LENGTH : 6;
    return strlen($digits) === $len ? $digits : '';
}

/**
 * Latest unused, unexpired OTP for phone (MySQL clock).
 *
 * @return array<string,mixed>|null
 */
function findActiveOtp(string $phone): ?array {
    $stmt = getDB()->prepare('
        SELECT * FROM otp_codes
        WHERE phone = ? AND used_at IS NULL AND expires_at > NOW()
        ORDER BY id DESC LIMIT 1
    ');
    $stmt->execute([$phone]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function assertOtpMatches(?array $row, string $code): void {
    $code = normalizeOtp($code);
    if ($code === '') {
        jsonErrorKey('otp_invalid', 401);
    }
    if (!$row) {
        jsonErrorKey('otp_expired', 401);
    }
    $max = defined('OTP_MAX_ATTEMPTS') ? (int) OTP_MAX_ATTEMPTS : 5;
    if ((int) ($row['attempts'] ?? 0) >= $max) {
        jsonErrorKey('otp_expired', 401);
    }
    $stored = normalizeOtp((string) ($row['code'] ?? ''));
    if ($stored === '' || !hash_equals($stored, $code)) {
        getDB()->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')
            ->execute([(int) $row['id']]);
        jsonErrorKey('otp_invalid', 401);
    }
}

function markOtpUsed(int $otpId): void {
    getDB()->prepare('UPDATE otp_codes SET used_at = NOW() WHERE id = ?')->execute([$otpId]);
}

function startAppSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function formatPrice(int $price): string {
    if ($price === 0) return 'Ubuntu';
    return number_format($price, 0, '.', ',') . ' FRW';
}

function getAuthUser(): ?array {
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    if ($token === '') {
        $token = (string) ($_SERVER['HTTP_X_GUGU_TOKEN'] ?? $_POST['token'] ?? '');
    }
    if ($token === '') return null;

    $db = getDB();
    $stmt = $db->prepare('
        SELECT u.* FROM users u
        JOIN sessions s ON s.user_id = u.id
        WHERE s.id = ? AND s.expires_at > NOW()
    ');
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if ($user) {
        unset($user['password_hash']);
        $user = enrichUserFlags($user);
    }
    return $user ?: null;
}

/** Role 1–3 = staff (Admin / District Manager / Moderator). */
function isStaffRoleId(int $roleId): bool {
    return $roleId >= 1 && $roleId <= 3;
}

/** Jobs category id in `categories` (Akazi). */
function guguJobCategoryId(): int {
    return defined('GUGU_JOB_CATEGORY_ID') ? (int) GUGU_JOB_CATEGORY_ID : 11;
}

/** item | job — separate marketplace businesses. */
function guguBusinessTypeFromCategory(int $categoryId): string {
    return $categoryId === guguJobCategoryId() ? 'job' : 'item';
}

function guguAnnounceFeeForBusiness(string $businessType): int {
    if ($businessType === 'job') {
        return defined('GUGU_JOB_ANNOUNCE_FEE_RWF')
            ? (int) GUGU_JOB_ANNOUNCE_FEE_RWF
            : (int) (defined('GUGU_ANNOUNCE_FEE_RWF') ? GUGU_ANNOUNCE_FEE_RWF : 1000);
    }
    return defined('GUGU_ITEM_ANNOUNCE_FEE_RWF')
        ? (int) GUGU_ITEM_ANNOUNCE_FEE_RWF
        : (int) (defined('GUGU_ANNOUNCE_FEE_RWF') ? GUGU_ANNOUNCE_FEE_RWF : 1000);
}

function guguBusinessLabel(string $businessType): string {
    return $businessType === 'job' ? 'Job' : 'Item';
}

function roleName(int $roleId): string {
    return match ($roleId) {
        1 => 'Admin',
        2 => 'District Manager',
        3 => 'Moderator / Support',
        4 => 'Member',
        default => 'Guest',
    };
}

/** Display label for management/member roles (Admin only — no Super Admin). */
function roleLabel(int $roleId): string {
    return roleName($roleId);
}

function requireStaff(array $actor): void {
    $roleId = (int) ($actor['role_id'] ?? 0);
    if (!isStaffRoleId($roleId)) {
        jsonError('Staff access only', 403);
    }
    $status = (string) ($actor['account_status'] ?? 'active');
    if (in_array($status, ['suspended', 'banned'], true) || !empty($actor['is_banned'])) {
        jsonError('Account suspended', 403);
    }
}

function syncAccountKind(PDO $db, int $userId, int $roleId): void {
    $kind = isStaffRoleId($roleId) ? 'management' : 'member';
    try {
        $db->prepare('UPDATE users SET account_kind = ? WHERE id = ?')->execute([$kind, $userId]);
    } catch (Throwable $e) {
        // older schemas may lack account_kind
    }
    // Keep legacy string role column in sync (ENUM still uses super_admin for Admin)
    if (tableHasColumn('users', 'role')) {
        $legacyRole = match ($roleId) {
            1 => 'super_admin',
            2 => 'district_manager',
            3 => 'moderator',
            default => 'member',
        };
        try {
            $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$legacyRole, $userId]);
        } catch (Throwable $e) {
            // ignore ENUM / missing column issues
        }
    }
}

function writeAuditLog(int $actorId, string $action, ?string $targetType = null, ?int $targetId = null, array $meta = []): void {
    try {
        getDB()->prepare('
            INSERT INTO admin_audit_logs (actor_id, action, target_type, target_id, meta_json)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([
            $actorId,
            $action,
            $targetType,
            $targetId,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        // never break portal actions
    }
}

/**
 * When Admin has sticky DM/Moderator preview in PHP session, expose it to the
 * React marketplace so identity chrome can match the portal they came from.
 *
 * @return array{role:int,district:string}|null
 */
function stickyPortalViewForUser(array $user): ?array {
    if ((int) ($user['role_id'] ?? 0) !== 1) {
        return null;
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $role = (int) ($_SESSION['portal_preview_role'] ?? 0);
    if ($role !== 2 && $role !== 3) {
        return null;
    }
    return [
        'role' => $role,
        'district' => trim((string) ($_SESSION['portal_preview_district'] ?? '')),
    ];
}

function enrichUserFlags(array $user): array {
    $roleId = (int) ($user['role_id'] ?? 4);
    $user['role_id'] = $roleId;
    $user['is_staff'] = isStaffRoleId($roleId);
    $user['is_management'] = $user['is_staff'];
    $user['account_kind'] = $user['is_staff'] ? 'management' : 'member';
    $user['is_member'] = !$user['is_staff'];
    $user['is_admin'] = $roleId === 1;
    $user['role_name'] = roleName($roleId);
    $user['role_label'] = roleLabel($roleId);
    $portalView = stickyPortalViewForUser($user);
    if ($portalView !== null) {
        $user['portal_view'] = $portalView;
    } else {
        unset($user['portal_view']);
    }
    // Admin (role 1) = full former Super Admin control
    $user['can_manage_roles'] = $roleId === 1;
    $user['can_manage_staff'] = $roleId === 1;
    $user['can_system_controls'] = $roleId === 1;
    $user['can_view_all_districts'] = $roleId === 1;
    $user['can_ban'] = $roleId === 1 || $roleId === 3;
    $user['permissions'] = rolePermissions(roleKeyFromId($roleId));
    $nick = trim((string) ($user['nickname'] ?? ''));
    $user['needs_profile'] = $nick === '';

    $daysWindow = defined('LOCATION_VERIFY_DAYS') ? (int) LOCATION_VERIFY_DAYS : 30;
    $verifiedAt = $user['location_verified_at'] ?? null;
    $locationOk = false;
    $daysLeft = null;
    if (!empty($verifiedAt)) {
        $ts = strtotime((string) $verifiedAt);
        if ($ts !== false) {
            $expires = $ts + ($daysWindow * 86400);
            $remaining = (int) ceil(($expires - time()) / 86400);
            $locationOk = $remaining > 0;
            $daysLeft = max(0, $remaining);
        }
    }
    $user['location_ok'] = $locationOk;
    $user['location_days_left'] = $daysLeft;
    $user['needs_location'] = !$locationOk;

    $idStatus = (string) ($user['id_status'] ?? 'none');
    if ($idStatus === '') {
        $idStatus = 'none';
    }
    $user['id_status'] = $idStatus;
    // Must upload (or re-upload after reject) before using the member app
    $user['needs_id_upload'] = in_array($idStatus, ['none', 'rejected'], true);
    // True until Admin approves (pending still counts as "not fully verified")
    $user['needs_id_verification'] = $idStatus !== 'approved';
    $user['id_verified'] = $idStatus === 'approved';
    if ($user['is_staff']) {
        $user['needs_profile'] = false;
        $user['needs_location'] = false;
        $user['needs_id_upload'] = false;
        $user['needs_id_verification'] = false;
        $user['id_verified'] = true;
    }
    return $user;
}

/**
 * Members must have Admin-approved ID before posting items/jobs (platform security).
 */
function requireMemberIdApproved(array $user): void {
    $roleId = (int) ($user['role_id'] ?? 4);
    if ($roleId >= 1 && $roleId <= 3) {
        return; // staff
    }
    $status = (string) ($user['id_status'] ?? 'none');
    if ($status === 'approved') {
        return;
    }
    if ($status === 'pending') {
        jsonError('ID yawe iracyasuzumwa. Tegereza Admin mbere yo kugurisha / gutangaza akazi.', 403);
    }
    if ($status === 'rejected') {
        jsonError('ID yawe yanze. Ongera wohereze ifoto isobanutse mbere yo kugurisha.', 403);
    }
    jsonError('Banza wohereze ifoto y\'indangamuntu (ID) mbere yo kugurisha / gutangaza akazi.', 403);
}

function startStaffPhpSession(array $user): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => defined('SESSION_LIFETIME') ? (int) SESSION_LIFETIME : 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role_id'] = (int) ($user['role_id'] ?? 4);
    $_SESSION['nickname'] = $user['nickname'] ?? $user['full_name'] ?? 'Staff';
    $_SESSION['phone'] = $user['phone'] ?? '';
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['district'] = $user['district'] ?? '';
    $_SESSION['admin_district'] = $user['admin_district'] ?? ($user['district'] ?? '');
}

function clearStaffPhpSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

function requireAuth(): array {
    $user = getAuthUser();
    if (!$user) {
        jsonErrorKey('login_required', 401);
    }
    if (!empty($user['is_banned'])) {
        jsonErrorKey('account_banned', 403);
    }
    return $user;
}

/**
 * Guards portal features so the app keeps working if setup.php has not been re-run yet.
 */
function tableHasColumn(string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    try {
        $stmt = getDB()->prepare('
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ');
        $stmt->execute([$table, $column]);
        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/* ─────────── Roles & permissions (Administrative Portal) ─────────── */

/** Staff string keys — Admin is the top tier (same powers as former Super Admin). */
const ADMIN_ROLES = ['moderator', 'district_manager', 'admin', 'super_admin'];

/**
 * Legacy admins kept so the original backend login never breaks.
 */
function legacyAdminPhones(): array {
    return ['+250789999999'];
}

/** Map role_id → permission key. Role 1 Admin = full system control. */
function roleKeyFromId(int $roleId): string {
    return match ($roleId) {
        1 => 'admin',
        2 => 'district_manager',
        3 => 'moderator',
        default => 'member',
    };
}

function userRole(array $user): string {
    // Prefer numeric role_id (live system)
    if (isset($user['role_id'])) {
        $rid = (int) $user['role_id'];
        if ($rid >= 1 && $rid <= 3) {
            return roleKeyFromId($rid);
        }
    }
    $role = (string) ($user['role'] ?? 'member');
    if ($role === 'super_admin') {
        return 'admin'; // alias — same full access
    }
    if ($role === 'member' && in_array($user['phone'] ?? '', legacyAdminPhones(), true)) {
        return 'admin';
    }
    return $role;
}

function isAdminUser(array $user): bool {
    if (isStaffRoleId((int) ($user['role_id'] ?? 0))) {
        return true;
    }
    return in_array(userRole($user), ADMIN_ROLES, true);
}

/** True for the single platform Administrator (role 1). */
function isSystemAdmin(array $user): bool {
    return (int) ($user['role_id'] ?? 0) === 1 || userRole($user) === 'admin';
}

/**
 * Permission matrix — Admin has every former Super Admin permission.
 */
function rolePermissions(string $role): array {
    $moderator = [
        'view_dashboard', 'view_moderation', 'approve_listing', 'reject_listing',
        'view_listings', 'view_users', 'view_disputes', 'review_id', 'resolve_report',
        'suspend_user', 'ban_user',
    ];
    // District Manager — Akarere only: pay/approve/reject, members & Moderators activate/suspend, local reports
    $districtManager = [
        'view_dashboard', 'view_moderation', 'approve_listing', 'reject_listing',
        'view_listings', 'view_users', 'view_disputes', 'resolve_report',
        'delete_listing', 'verify_user', 'suspend_user', 'suspend_staff',
        'handle_dispute', 'view_analytics', 'view_regional_report', 'mark_paid',
    ];
    // Admin = full system control (nationwide + roles + settings + bans + audit)
    $admin = array_merge($districtManager, [
        'ban_user', 'manage_roles', 'manage_staff', 'system_controls', 'review_id',
        'view_audit_log', 'view_all_districts', 'view_financials',
        'preview_dashboards',
    ]);

    return match ($role) {
        'moderator' => $moderator,
        'district_manager' => $districtManager,
        'admin', 'super_admin' => $admin,
        default => [],
    };
}

function roleCan(string $role, string $permission): bool {
    if ($role === 'super_admin') {
        $role = 'admin';
    }
    return in_array($permission, rolePermissions($role), true);
}

function requireAdmin(?string $permission = null): array {
    $user = requireAuth();
    if (!isAdminUser($user)) {
        jsonError('Ntufite uburenganzira bwo kwinjira hano', 403);
    }
    $user['role'] = userRole($user);
    if ($permission !== null && !roleCan($user['role'], $permission)) {
        jsonError('Uru ruhare ntirwemerewe iki gikorwa (permission denied)', 403);
    }
    return $user;
}

/**
 * District Managers and Moderators only see their own Akarere.
 * Admin (role 1) sees all districts nationwide.
 */
function adminDistrictScope(array $admin): ?string {
    if ((int) ($admin['role_id'] ?? 0) === 1 || roleCan(userRole($admin), 'view_all_districts')) {
        return null;
    }
    $scope = $admin['admin_district'] ?? $admin['managed_district'] ?? '';
    return $scope !== '' ? $scope : ($admin['district'] ?? null);
}

function logAdminAction(int $adminId, string $action, ?string $targetType = null, ?int $targetId = null, ?string $details = null): void {
    try {
        getDB()->prepare('
            INSERT INTO admin_audit_logs (actor_id, action, target_type, target_id, meta_json)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$adminId, $action, $targetType, $targetId, $details]);
    } catch (PDOException $e) {
        // Audit logging must never break an admin action
    }
}

/* ─────────── System controls ─────────── */

function getSetting(string $key, string $default = ''): string {
    try {
        $stmt = getDB()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    } catch (PDOException $e) {
        return $default;
    }
}

function setSetting(string $key, string $value): void {
    getDB()->prepare('
        INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ')->execute([$key, $value]);
}

/* ─────────── Notifications ─────────── */

/**
 * Link is stored as "order:12" / "listing:5" to match the shared notifications table.
 */
function notify(int $userId, string $type, string $title, ?string $body = null, ?string $linkType = null, ?int $linkId = null): void {
    $link = ($linkType && $linkId) ? $linkType . ':' . $linkId : null;
    try {
        getDB()->prepare('
            INSERT INTO notifications (user_id, type, title, body, link)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$userId, $type, $title, $body, $link]);
    } catch (PDOException $e) {
        // Notifications are best-effort
    }
}

/* ─────────── Escrow wallet ─────────── */

function escrowEntry(int $userId, string $direction, int $amount, ?int $orderId = null, string $provider = 'sandbox', ?string $providerRef = null, ?string $meta = null): void {
    getDB()->prepare('
        INSERT INTO escrow_ledger (order_id, user_id, direction, amount, provider, provider_ref, status, meta)
        VALUES (?, ?, ?, ?, ?, ?, "success", ?)
    ')->execute([$orderId, $userId, $direction, $amount, $provider, $providerRef, $meta]);

    syncWallet($userId);
}

/**
 * Keeps the wallets table in step with the escrow ledger.
 */
function syncWallet(int $userId): void {
    $summary = walletSummary($userId);
    try {
        getDB()->prepare('
            INSERT INTO wallets (user_id, available_balance, held_balance) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE available_balance = VALUES(available_balance), held_balance = VALUES(held_balance)
        ')->execute([$userId, $summary['earned'] + $summary['refunded'], $summary['held']]);
    } catch (PDOException $e) {
        // Wallet cache is derived data
    }
}

/**
 * Escrow totals derived from the ledger so both portals agree.
 */
function walletSummary(int $userId): array {
    $db = getDB();
    $sum = function (string $direction) use ($db, $userId): int {
        $stmt = $db->prepare('
            SELECT COALESCE(SUM(amount), 0) FROM escrow_ledger
            WHERE user_id = ? AND direction = ? AND status = "success"
        ');
        $stmt->execute([$userId, $direction]);
        return (int) $stmt->fetchColumn();
    };

    $held = $sum('hold');
    $released = $sum('release');
    $refunded = $sum('refund');

    return [
        'held' => max(0, $held - $released - $refunded),
        'earned' => $sum('credit') + $released,
        'spent' => $held,
        'refunded' => $refunded,
    ];
}

function generatePaymentRef(string $method): string {
    $prefix = $method === 'airtel_money' ? 'AIRTEL' : ($method === 'cash' ? 'CASH' : 'MOMO');
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function providerForMethod(string $method): string {
    return match ($method) {
        'airtel_money' => 'airtel',
        'mtn_momo' => 'mtn',
        default => 'sandbox',
    };
}

function generateTrackCode(): string {
    return 'GG' . strtoupper(bin2hex(random_bytes(5)));
}

/**
 * Escrow state for an order, derived from its ledger rows.
 */
function orderEscrowStatus(int $orderId): string {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT direction FROM escrow_ledger
        WHERE order_id = ? AND status = "success"
    ');
    $stmt->execute([$orderId]);
    $directions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('refund', $directions, true)) return 'refunded';
    if (in_array('release', $directions, true)) return 'released';
    if (in_array('hold', $directions, true)) return 'held';
    return 'unpaid';
}

function handleImageUpload(array $file): ?string {
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) return null;
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > MAX_UPLOAD_SIZE) return null;

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return null;

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string) finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    if ($mime === '' || $mime === 'application/octet-stream') {
        $mime = (string) ($file['type'] ?? '');
    }

    $extFromName = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $ext = $mimeMap[$mime] ?? null;
    if ($ext === null && in_array($extFromName, $allowedExt, true)) {
        $ext = $extFromName === 'jpeg' ? 'jpg' : $extFromName;
    }
    if ($ext === null) {
        // Last resort: detect via getimagesize
        $info = @getimagesize($tmp);
        if (is_array($info) && !empty($info['mime']) && isset($mimeMap[$info['mime']])) {
            $ext = $mimeMap[$info['mime']];
        }
    }
    if ($ext === null) return null;

    $filename = uniqid('gugu_', true) . '.' . $ext;
    $dest = UPLOAD_DIR . $filename;

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    if (move_uploaded_file($tmp, $dest)) {
        return $filename;
    }
    // Windows/XAMPP fallback when move_uploaded_file fails on some temp paths
    if (@copy($tmp, $dest)) {
        @unlink($tmp);
        return $filename;
    }
    return null;
}

/** Public URL for an uploaded file (listing photos, ID docs). */
function publicUploadUrl(?string $path): string {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    // Already a site-absolute uploads URL
    if (str_starts_with($path, '/gugu-app/')) {
        return $path;
    }
    $path = ltrim(str_replace('\\', '/', $path), '/');
    // Prefer media.php so Content-Type is always correct for jpg/png/webp/gif
    return '/gugu-app/api/media.php?f=' . rawurlencode($path);
}

/**
 * Normalize $_FILES entries for images / images[] / image.
 *
 * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function collectListingUploadFiles(): array {
    $bag = null;
    foreach (['images', 'images[]', 'image', 'photos', 'photos[]'] as $key) {
        if (!empty($_FILES[$key]) && is_array($_FILES[$key])) {
            $bag = $_FILES[$key];
            break;
        }
    }
    if ($bag === null) {
        foreach ($_FILES as $key => $val) {
            if (is_string($key) && str_starts_with($key, 'images') && is_array($val)) {
                $bag = $val;
                break;
            }
        }
    }
    if ($bag === null) {
        return [];
    }

    $out = [];
    if (is_array($bag['name'] ?? null)) {
        $count = count($bag['name']);
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'name' => (string) ($bag['name'][$i] ?? ''),
                'type' => (string) ($bag['type'][$i] ?? ''),
                'tmp_name' => (string) ($bag['tmp_name'][$i] ?? ''),
                'error' => (int) ($bag['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($bag['size'][$i] ?? 0),
            ];
        }
    } else {
        $out[] = [
            'name' => (string) ($bag['name'] ?? ''),
            'type' => (string) ($bag['type'] ?? ''),
            'tmp_name' => (string) ($bag['tmp_name'] ?? ''),
            'error' => (int) ($bag['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($bag['size'] ?? 0),
        ];
    }
    return array_values(array_filter($out, static fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE || ($f['size'] ?? 0) > 0));
}

function getRwandaLocations(): array {
    return [
        'Kigali' => [
            'Gasabo' => ['Bumbogo', 'Gatsata', 'Jali', 'Gikomero', 'Gisozi', 'Jabana', 'Kinyinya', 'Ndera', 'Nduba', 'Remera', 'Kacyiru', 'Kimihurura', 'Kimironko', 'Kinyinya', 'Nyarutarama'],
            'Kicukiro' => ['Gahanga', 'Gatenga', 'Gikondo', 'Kagarama', 'Kanombe', 'Kicukiro', 'Kigarama', 'Masaka', 'Niboye', 'Nyarugunga'],
            'Nyarugenge' => ['Gitega', 'Kanyinya', 'Kigali', 'Kimisagara', 'Mageragere', 'Muhima', 'Nyakabanda', 'Nyamirambo', 'Nyarugenge', 'Rwezamenyo']
        ],
        'Northern Province' => [
            'Burera' => ['Bungwe', 'Butaro', 'Cyanika', 'Cyeru', 'Gahunga', 'Gatebe', 'Gitovu', 'Kagogo', 'Kinoni', 'Kinyababa'],
            'Gakenke' => ['Busengo', 'Coko', 'Cyabingo', 'Gakenke', 'Gashenyi', 'Mugunga', 'Janja', 'Kamubuga', 'Karambo', 'Kivumu'],
            'Gicumbi' => ['Bukure', 'Bwisige', 'Byumba', 'Cyumba', 'Giti', 'Kaniga', 'Manyagiro', 'Miyove', 'Kageyo', 'Mukarange'],
            'Musanze' => ['Busogo', 'Cyuve', 'Gacaca', 'Gashaki', 'Gataraga', 'Kimonyi', 'Kinigi', 'Muhoza', 'Muko', 'Nkotsi'],
            'Rulindo' => ['Base', 'Burega', 'Bushoki', 'Buyoga', 'Cyinzuzi', 'Cyungo', 'Kinihira', 'Kisaro', 'Masoro', 'Mbogo']
        ],
        'Southern Province' => [
            'Gisagara' => ['Gikonko', 'Gishubi', 'Kansi', 'Kibirizi', 'Kigembe', 'Mamba', 'Muganza', 'Musha', 'Ndora', 'Save'],
            'Huye' => ['Gishamvu', 'Karama', 'Kigoma', 'Kinazi', 'Maraba', 'Mbazi', 'Mukura', 'Ngoma', 'Ruhashya', 'Rusatira'],
            'Kamonyi' => ['Gacurabwenge', 'Karama', 'Kayenzi', 'Kayumbu', 'Mugina', 'Musambira', 'Ngamba', 'Nyamiyaga', 'Rugalika', 'Rukoma'],
            'Muhanga' => ['Cyeza', 'Kabacuzi', 'Kibangu', 'Kiyumba', 'Muhanga', 'Mushishiro', 'Nyabinoni', 'Nyamabuye', 'Nyarusange', 'Rongi'],
            'Nyamagabe' => ['Buruhukiro', 'Cuanika', 'Gasaka', 'Gatare', 'Kaduha', 'Kamegeli', 'Kibirizi', 'Kibumbwe', 'Kitabi', 'Mbazi'],
            'Nyanza' => ['Busasamana', 'Busoro', 'Cyabakamyi', 'Kibirizi', 'Kigoma', 'Mukingo', 'Muyira', 'Ntyazo', 'Nyagisozi', 'Rwabicuma'],
            'Nyaruguru' => ['Busanze', 'Cyahinda', 'Kibeho', 'Kivu', 'Mata', 'Munini', 'Ngera', 'Ngoma', 'Nyabimata', 'Nyagisozi'],
            'Ruhango' => ['Bweramana', 'Byimana', 'Kabagali', 'Kinazi', 'Kinihira', 'Mbuye', 'Mwendo', 'Ntongwe', 'Ruhango', 'Rusatira']
        ],
        'Eastern Province' => [
            'Bugesera' => ['Gashora', 'Juru', 'Kamabuye', 'Mareba', 'Mayange', 'Musenyi', 'Mwogo', 'Ngeruka', 'Ntarama', 'Nyamata'],
            'Gatsibo' => ['Gasange', 'Gatsibo', 'Gitoki', 'Kabarore', 'Kageyo', 'Kiramuruzi', 'Kiziguro', 'Muhura', 'Murambi', 'Ngarama'],
            'Kayonza' => ['Gahini', 'Kabare', 'Kabarondo', 'Mukarange', 'Murama', 'Murundi', 'Mwiri', 'Ndego', 'Nyamirama', 'Rukara'],
            'Kirehe' => ['Gahara', 'Gatore', 'Kigarama', 'Kigina', 'Kirehe', 'Mahama', 'Mpanga', 'Musaza', 'Mushikiri', 'Nasho'],
            'Ngoma' => ['Gashanda', 'Jarama', 'Karembo', 'Kazo', 'Kibungo', 'Mugesera', 'Murama', 'Mutenderi', 'Remera', 'Rukira'],
            'Nyagatare' => ['Gatunda', 'Karangazi', 'Katabagemu', 'Kiyombe', 'Matimba', 'Mimuli', 'Mukama', 'Musheri', 'Nyagatare', 'Rukomo'],
            'Rwamagana' => ['Fumbwe', 'Gahengeri', 'Gishali', 'Karenge', 'Kigabiro', 'Muhazi', 'Munyaga', 'Munyiginya', 'Musha', 'Nyakaliro']
        ],
        'Western Province' => [
            'Karongi' => ['Bwishyura', 'Gashari', 'Gishyita', 'Gitesi', 'Mubuga', 'Murambi', 'Murundi', 'Mutuntu', 'Rubengera', 'Rugabano'],
            'Ngororero' => ['Bwira', 'Gatumba', 'Hindiro', 'Kabatwa', 'Kageyo', 'Kavumu', 'Matyazo', 'Muhanda', 'Muhororo', 'Ndaro'],
            'Nyabihu' => ['Bigogwe', 'Jenda', 'Jomba', 'Kabatwa', 'Karago', 'Kintobo', 'Mukamira', 'Muringa', 'Rambura', 'Rugera'],
            'Nyamasheke' => ['Bushekeri', 'Bushenge', 'Cyato', 'Gihombo', 'Kagano', 'Kanjongo', 'Karambi', 'Karengera', 'Kirimbi', 'Macuba'],
            'Rubavu' => ['Bugeshi', 'Busasamana', 'Cyanzarwe', 'Gisenyi', 'Kanama', 'Mudende', 'Nyakiriba', 'Nyamyumba', 'Nyundo', 'Rubavu'],
            'Rusizi' => ['Bugarama', 'Butare', 'Bweyeye', 'Gashonga', 'Giheke', 'Gihundwe', 'Gitambi', 'Kamembe', 'Muganza', 'Mururu'],
            'Rutsiro' => ['Boneza', 'Gihango', 'Kigeyo', 'Kivumu', 'Manihira', 'Mukura', 'Murunda', 'Musasa', 'Mushonyi', 'Nyabirasi']
        ]
    ];
}

function timeAgo(string $datetime): string {
    $time = strtotime($datetime);
    if ($time === false) {
        return $datetime;
    }
    $diff = max(0, time() - $time);
    $lang = function_exists('requestLang') ? requestLang() : 'rw';

    if ($lang === 'en') {
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return (int) floor($diff / 60) . ' min ago';
        if ($diff < 86400) return (int) floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return (int) floor($diff / 86400) . 'd ago';
        return date('d/m/Y', $time);
    }

    if ($lang === 'fr') {
        if ($diff < 60) return "À l'instant";
        if ($diff < 3600) return 'Il y a ' . (int) floor($diff / 60) . ' min';
        if ($diff < 86400) return 'Il y a ' . (int) floor($diff / 3600) . ' h';
        if ($diff < 604800) return 'Il y a ' . (int) floor($diff / 86400) . ' j';
        return date('d/m/Y', $time);
    }

    if ($diff < 60) return 'Ubu noneho';
    if ($diff < 3600) return (int) floor($diff / 60) . ' iminota ishize';
    if ($diff < 86400) return (int) floor($diff / 3600) . ' amasaha ashize';
    if ($diff < 604800) return (int) floor($diff / 86400) . ' iminsi ishize';
    return date('d/m/Y', $time);
}

function updateMannerScore(int $userId): void {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT 
            SUM(CASE WHEN rating = "good" THEN 1 ELSE 0 END) as good,
            SUM(CASE WHEN rating = "bad" THEN 1 ELSE 0 END) as bad,
            COUNT(*) as total
        FROM reviews WHERE reviewed_id = ?
    ');
    $stmt->execute([$userId]);
    $stats = $stmt->fetch();

    if ($stats['total'] > 0) {
        $score = 36.5 + (($stats['good'] - $stats['bad']) / $stats['total']) * 3.5;
        $score = max(0, min(99.9, $score));
    } else {
        $score = 36.5;
    }

    $db->prepare('UPDATE users SET manner_score = ?, manner_count = ? WHERE id = ?')
       ->execute([$score, $stats['total'], $userId]);
}
