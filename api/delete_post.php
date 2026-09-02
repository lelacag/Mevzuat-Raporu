<?php
require_once __DIR__ . '/../includes/header.php';

// Must be logged in
$user_id = get_current_user_id();
if (!$user_id) {
    $_SESSION['error'] = 'Giriş yapmalısınız.';
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $post_id = $_POST['post_id'] ?? null;
    
    if (!$post_id) {
        $_SESSION['error'] = 'Geçersiz istek.';
        header('Location: ' . BASE_PATH . '/index.php');
        exit;
    }
    
    $result = user_delete_post($user_id, $post_id);
    
    if (is_array($result) && isset($result['error'])) {
        if ($result['error'] === 'rookie_restricted') {
            // Show green notification guiding rookie users to Premium
            $_SESSION['flash'] = sprintf(t('rookie_restricted_delete'), BASE_PATH . '/premium.php');
        } elseif ($result['error'] === 'not_owner') {
            $_SESSION['error'] = 'Gönderi silinemedi. Sadece kendi gönderilerinizi silebilirsiniz.';
        } elseif ($result['error'] === 'unapproved') {
            $_SESSION['error'] = 'Hesabınız onaylı değil; onaylı kullanıcılar gönderilerini silebilir.';
        } else {
            $_SESSION['error'] = 'Gönderi silinemedi.';
        }
    } elseif ($result) {
        $_SESSION['flash'] = 'Gönderi silindi.';
    } else {
        $_SESSION['error'] = 'Gönderi silinemedi.';
    }
    
    // Redirect back to referer or index
    $referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/index.php';
    $referer = validate_referer($referer, BASE_PATH . '/index.php', false);
    header('Location: ' . $referer);
    exit;
}

// Invalid request method
header('Location: ' . BASE_PATH . '/index.php');
exit;
