<?php
require_once __DIR__ . '/../includes/auth.php';
// Serve temporary uploaded files stored in sys_get_temp_dir() (session-backed)
if (!is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}
$token = $_GET['token'] ?? '';
if (!$token || empty($_SESSION['event_update_temp_files'][$token])) {
    http_response_code(404);
    exit('Not found');
}
$info = $_SESSION['event_update_temp_files'][$token];
$path = $info['path'];
if (!file_exists($path)) {
    http_response_code(404);
    exit('Not found');
}
$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;