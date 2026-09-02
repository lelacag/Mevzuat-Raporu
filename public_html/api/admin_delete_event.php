<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Server-side delete endpoint (no JSON)
if (!is_logged_in() || !admin_has_perm(null, 'manage_events')) {
    $_SESSION['flash_error'] = 'Yetkisiz işlem.';
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
if ($event_id <= 0) {
    $_SESSION['flash_error'] = 'Geçersiz etkinlik ID.';
    header('Location: ' . BASE_PATH . '/admin/events.php');
    exit;
}

$db = db_connect();
$stmt = $db->prepare("DELETE FROM events WHERE id = ?");
$stmt->execute([$event_id]);
log_admin_action('delete_event', 'deleted event_id=' . $event_id, get_current_user_id());
$_SESSION['flash'] = 'Etkinlik silindi.';
header('Location: ' . BASE_PATH . '/admin/events.php');
exit;
