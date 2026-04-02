<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$post_id = intval($_POST['post_id'] ?? 0);
$return_id = isset($_POST['return_id']) ? (int)$_POST['return_id'] : null;
$new_content = $_POST['content'] ?? '';

$scheduled_at = trim($_POST['scheduled_at'] ?? '');
if ($scheduled_at === '') { $scheduled_at = null; }

if ($post_id && !empty($new_content)) {
    $result = edit_post($user_id, $post_id, $new_content, $scheduled_at);
    
    if (isset($result['error'])) {
        if ($result['error'] === 'premium_required') {
            $_SESSION['flash_error'] = t('post_edit_premium_required');
        } elseif ($result['error'] === 'time_expired') {
            $_SESSION['flash_error'] = t('post_edit_time_expired');
        } elseif ($result['error'] === 'not_owner') {
            $_SESSION['flash_error'] = t('post_edit_not_owner');
        } elseif ($result['error'] === 'rookie_restricted') {
            // Show as green notification with Premium CTA
            $_SESSION['flash'] = sprintf(t('rookie_restricted_edit'), BASE_PATH . '/premium.php');
        } else {
            $_SESSION['flash_error'] = t('post_failed_error');
        }
        $redirect = BASE_PATH . '/edit_post.php?id=' . $post_id;
        if ($return_id) {
            $redirect .= '&return_id=' . $return_id;
        }
        header('Location: ' . $redirect);
    } else {
        if ($result['has_bad_words']) {
            $_SESSION['flash'] = 'Düzenleme yapılmıştır. uygun olmayan kelimeler otomatik olarak yıldızlandırılmıştır(*****).';
        } else {
            $_SESSION['flash'] = 'Düzenleme yapılmıştır.';
        }
        if ($return_id) {
            header('Location: ' . BASE_PATH . '/post.php?id=' . $return_id);
        } else {
            // Redirect back to homepage instead of post detail page
            header('Location: ' . BASE_PATH . '/index.php');
        }
    }
} else {
    header('Location: ' . BASE_PATH . '/index.php');
}
exit;
?>
