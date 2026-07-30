<?php
/**
 * GUGU App - Authentication API
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

function register(): void {
    $data = getJsonInput();
    $phone = formatPhone($data['phone'] ?? '');
    $password = $data['password'] ?? '';
    $fullName = trim($data['full_name'] ?? '');
    $province = trim($data['province'] ?? '');
    $district = trim($data['district'] ?? '');
    $sector = trim($data['sector'] ?? '');

    if (!validateRwandaPhone($phone)) {
        jsonError('Nomero ya telefoni ntabwo ari yo (+2507XXXXXXXX)');
    }
    if (strlen($password) < 6) {
        jsonError('Ijambo ry\'ibanga rigomba kuba nibura inyuguti 6');
    }
    if (empty($fullName) || empty($province) || empty($district)) {
        jsonError('Uzuza amakuru yose');
    }

    $db = getDB();

    $stmt = $db->prepare('SELECT id FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        jsonError('Iyi nomero ya telefoni isanzwe ikoreshwa');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('
        INSERT INTO users (phone, password_hash, full_name, province, district, sector, is_verified)
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ');
    $stmt->execute([$phone, $hash, $fullName, $province, $district, $sector ?: null]);

    $userId = (int) $db->lastInsertId();
    $token = createSession($userId);

    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    unset($user['password_hash']);

    jsonResponse([
        'success' => true,
        'message' => 'Kwiyandikisha byagenze neza! Murakaza neza kuri GUGU App',
        'token' => $token,
        'user' => $user
    ], 201);
}

function login(): void {
    $data = getJsonInput();
    $phone = formatPhone($data['phone'] ?? '');
    $password = $data['password'] ?? '';

    if (!validateRwandaPhone($phone)) {
        jsonError('Nomero ya telefoni ntabwo ari yo');
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonError('Nomero cyangwa ijambo ry\'ibanga ntabwo ari byo', 401);
    }

    $token = createSession((int) $user['id']);
    unset($user['password_hash']);

    jsonResponse([
        'success' => true,
        'message' => 'Murakaza neza!',
        'token' => $token,
        'user' => $user
    ]);
}

function logout(): void {
    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($token) {
        $db = getDB();
        $db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$token]);
    }
    jsonResponse(['success' => true, 'message' => 'Wasohotse neza']);
}

function me(): void {
    $user = requireAuth();
    jsonResponse(['success' => true, 'user' => $user]);
}

function createSession(int $userId): string {
    $db = getDB();
    $token = generateToken();
    $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

    $db->prepare('INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, ?)')
       ->execute([$token, $userId, $expires]);

    return $token;
}
