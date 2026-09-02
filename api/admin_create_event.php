<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Server-side admin form endpoint (no JSON) — create event via POST + redirect
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

$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$event_date = trim($_POST['event_date'] ?? '');
$is_active = !empty($_POST['is_active']) ? 1 : 0;

if ($title === '' || $description === '' || $event_date === '') {
    $_SESSION['flash_error'] = 'Eksik alanlar — lütfen tüm zorunlu alanları doldurun.';
    header('Location: ' . BASE_PATH . '/admin/events.php');
    exit;
}

$db = db_connect();
$stmt = $db->prepare("INSERT INTO events (title, description, event_date, created_by, is_active, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
$stmt->execute([$title, $description, $event_date, get_current_user_id(), $is_active]);

$newId = $db->lastInsertId();
log_admin_action('create_event', 'created event_id=' . $newId, get_current_user_id());
$_SESSION['flash'] = 'Etkinlik oluşturuldu.';
header('Location: ' . BASE_PATH . '/admin/events.php');
exit;
