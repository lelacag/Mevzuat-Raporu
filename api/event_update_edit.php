<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

$id = !empty($_POST['update_id']) ? (int)$_POST['update_id'] : 0;
$content = trim($_POST['content'] ?? '');
$referer = $_POST['referer'] ?? BASE_PATH . '/admin/events.php';
// CSRF protection for admin edits
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token']))) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . $referer);
    exit;
}
if (empty($id) || $content === '') {
    $_SESSION['flash_error'] = 'Geçersiz veri.';
    header('Location: ' . $referer);
    exit;
}

$pdo = db_connect();
try {
    $upd = $pdo->prepare("UPDATE event_updates SET content = ?, updated_at = NOW() WHERE id = ?");
    $upd->execute([$content, $id]);
    $_SESSION['flash'] = 'Güncelleme düzenlendi.';
} catch (Exception $e) {
    error_log('[event_update_edit] ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Güncelleme düzenlenemedi.';
}
header('Location: ' . $referer);
exit;