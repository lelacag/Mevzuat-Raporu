<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$user_id = get_current_user_id();

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

require_csrf();

$following_id = intval($_POST['following_id'] ?? $_GET['following_id'] ?? 0);
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? home_url();
$referer = validate_referer($referer, home_url(), false);

if ($following_id > 0) {
    try {
        $target_user = get_user($following_id);
        $is_following_now = toggle_follow($user_id, $following_id);

        if ($target_user) {
            $target_name = htmlspecialchars($target_user['username'], ENT_QUOTES, 'UTF-8');
            if ($is_following_now) {
                $_SESSION['flash'] = $target_name . "'in kuyruğuna girdiniz";
            } else {
                $_SESSION['flash'] = $target_name . "'in kuyruğundan çıktınız";
            }
        }
    } catch (Exception $e) {
        error_log('api/follow.php: toggle_follow exception: ' . $e->getMessage() . ' user=' . $user_id . ' target=' . $following_id);
    }
} else {
    error_log('api/follow.php: missing following_id, user=' . $user_id . ' referer=' . $referer);
}

header('Location: ' . $referer);
exit;
?>

