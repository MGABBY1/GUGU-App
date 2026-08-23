<?php
/**
 * Serve uploaded media with correct Content-Type (jpg/png/webp/gif).
 * Usage: /gugu-app/api/media.php?f=gugu_xxx.jpg
 */
require_once __DIR__ . '/../includes/helpers.php';

$file = (string) ($_GET['f'] ?? $_GET['file'] ?? '');
$file = str_replace(['\\', "\0"], ['/', ''], $file);
$file = basename($file);

if ($file === '' || str_contains($file, '..')) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid file';
    exit;
}

$path = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $file;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    default => '',
};
if ($mime === '') {
    $info = @getimagesize($path);
    $mime = is_array($info) && !empty($info['mime']) ? (string) $info['mime'] : 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=604800');
header('Access-Control-Allow-Origin: *');
readfile($path);
exit;
