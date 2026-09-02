<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Server-side toggle endpoint (no JSON for admin UI)
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
$is_active = intval($_POST['is_active'] ?? 0);

if ($event_id <= 0) {
    $_SESSION['flash_error'] = 'Geçersiz etkinlik ID.';
    header('Location: ' . BASE_PATH . '/admin/events.php');
    exit;
}

$db = db_connect();
$stmt = $db->prepare("UPDATE events SET is_active = ?, updated_at = NOW() WHERE id = ?");
$stmt->execute([$is_active, $event_id]);
log_admin_action('toggle_event', 'toggled event_id=' . $event_id . ' to is_active=' . $is_active, get_current_user_id());
$_SESSION['flash'] = 'Etkinlik durumu güncellendi.';
header('Location: ' . BASE_PATH . '/admin/events.php');
exit;
