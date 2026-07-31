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
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        register();
        break;
    case 'login':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        login();
        break;
    case 'send-otp':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        sendOtp();
        break;
    case 'verify-otp':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        verifyOtp();
        break;
    case 'complete-profile':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        completeProfile();
        break;
    case 'verify-location':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        verifyLocation();
        break;
    case 'submit-id':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        submitId();
        break;
    case 'open-staff-portal':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        openStaffPortal();
        break;
    case 'logout':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        logout();
        break;
    case 'me':
        if ($method !== 'GET') jsonError('Method not allowed', 405);
        me();
        break;
    default:
        jsonError('Invalid action', 404);
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
        jsonError('Nomero ya telefoni ntabwo ari yo');
    }

    $code = str_pad((string) random_int(0, 999999), OTP_LENGTH, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS);
    $db = getDB();
    $db->prepare('UPDATE otp_codes SET used_at = NOW() WHERE phone = ? AND used_at IS NULL')->execute([$phone]);
    $db->prepare('
        INSERT INTO otp_codes (phone, code, purpose, attempts, expires_at)
        VALUES (?, ?, "login", 0, ?)
    ')->execute([$phone, $code, $expires]);

    $out = [
        'success' => true,
        'phone' => $phone,
        'expires_in' => OTP_TTL_SECONDS,
        'message' => 'OTP yoherejwe',
    ];
    if (defined('OTP_DEV_MODE') && OTP_DEV_MODE) {
        $out['dev_otp'] = $code;
        $out['message'] = 'OTP (dev mode): ' . $code;
    }
    jsonResponse($out);
}

function verifyOtp(): void {
    $data = getJsonInput();
    $phone = formatPhone($data['phone'] ?? '');
    $code = trim((string) ($data['code'] ?? ''));
    if (!validateRwandaPhone($phone) || $code === '') {
        jsonError('OTP ntabwo ari yo');
    }

    $db = getDB();
    $stmt = $db->prepare('
        SELECT * FROM otp_codes
        WHERE phone = ? AND used_at IS NULL AND expires_at > NOW()
        ORDER BY id DESC LIMIT 1
    ');
    $stmt->execute([$phone]);
    $otp = $stmt->fetch();
    if (!$otp) {
        jsonError('OTP yarangiye — saba indi', 401);
    }
    if ((int) $otp['attempts'] >= OTP_MAX_ATTEMPTS) {
        jsonError('OTP yarangiye — saba indi', 401);
    }
    if (!hash_equals((string) $otp['code'], $code)) {
        $db->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $otp['id']]);
        jsonError('OTP ntabwo ari yo', 401);
    }
    $db->prepare('UPDATE otp_codes SET used_at = NOW() WHERE id = ?')->execute([(int) $otp['id']]);

    $stmt = $db->prepare('SELECT * FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    $user = $stmt->fetch();
    $isNew = false;
    if (!$user) {
        $db->prepare('
            INSERT INTO users (phone, password_hash, nickname, role_id, account_kind, account_status, is_verified)
            VALUES (?, NULL, "", 4, "member", "active", 1)
        ')->execute([$phone, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
        $user = fetchUserById((int) $db->lastInsertId());
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
        jsonError('Nomero ya telefoni ntabwo ari yo');
    }
    if ($password === '') {
        jsonError('Andika ijambo ry\'ibanga cyangwa OTP');
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    $user = $stmt->fetch();

    if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        jsonError('Nomero cyangwa ijambo ry\'ibanga ntabwo ari byo', 401);
    }
    if (($user['account_status'] ?? 'active') !== 'active' || !empty($user['is_banned'])) {
        jsonError('Konti yawe yahagaritswe', 403);
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
        jsonError('Staff portal for management accounts only', 403);
    }
    startStaffPhpSession($user);
    $labels = [
        1 => 'System Administrator',
        2 => 'District Manager',
        3 => 'Moderator / Support',
    ];
    jsonResponse([
        'success' => true,
        'redirect' => '/gugu-app/admin/dashboard.php',
        'role_id' => $roleId,
        'role_name' => $labels[$roleId] ?? 'Staff',
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
        jsonError('Uzuza nickname, intara n\'akarere');
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
        jsonError('Location must be in Rwanda');
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
        jsonError('Andika numero y\'indangamuntu');
    }
    if (empty($_FILES['id_document']) && empty($_FILES['document'])) {
        jsonError('Shyiramo ifoto y\'indangamuntu');
    }
    $file = $_FILES['id_document'] ?? $_FILES['document'];
    $path = handleImageUpload($file);
    if (!$path) {
        jsonError('Ifoto ntabwo yemewe');
    }

    getDB()->prepare('
        UPDATE users SET id_number = ?, id_document_path = ?, id_status = "pending", id_reject_reason = NULL
        WHERE id = ?
    ')->execute([$idNumber, $path, (int) $user['id']]);

    $fresh = fetchUserById((int) $user['id']);
    jsonResponse([
        'success' => true,
        'message' => 'ID yoherejwe — tegereza approval',
        'user' => publicUser($fresh),
        'needs_id_verification' => true,
    ]);
}

function register(): void {
    $data = getJsonInput();
    $phone = formatPhone($data['phone'] ?? '');
    $password = (string) ($data['password'] ?? '');
    $otp = trim((string) ($data['otp'] ?? ''));
    $fullName = trim((string) ($data['full_name'] ?? $data['nickname'] ?? ''));
    $province = trim((string) ($data['province'] ?? ''));
    $district = trim((string) ($data['district'] ?? ''));
    $sector = trim((string) ($data['sector'] ?? ''));

    if (!validateRwandaPhone($phone)) {
        jsonError('Nomero ya telefoni ntabwo ari yo (+2507XXXXXXXX)');
    }
    if (strlen($password) < 6) {
        jsonError('Ijambo ry\'ibanga rigomba kuba nibura inyuguti 6');
    }
    if ($fullName === '' || $province === '' || $district === '') {
        jsonError('Uzuza amakuru yose');
    }

    $db = getDB();
    if ($otp !== '') {
        $stmt = $db->prepare('
            SELECT * FROM otp_codes
            WHERE phone = ? AND used_at IS NULL AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([$phone]);
        $row = $stmt->fetch();
        if (!$row || !hash_equals((string) $row['code'], $otp)) {
            jsonError('OTP ntabwo ari yo', 401);
        }
        $db->prepare('UPDATE otp_codes SET used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        jsonError('Iyi nomero ya telefoni isanzwe ikoreshwa');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare('
        INSERT INTO users (phone, password_hash, full_name, nickname, province, district, sector, role_id, account_kind, account_status, is_verified)
        VALUES (?, ?, ?, ?, ?, ?, ?, 4, "member", "active", 1)
    ')->execute([$phone, $hash, $fullName, $fullName, $province, $district, $sector ?: null]);

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
