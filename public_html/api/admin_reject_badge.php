<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in() || !admin_has_perm(null, 'manage_badges')) {
    $_SESSION['flash_error'] = 'Yetkisiz';
    header('Location: ' . BASE_PATH . '/admin/premium_badges.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['badge_id'])) {
    $_SESSION['flash_error'] = 'Eksik parametre';
    header('Location: ' . BASE_PATH . '/admin/premium_badges.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/premium_badges.php');
    exit;
}

$badge_id = intval($_POST['badge_id']);
$db = db_connect();

try {
    $stmt = $db->prepare("UPDATE user_custom_badges SET is_rejected = 1 WHERE id = ?");
    $stmt->execute([$badge_id]);
    log_admin_action('reject_badge', 'rejected badge_id=' . $badge_id, get_current_user_id());
    $_SESSION['flash'] = 'Badge rejected';
    header('Location: ' . BASE_PATH . '/admin/premium_badges.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Database error';
    header('Location: ' . BASE_PATH . '/admin/premium_badges.php');
    exit;
}
