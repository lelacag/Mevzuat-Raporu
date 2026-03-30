<?php
/**
 * Like/Unlike a group post
 */
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
    $referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/groups.php');
    $referer = validate_referer($referer, BASE_PATH . '/groups.php', false);
    header('Location: ' . $referer);
    exit;
}

$post_id = intval($_POST['post_id'] ?? $_GET['post_id'] ?? 0);
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/groups.php');
$referer = validate_referer($referer, BASE_PATH . '/groups.php', false);

if ($post_id) {
    // Check if already liked
    $stmt = query("SELECT id FROM group_post_likes WHERE user_id = ? AND post_id = ?", [$user_id, $post_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Unlike
        query("DELETE FROM group_post_likes WHERE user_id = ? AND post_id = ?", [$user_id, $post_id]);
    } else {
        // Like
        query("INSERT INTO group_post_likes (user_id, post_id) VALUES (?, ?)", [$user_id, $post_id]);

        // Notify post author about like
        try {
            $stmtP = query("SELECT gp.user_id as author_id, g.name as group_name, g.slug
                             FROM group_posts gp
                             JOIN groups_table g ON gp.group_id = g.id
                             WHERE gp.id = ?", [$post_id]);
            $row = $stmtP->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $author_id = (int)$row['author_id'];
                if ($author_id && $author_id !== $user_id) {
                    // Use system type with text; store post_id for linking
                    $text = "Grup gönderiniz beğenildi: " . $row['group_name'];
                    query("INSERT INTO notifications (user_id, type, text, from_user_id, post_id, created_at) VALUES (?, 'system', ?, ?, ?, NOW())",
                        [$author_id, $text, $user_id, $post_id]);
                }
            }
        } catch (Exception $ex) {
            error_log('group like notification error: ' . $ex->getMessage());
        }
    }
}

header('Location: ' . $referer);
exit;
?>
