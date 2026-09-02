<?php
/**
 * photo_img.php — Serves user-uploaded photos stored in tmp/photos/
 *
 * Usage:
 *   /photo_img.php?id=123
 *
 * No authentication required — photos are publicly visible once uploaded.
 * Security: basename-only filenames, realpath containment check, MIME whitelist.
 */

$projectRoot = realpath(__DIR__) ?: __DIR__;
$photosBase  = $projectRoot . '/users';

require_once __DIR__ . '/includes/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Bad Request');
}

// Look up image record
$stmt = db_connect()->prepare(
    "SELECT filename, user_id, original_filename FROM user_images WHERE id = ? AND deleted_at IS NULL LIMIT 1"
);
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit('Not Found');
}

$user_id  = (int)$row['user_id'];
$filename = basename($row['filename']);

// Validate filename characters — only safe chars allowed
if ($filename === '' || $filename === '.' || $filename === '..') {
    http_response_code(400);
    exit('Bad Request');
}
if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
    http_response_code(400);
    exit('Bad Request');
}

// Build and validate full path — must stay within photosBase
$user_hash = hash('sha256', 'mevzuat_photo_' . $user_id);
$fullPath = realpath($photosBase . '/' . $user_hash . '/photos/' . $filename);
$realBase = realpath($photosBase);

if ($fullPath === false || $realBase === false) {
    http_response_code(404);
    exit('Not Found');
}

// Strict path containment check — prevent traversal
if (strpos($fullPath, $realBase . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    exit('Forbidden');
}

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('Not Found');
}

// Detect MIME type — runtime detection first, then extension fallback
$mime = null;
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $mime = finfo_file($fi, $fullPath);
        finfo_close($fi);
    }
}
if (!$mime && function_exists('mime_content_type')) {
    $mime = mime_content_type($fullPath);
}
if (!$mime) {
    $ext    = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $extMap = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'tif'  => 'image/tiff',
        'tiff' => 'image/tiff',
    ];
    $mime = $extMap[$ext] ?? null;
}

$allowed = ['image/jpeg', 'image/png', 'image/tiff'];
if (!$mime || !in_array($mime, $allowed, true)) {
    http_response_code(403);
    exit('Forbidden');
}

// Stream the file
$size = filesize($fullPath);
header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
if (!empty($_GET['download'])) {
    $safe_dl = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($row['original_filename'] ?? $filename));
    header('Content-Disposition: attachment; filename="' . $safe_dl . '"');
}
readfile($fullPath);
exit;
