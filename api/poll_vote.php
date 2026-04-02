<?php
/**
 * Vote on a poll (no-JS form submission)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$poll_id = isset($_POST['poll_id']) ? (int)$_POST['poll_id'] : 0;
$option_id = isset($_POST['option_id']) ? (int)$_POST['option_id'] : null;
$remove = isset($_POST['remove']) ? true : false;
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/index.php';
$referer = validate_referer($referer, BASE_PATH . '/index.php', false);

// CSRF validation
if (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF).';
    header('Location: ' . $referer);
    exit;
}

if ($poll_id && ($option_id !== null || $remove)) {
    // If remove requested, use option_id 0 to indicate removal
    if ($remove && ($option_id === null || $option_id === 0)) {
        $option_id = 0;
    } elseif ($option_id === null) {
        $option_id = 0; // default to remove if no option provided (defensive)
    }
    $res = vote_poll($user_id, $poll_id, $option_id);
    if (isset($res['error'])) {
        // show a simple flash or redirect back
        $_SESSION['flash'] = 'Oylama başarısız: ' . htmlspecialchars($res['error']);
    } else {
        // Friendly messages
        if (isset($res['status'])) {
            if ($res['status'] === 'voted') { $_SESSION['flash'] = 'Oyunuz kaydedildi.'; }
            elseif ($res['status'] === 'changed') { $_SESSION['flash'] = 'Oy değiştirildi.'; }
            elseif ($res['status'] === 'removed') { $_SESSION['flash'] = 'Oyunuz geri alındı.'; }
        }
    }
}

header('Location: ' . $referer);
exit;
?>