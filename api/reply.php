<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$user_id = get_current_user_id();

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$parent_id = $_POST['parent_id'] ?? $_GET['parent_id'] ?? 0;
$content = $_POST['content'] ?? '';

if ($parent_id && !empty($content)) {
    $res = create_post($user_id, $content, $parent_id);
    if (isset($res['error'])) {
        if ($res['error'] === 'limit_exceeded') {
            $_SESSION['flash_error'] = $res['message'];
        } elseif ($res['error'] === 'suspended') {
            $_SESSION['flash_error'] = t('post_suspended_error', htmlspecialchars($res['until']));
        } else {
            $_SESSION['flash_error'] = t('post_failed_error');
        }
        // redirect back to post page on error
        header('Location: ' . BASE_PATH . '/post.php?id=' . intval($parent_id));
        exit;
    }

    // If creation succeeded and we have the new id, redirect to the post page anchored to the new comment
    if (!empty($res['id'])) {
        $newId = (int)$res['id'];
        $approved = !empty($res['approved']);
        // Build canonical post URL and only append fragment if the reply is approved (anchor exists)
        $redirectTo = post_url($parent_id);
        $query = [];
        if ($newId) $query[] = 'highlight=' . $newId;
        if (!empty($query)) {
            $redirectTo .= (strpos($redirectTo, '?') === false ? '?' : '&') . implode('&', $query);
        }
        if ($approved) {
            $redirectTo .= '#comment-' . $newId;
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    // Fallback redirect
    header('Location: ' . BASE_PATH . '/post.php?id=' . intval($parent_id));
    exit;
}

$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/index.php');
$referer = validate_referer($referer, BASE_PATH . '/index.php', false);
header('Location: ' . $referer);
exit; 
?>

