<?php
/**
 * Edit a group post
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$user_id = get_current_user_id();

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

require_csrf();

$post_id = intval($_POST['post_id'] ?? 0);
$content = trim($_POST['content'] ?? '');
$referer = $_POST['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/topluluklar'));
$referer = validate_referer($referer, BASE_PATH . '/topluluklar', false);

if ($post_id && !empty($content)) {
    // Check if user owns this post
    $stmt = query("SELECT * FROM group_posts WHERE id = ? AND user_id = ?", [$post_id, $user_id]);
    $post = $stmt->fetch();
    
    if ($post) {
        // Same length policy as main posts: premium unlimited, free users capped
        $user_limit = function_exists('get_user_post_limit') ? get_user_post_limit($user_id) : MAX_POST_LENGTH;
        $visible_content = trim(strip_tags(function_exists('render_rich_text') ? render_rich_text($content) : $content));
        if ($user_limit > 0 && mb_strlen($visible_content, 'UTF-8') > $user_limit) {
            $premium_url = BASE_PATH . '/premium.php';
            $_SESSION['flash_error'] = function_exists('t')
                ? strip_tags(sprintf(t('post_length_error_premium'), $user_limit, $user_limit, $premium_url))
                : ('Gönderi ' . $user_limit . ' karakterden uzun olamaz.');
            header('Location: ' . $referer);
            exit;
        }

        // Censor bad words
        $censored = censor_bad_words($content);
        $filtered_content = $censored['clean'];
        
        // Save edit history before updating
        $prev_content = $post['content'] ?? '';
        try {
            query("INSERT INTO group_post_edits (post_id, editor_id, previous_content, new_content) VALUES (?, ?, ?, ?)",
                [$post_id, $user_id, $prev_content, $filtered_content]);
        } catch (Exception $e) {
            error_log('group_post_edit: failed to save edit history: ' . $e->getMessage());
        }
        
        // Update post
        query("UPDATE group_posts SET content = ?, updated_at = NOW() WHERE id = ?", [$filtered_content, $post_id]);
        $_SESSION['flash'] = 'Gönderi düzenlendi.';
    } else {
        $_SESSION['flash_error'] = 'Bu gönderiyi düzenleme yetkiniz yok.';
    }
}

header('Location: ' . $referer);
exit;
?>
