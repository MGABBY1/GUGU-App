<?php
/**
 * GUGU App - Helper Functions
 */

require_once __DIR__ . '/db.php';

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
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

function formatPrice(int $price): string {
    if ($price === 0) return 'Ubuntu';
    return number_format($price, 0, '.', ',') . ' FRW';
}

function getAuthUser(): ?array {
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    if (empty($token)) return null;

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
    }
    return $user ?: null;
}

function requireAuth(): array {
    $user = getAuthUser();
    if (!$user) {
        jsonError('Nyamuneka winjire mbere (Please login first)', 401);
    }
    if (!empty($user['is_banned'])) {
        jsonError('Konti yawe yahagaritswe. Vugana na GUGU support.', 403);
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

const ADMIN_ROLES = ['moderator', 'district_manager', 'super_admin'];

/**
 * Legacy super admins kept so the original backend login never breaks.
 */
function legacyAdminPhones(): array {
    return ['+250789999999'];
}

function userRole(array $user): string {
    $role = $user['role'] ?? 'member';
    if ($role === 'member' && in_array($user['phone'] ?? '', legacyAdminPhones(), true)) {
        return 'super_admin';
    }
    return $role;
}

function isAdminUser(array $user): bool {
    return in_array(userRole($user), ADMIN_ROLES, true);
}

/**
 * Permission matrix behind the portal's Permission Controls panel.
 */
function rolePermissions(string $role): array {
    $moderator = [
        'view_dashboard', 'view_moderation', 'approve_listing', 'reject_listing',
        'view_listings', 'view_users', 'view_disputes',
    ];
    $districtManager = array_merge($moderator, [
        'delete_listing', 'verify_user', 'ban_user',
        'handle_dispute', 'view_analytics', 'view_regional_report',
    ]);
    $superAdmin = array_merge($districtManager, [
        'manage_roles', 'system_controls', 'view_audit_log', 'view_all_districts',
    ]);

    return match ($role) {
        'moderator' => $moderator,
        'district_manager' => $districtManager,
        'super_admin' => $superAdmin,
        default => [],
    };
}

function roleCan(string $role, string $permission): bool {
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
 */
function adminDistrictScope(array $admin): ?string {
    if (roleCan(userRole($admin), 'view_all_districts')) {
        return null;
    }
    $scope = $admin['managed_district'] ?? '';
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
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > MAX_UPLOAD_SIZE) return null;

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed)) return null;

    $ext = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        default => 'jpg'
    };

    $filename = uniqid('gugu_', true) . '.' . $ext;
    $dest = UPLOAD_DIR . $filename;

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return $filename;
    }
    return null;
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
    $diff = time() - $time;
    if ($diff < 60) return 'Ubu noneho';
    if ($diff < 3600) return floor($diff / 60) . ' iminota ishize';
    if ($diff < 86400) return floor($diff / 3600) . ' amasaha ashize';
    if ($diff < 604800) return floor($diff / 86400) . ' iminsi ishize';
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
