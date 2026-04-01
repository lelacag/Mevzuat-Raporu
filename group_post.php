<?php
/**
 * Group Post Detail Page - View single group post with comments
 */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$post_id = $_GET['id'] ?? 0;

if (!$post_id) {
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

// Get group post with details
$stmt = query("SELECT gp.*, u.username, g.slug, g.name as group_name, g.id as group_id, COALESCE(g.is_private, 0) as is_private
               FROM group_posts gp 
               JOIN users u ON gp.user_id = u.id 
               JOIN groups_table g ON gp.group_id = g.id 
               WHERE gp.id = ?", [$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    $_SESSION['flash_error'] = 'Gönderi bulunamadı';
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

$slug = $post['slug'];

// Check if user is member
$is_member = false;
if ($user_id) {
    $stmt = query("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?", [$post['group_id'], $user_id]);
    $is_member = (bool)$stmt->fetch();
}

// Access control: private group posts visible only to members or admins
$can_view = empty($post['is_private']) || $is_member || is_admin();
if (!$can_view) {
    $_SESSION['flash'] = 'Bu gönderi özel bir gruba ait. İçeriği görmek için gruba katılın.';
    header('Location: ' . BASE_PATH . '/group.php?slug=' . urlencode($slug));
    exit;
}

// Handle comment creation
if ($user_id && $is_member && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_comment') {
    $content = trim($_POST['content'] ?? '');
    $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    
    if (!empty($content)) {
        // Censor bad words
        $censored = censor_bad_words($content);
        $filtered_content = $censored['clean'];
        
        $stmt = query("INSERT INTO group_post_comments (post_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)", 
                      [$post_id, $user_id, $parent_id, $filtered_content]);

        // Fetch the newly-inserted comment id and use it for redirect/anchor
        try {
            $newCommentId = (int)db_connect()->lastInsertId();
        } catch (Exception $e) {
            $newCommentId = 0;
        }

        // Notifications: comment on group post, and reply to comment
        try {
            // Notify post author (if not self)
            if (!empty($post['user_id']) && $post['user_id'] != $user_id) {
                $text = "Grup gönderinize yorum yapıldı: " . $post['group_name'];
                query("INSERT INTO notifications (user_id, type, text, from_user_id, post_id, created_at) VALUES (?, 'system', ?, ?, ?, NOW())",
                    [$post['user_id'], $text, $user_id, $post_id]);
            }
            // If this is a reply to a comment, notify parent comment author (if not self)
            if (!empty($parent_id)) {
                $stmtPC = query("SELECT user_id FROM group_post_comments WHERE id = ?", [$parent_id]);
                $pc = $stmtPC->fetch(PDO::FETCH_ASSOC);
                if ($pc && (int)$pc['user_id'] !== (int)$user_id) {
                    $text2 = "Yorumunuza cevap geldi: " . $post['group_name'];
                    query("INSERT INTO notifications (user_id, type, text, from_user_id, post_id, created_at) VALUES (?, 'system', ?, ?, ?, NOW())",
                        [$pc['user_id'], $text2, $user_id, $post_id]);
                }
            }
        } catch (Exception $ex) {
            error_log('group comment notification error: ' . $ex->getMessage());
        }
        $_SESSION['flash'] = 'Yorumunuz eklendi';
        // Redirect to the comment anchor if we have the id, otherwise fallback to the post page
        if ($newCommentId) {
            $redirectTo = BASE_PATH . '/group_post.php?id=' . $post_id . '#comment-' . $newCommentId;
            error_log('group_post.php: redirecting to ' . $redirectTo);
            header('Location: ' . $redirectTo);
        } else {
            $fallback = BASE_PATH . '/group_post.php?id=' . $post_id;
            error_log('group_post.php: redirecting to ' . $fallback);
            header('Location: ' . $fallback);
        }
        exit;
    }
}

// Get like status
$user_liked = false;
$like_count = 0;
$stmt = query("SELECT COUNT(*) as cnt FROM group_post_likes WHERE post_id = ?", [$post_id]);
$like_count = $stmt->fetch()['cnt'];
if ($user_id) {
    $stmt = query("SELECT id FROM group_post_likes WHERE post_id = ? AND user_id = ?", [$post_id, $user_id]);
    $user_liked = (bool)$stmt->fetch();
}

// Get threaded comments using the new function
$comments = get_group_comments($post_id, $user_id);
$total_comments = count_group_comments($post_id);
?>

<div class="main-container groups-layout">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= BASE_PATH ?>/group.php?slug=<?= urlencode($slug) ?>">← <?= htmlspecialchars($post['group_name']) ?></a></li>
                <li><a href="<?= BASE_PATH ?>/topluluklar">Tüm Topluluklar</a></li>
            </ul>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="content-area form-centered">
        <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-success">
            <?= $_SESSION['flash'] ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <!-- Main Post -->
        <?php
            $current_user_id = $user_id;
            $gp = $post;
            $show_group_post_comment_form = false; // use group_comment.php form instead
            require __DIR__ . '/templates/group-post-card.php';
        ?>

        <?php require __DIR__ . '/templates/group-comment.php'; ?>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">Gönderi Bilgisi</div>
            <div class="sidebar-note padded">
                <p class="muted mb-8"><strong>Grup:</strong> <a href="<?= BASE_PATH ?>/group.php?slug=<?= urlencode($slug) ?>"><?= htmlspecialchars($post['group_name']) ?></a></p>
                <p class="muted mb-8"><strong>Yazar:</strong> @<?= htmlspecialchars($post['username']) ?></p>
                <p class="muted mb-8"><strong>Tarih:</strong> <?= format_time($post['created_at']) ?></p>
                <p class="muted mb-8"><strong>Beğeni:</strong> <?= $like_count ?></p>
                <p class="muted"><strong>Yorum:</strong> <?= $total_comments ?></p>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>