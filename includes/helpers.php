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
    return $user;
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
