<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Server-side update endpoint (no JSON)
if (!is_logged_in() || !admin_has_perm(null, 'manage_events')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/admin/events.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/admin/events.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/events.php');
    exit;
}

$event_id = intval($_POST['event_id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$event_date = trim($_POST['event_date'] ?? '');
$is_active = !empty($_POST['is_active']) ? 1 : 0;

if ($event_id <= 0 || $title === '' || $description === '' || $event_date === '') {
    $_SESSION['flash_error'] = 'Eksik veya geçersiz alanlar.';
    header('Location: ' . BASE_PATH . '/admin/events.php');
    exit;
}

$db = db_connect();
$stmt = $db->prepare("UPDATE events SET title = ?, description = ?, event_date = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
$stmt->execute([$title, $description, $event_date, $is_active, $event_id]);
log_admin_action('update_event', 'updated event_id=' . $event_id, get_current_user_id());
$_SESSION['flash'] = 'Etkinlik güncellendi.';
header('Location: ' . BASE_PATH . '/admin/events.php');
exit;
