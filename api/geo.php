<?php
/**
 * GUGU App — reverse geocode helper (Nominatim via server, Karrot-style neighbourhood).
 */
require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['ok' => true]);
}

$action = $_GET['action'] ?? 'reverse';
if ($action !== 'reverse') {
    jsonError('Invalid action', 404);
}

$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;

if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    jsonError('Valid lat/lng required');
}

$inRwanda = ($lat >= -2.9 && $lat <= -1.0 && $lng >= 28.8 && $lng <= 30.9);

$url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2'
    . '&lat=' . rawurlencode((string) $lat)
    . '&lon=' . rawurlencode((string) $lng)
    . '&zoom=14&addressdetails=1&accept-language=en';

$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: GUGU-App/1.0 (local marketplace; contact@gugu.local)\r\nAccept: application/json\r\n",
        'timeout' => 8,
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

$raw = @file_get_contents($url, false, $ctx);
$address = [];
$display = '';

if ($raw !== false) {
    $data = json_decode($raw, true);
    if (is_array($data)) {
        $address = $data['address'] ?? [];
        $display = $data['display_name'] ?? '';
    }
}

jsonResponse([
    'success' => true,
    'lat' => $lat,
    'lng' => $lng,
    'in_rwanda' => $inRwanda,
    'display_name' => $display,
    'address' => $address,
]);
