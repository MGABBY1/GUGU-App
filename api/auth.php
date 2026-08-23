<?php
/**
 * Gura & Gurisha — Authentication API
 * Member OTP + staff password login + PHP session bridge for admin portal.
 */

require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['ok' => true]);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':
        if ($method !== 'POST') jsonErrorKey('method_not_allowed', 405);
        register();
        break;
    case 'login':
        if ($method !== 'POST') jsonErrorKey('method_not_allowed', 405);
        login();
        break;
    case 'send-otp':
        if ($method !== 'POST') jsonErrorKey('method_not_allowed', 405);
        sendOtp();
        break;
    case 'confirm-otp':
        if ($method !== 'POST') jsonErrorKey('method_not_allowed', 405);
        confirmOtp();
        break;
    case 'verify-otp':
        if ($method !== 'POST') jsonErrorKey('method_not_allowed', 405);
        verifyOtp();
        break;
    case 'complete-profile':
        if ($method !== 'POST') jsonErrorKey('method_not_allowed', 405);
        completeProfile();
        break;
    case 'verify-location':
        if ($method !== 'POST') jsonErrorKey('method_not_allowed', 405);
        verifyLocation();
        break;
    case 'submit-id':
        if ($method !== 'POST') jsonErrorKey('method_not_allowed', 405);
        submitId();
        break;
    case 'open-staff-portal':
        if ($method !== 'POST') jsonErrorKey('method_not_allowed', 405);
        openStaffPortal();
        break;
    case 'logout':
        if ($method !== 'POST') jsonErrorKey('method_not_allowed', 405);
        logout();
        break;
    case 'me':
        if ($method !== 'GET') jsonErrorKey('method_not_allowed', 405);
        me();
        break;
    default:
        jsonErrorKey('invalid_action', 404);
}

function publicUser(array $user): array {
    unset($user['password_hash']);
    return enrichUserFlags($user);
}

function fetchUserById(int $id): ?array {
    $stmt = getDB()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function createSession(int $userId): string {
    $db = getDB();
    $token = generateToken();
    $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
    $db->prepare('INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, ?)')
       ->execute([$token, $userId, $expires]);
    return $token;
}

function authPayload(array $user, string $token, string $message = 'Murakaza neza!'): array {
    $user = publicUser($user);
    $roleId = (int) ($user['role_id'] ?? 4);
    $payload = [
        'success' => true,
        'message' => $message,
        'token' => $token,
        'user' => $user,
        'needs_profile' => !empty($user['needs_profile']),
        'needs_location' => !empty($user['needs_location']),
        'needs_id_upload' => !empty($user['needs_id_upload']),
        'needs_id_verification' => !empty($user['needs_id_verification']),
        'is_staff' => isStaffRoleId($roleId),
    ];
    if ($payload['is_staff']) {
        $payload['redirect'] = '/gugu-app/admin/dashboard.php';
    }
    return $payload;
}

function sendOtp(): void {
    $data = getJsonInput();
    $phone = formatPhone($data['phone'] ?? '');
    if (!validateRwandaPhone($phone)) {
        jsonErrorKey('phone_invalid');
    }

    $purpose = trim((string) ($data['purpose'] ?? 'login'));
    if (!in_array($purpose, ['login', 'register', 'verify'], true)) {
        $purpose = 'login';
    }

    $len = defined('OTP_LENGTH') ? (int) OTP_LENGTH : 6;
    $ttl = defined('OTP_TTL_SECONDS') ? (int) OTP_TTL_SECONDS : 300;
    $code = str_pad((string) random_int(0, (10 ** $len) - 1), $len, '0', STR_PAD_LEFT);
    $db = getDB();
    $db->prepare('UPDATE otp_codes SET used_at = NOW() WHERE phone = ? AND used_at IS NULL')->execute([$phone]);
    // Use MySQL clock so expires_at matches expires_at > NOW() checks.
    $db->prepare('
        INSERT INTO otp_codes (phone, code, purpose, attempts, expires_at)
        VALUES (?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL ? SECOND))
    ')->execute([$phone, $code, $purpose, $ttl]);

    $out = [
        'success' => true,
        'phone' => $phone,
        'expires_in' => $ttl,
        'message' => 'OTP yoherejwe',
    ];
    if (defined('OTP_DEV_MODE') && OTP_DEV_MODE) {
        $out['dev_otp'] = $code;
        $out['message'] = 'OTP (dev mode): ' . $code;
    }
    jsonResponse($out);
}

/**
 * Validate OTP for registration without creating a user.
 * Marks a short-lived session proof so register() does not re-fail on expiry.
 */
function confirmOtp(): void {
    $data = getJsonInput();
    $phone = formatPhone($data['phone'] ?? '');
    $code = (string) ($data['code'] ?? $data['otp'] ?? '');
    if (!validateRwandaPhone($phone)) {
        jsonErrorKey('phone_invalid');
    }

    $row = findActiveOtp($phone);
    assertOtpMatches($row, $code);

    // Keep OTP usable for the details form, but give more time after successful check.
    getDB()->prepare('
        UPDATE otp_codes
        SET expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE), attempts = 0
        WHERE id = ?
    ')->execute([(int) $row['id']]);

    startAppSession();
    $_SESSION['register_otp_phone'] = $phone;
    $_SESSION['register_otp_code'] = normalizeOtp($code);
    $_SESSION['register_otp_at'] = time();

    jsonResponse([
        'success' => true,
        'phone' => $phone,
        'message' => 'OTP yemejwe',
    ]);
}

function verifyOtp(): void {
    $data = getJsonInput();
    $phone = formatPhone($data['phone'] ?? '');
    $code = (string) ($data['code'] ?? '');
    if (!validateRwandaPhone($phone) || normalizeOtp($code) === '') {
        jsonErrorKey('otp_invalid');
    }

    $otp = findActiveOtp($phone);
    assertOtpMatches($otp, $code);
    markOtpUsed((int) $otp['id']);

    $stmt = getDB()->prepare('SELECT * FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    $user = $stmt->fetch();
    $isNew = false;
    if (!$user) {
        getDB()->prepare('
            INSERT INTO users (phone, password_hash, nickname, role_id, account_kind, account_status, is_verified)
            VALUES (?, NULL, "", 4, "member", "active", 1)
        ')->execute([$phone, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
        $user = fetchUserById((int) getDB()->lastInsertId());
        $isNew = true;
    }

    $token = createSession((int) $user['id']);
    $payload = authPayload($user, $token, 'OTP yemejwe');
    $payload['is_new'] = $isNew;
    if ($isNew) {
        $payload['needs_profile'] = true;
    }
    jsonResponse($payload);
}

function login(): void {
    $data = getJsonInput();
    $phone = formatPhone($data['phone'] ?? $data['login'] ?? '');
    $password = (string) ($data['password'] ?? '');

    if (!validateRwandaPhone($phone)) {
        jsonErrorKey('phone_invalid');
    }
    if ($password === '') {
        jsonErrorKey('password_or_otp');
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    $user = $stmt->fetch();

    if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        jsonErrorKey('login_failed', 401);
    }
    if (($user['account_status'] ?? 'active') !== 'active' || !empty($user['is_banned'])) {
        jsonErrorKey('account_suspended', 403);
    }

    $token = createSession((int) $user['id']);
    if (isStaffRoleId((int) ($user['role_id'] ?? 4))) {
        startStaffPhpSession($user);
    }
    jsonResponse(authPayload($user, $token));
}

function openStaffPortal(): void {
    $user = requireAuth();
    $roleId = (int) ($user['role_id'] ?? 4);
    if (!isStaffRoleId($roleId)) {
        jsonErrorKey('staff_portal_only', 403);
    }
    startStaffPhpSession($user);
    $labels = [
        1 => 'Admin',
        2 => 'District Manager',
        3 => 'Moderator / Support',
    ];
    $redirect = '/gugu-app/admin/dashboard.php';
    $preview = stickyPortalViewForUser($user);
    if ($preview !== null) {
        $qs = ['view_role' => (string) $preview['role']];
        if ($preview['district'] !== '') {
            $qs['view_district'] = $preview['district'];
        }
        $redirect .= '?' . http_build_query($qs);
    }
    jsonResponse([
        'success' => true,
        'redirect' => $redirect,
        'role_id' => $roleId,
        'role_name' => $labels[$roleId] ?? 'Staff',
        'is_admin' => $roleId === 1,
        'can_manage_staff' => $roleId === 1,
        'can_system_controls' => $roleId === 1,
        'portal_view' => $preview,
    ]);
}

function completeProfile(): void {
    $user = requireAuth();
    $data = getJsonInput();
    $nickname = trim((string) ($data['nickname'] ?? ''));
    $realName = trim((string) ($data['real_name'] ?? $data['full_name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $province = trim((string) ($data['province'] ?? ''));
    $district = trim((string) ($data['district'] ?? ''));
    $sector = trim((string) ($data['sector'] ?? ''));

    if ($nickname === '' || $province === '' || $district === '') {
        jsonErrorKey('fill_nickname_district');
    }

    getDB()->prepare('
        UPDATE users SET nickname = ?, full_name = ?, email = ?, province = ?, district = ?, sector = ?
        WHERE id = ?
    ')->execute([
        $nickname,
        $realName !== '' ? $realName : $nickname,
        $email !== '' ? $email : null,
        $province,
        $district,
        $sector !== '' ? $sector : null,
        (int) $user['id'],
    ]);

    $fresh = fetchUserById((int) $user['id']);
    $pub = publicUser($fresh);
    jsonResponse([
        'success' => true,
        'user' => $pub,
        'needs_location' => !empty($pub['needs_location']),
    ]);
}

function verifyLocation(): void {
    $user = requireAuth();
    $data = getJsonInput();
    $lat = (float) ($data['lat'] ?? 0);
    $lng = (float) ($data['lng'] ?? 0);
    $district = trim((string) ($data['district'] ?? $user['district'] ?? ''));
    $sector = trim((string) ($data['sector'] ?? $user['sector'] ?? ''));
    $province = trim((string) ($data['province'] ?? $user['province'] ?? ''));

    // Rwanda rough bounds
    $inRwanda = $lat >= -2.9 && $lat <= -1.0 && $lng >= 28.8 && $lng <= 30.9;
    if (!$inRwanda && $district === '') {
        jsonErrorKey('location_rwanda');
    }

    getDB()->prepare('
        UPDATE users
        SET location_lat = ?, location_lng = ?, location_verified_at = NOW(),
            district = COALESCE(NULLIF(?, ""), district),
            sector = COALESCE(NULLIF(?, ""), sector),
            province = COALESCE(NULLIF(?, ""), province)
        WHERE id = ?
    ')->execute([$lat, $lng, $district, $sector, $province, (int) $user['id']]);

    $fresh = fetchUserById((int) $user['id']);
    jsonResponse([
        'success' => true,
        'user' => publicUser($fresh),
        'valid_days' => (int) LOCATION_VERIFY_DAYS,
        'in_rwanda' => $inRwanda,
        'display_name' => trim(($fresh['district'] ?? '') . ($sector ? ', ' . $sector : '')),
    ]);
}

function submitId(): void {
    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($token === '') {
        $token = (string) ($_SERVER['HTTP_X_GUGU_TOKEN'] ?? $_POST['token'] ?? '');
    }
    if ($token !== '') {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    $user = requireAuth();
    $idNumber = trim((string) ($_POST['id_number'] ?? ''));
    if ($idNumber === '') {
        jsonErrorKey('id_number_required');
    }
    if (empty($_FILES['id_document']) && empty($_FILES['document'])) {
        jsonErrorKey('id_photo_required');
    }
    $file = $_FILES['id_document'] ?? $_FILES['document'];
    $path = handleImageUpload($file);
    if (!$path) {
        jsonErrorKey('id_photo_invalid');
    }

    getDB()->prepare('
        UPDATE users SET id_number = ?, id_document_path = ?, id_status = "pending", id_reject_reason = NULL
        WHERE id = ?
    ')->execute([$idNumber, $path, (int) $user['id']]);

    $fresh = fetchUserById((int) $user['id']);
    $public = publicUser($fresh);
    jsonResponse([
        'success' => true,
        'message' => 'ID yoherejwe — tegereza approval',
        'user' => $public,
        'needs_id_verification' => !empty($public['needs_id_verification']),
    ]);
}

function register(): void {
    $data = getJsonInput();
    $phone = formatPhone($data['phone'] ?? '');
    $password = (string) ($data['password'] ?? '');
    $otp = normalizeOtp((string) ($data['otp'] ?? ''));
    $fullName = trim((string) ($data['full_name'] ?? $data['nickname'] ?? ''));
    $nickname = trim((string) ($data['nickname'] ?? ''));
    if ($nickname === '') {
        $nickname = $fullName !== '' ? explode(' ', $fullName)[0] : '';
    }
    $email = trim((string) ($data['email'] ?? ''));
    $province = trim((string) ($data['province'] ?? ''));
    $district = trim((string) ($data['district'] ?? ''));
    $sector = trim((string) ($data['sector'] ?? ''));

    if (!validateRwandaPhone($phone)) {
        jsonErrorKey('phone_invalid_format');
    }
    if (strlen($password) < 6) {
        jsonErrorKey('password_short');
    }
    if ($fullName === '' || $province === '' || $district === '') {
        jsonErrorKey('fill_all');
    }

    $db = getDB();
    startAppSession();
    $sessionOk = isset($_SESSION['register_otp_phone'], $_SESSION['register_otp_at'])
        && (string) $_SESSION['register_otp_phone'] === $phone
        && (time() - (int) $_SESSION['register_otp_at']) <= 900;

    if ($sessionOk) {
        // OTP already confirmed on previous step — consume any leftover unused code.
        $row = findActiveOtp($phone);
        if ($row) {
            markOtpUsed((int) $row['id']);
        }
    } else {
        if ($otp === '') {
            jsonErrorKey('otp_invalid', 401);
        }
        $row = findActiveOtp($phone);
        assertOtpMatches($row, $otp);
        markOtpUsed((int) $row['id']);
    }
    unset($_SESSION['register_otp_phone'], $_SESSION['register_otp_code'], $_SESSION['register_otp_at']);

    $stmt = $db->prepare('SELECT id FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        jsonErrorKey('phone_taken');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare('
        INSERT INTO users (phone, password_hash, full_name, nickname, email, province, district, sector, role_id, account_kind, account_status, is_verified)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 4, "member", "active", 1)
    ')->execute([
        $phone,
        $hash,
        $fullName,
        $nickname,
        $email !== '' ? $email : null,
        $province,
        $district,
        $sector !== '' ? $sector : null,
    ]);

    $userId = (int) $db->lastInsertId();
    $token = createSession($userId);
    $user = fetchUserById($userId);
    jsonResponse(authPayload($user, $token, 'Kwiyandikisha byagenze neza! Murakaza neza kuri Gura & Gurisha App'), 201);
}

function logout(): void {
    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($token === '') {
        $token = (string) ($_SERVER['HTTP_X_GUGU_TOKEN'] ?? '');
    }
    if ($token) {
        getDB()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$token]);
    }
    clearStaffPhpSession();
    jsonResponse(['success' => true, 'message' => 'Wasohotse neza']);
}

function me(): void {
    $user = requireAuth();
    jsonResponse(['success' => true, 'user' => publicUser($user)]);
}
