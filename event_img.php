<?php
/**
 * event_img.php — Serves event images stored in tmp/events/
 *
 * Usage:
 *   /event_img.php?event=123&type=header&file=filename.jpg
 *   /event_img.php?event=123&type=images&file=filename.jpg
 *   /event_img.php?token=abc123&type=header&file=filename.jpg   (staging)
 *   /event_img.php?token=abc123&type=images&file=filename.jpg   (staging)
 *
 * No JS, no AJAX. Pure PHP file-streaming.
 */

$projectRoot = realpath(__DIR__) ?: __DIR__;
$eventsBase  = $projectRoot . '/tmp/events';

// --- Resolve which folder to serve from ---
$type  = $_GET['type']  ?? '';
$file  = $_GET['file']  ?? '';
$event = $_GET['event'] ?? '';
$token = $_GET['token'] ?? '';

// Validate type
if (!in_array($type, ['header', 'images'], true)) {
    http_response_code(400);
    exit('Bad Request');
}

// Validate file: only basename, no directories, no traversal
$file = basename($file);
if ($file === '' || $file === '.' || $file === '..') {
    http_response_code(400);
    exit('Bad Request');
}
// Allow only safe filename characters
if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $file)) {
    http_response_code(400);
    exit('Bad Request');
}

// Resolve folder name
if ($event !== '') {
    $event = intval($event);
    if ($event <= 0) { http_response_code(404); exit('Not Found'); }
    $folder = 'event_' . $event;
} elseif ($token !== '') {
    // Validate token: hex characters only, 32 chars
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        http_response_code(400);
        exit('Bad Request');
    }
    $folder = 'tmp_' . $token;
} else {
    http_response_code(400);
    exit('Bad Request');
}

// Build and validate full path — must stay within eventsBase
$fullPath = realpath($eventsBase . '/' . $folder . '/' . $type . '/' . $file);
$realBase = realpath($eventsBase);

if ($fullPath === false || $realBase === false) {
    http_response_code(404);
    exit('Not Found');
}

// Strict path containment check
if (strpos($fullPath, $realBase . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    exit('Forbidden');
}

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('Not Found');
}

// Detect MIME type — try runtime detection then fall back to extension map
$mime = null;
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) { $mime = finfo_file($fi, $fullPath); finfo_close($fi); }
}
if (!$mime && function_exists('mime_content_type')) {
    $mime = mime_content_type($fullPath);
}
// Extension-based fallback (safe: filename already validated above)
if (!$mime) {
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $extMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
               'gif' => 'image/gif',  'webp'  => 'image/webp'];
    $mime = $extMap[$ext] ?? null;
}
$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
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
readfile($fullPath);
exit;
