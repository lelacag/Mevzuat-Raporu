<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$user_id = get_current_user_id();

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

// CSRF protection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token']))) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF).';
    $referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? home_url();
    $referer = validate_referer($referer, home_url(), false);
    header('Location: ' . $referer);
    exit;
}

$following_id = $_POST['following_id'] ?? $_GET['following_id'] ?? 0;
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? home_url();
$referer = validate_referer($referer, home_url(), false);

if ($following_id) {
    toggle_follow($user_id, $following_id);
}

header('Location: ' . $referer);
exit;
?>

