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
        <article class="post-card">
            <div class="post-header">
                <div class="post-number">#<?= $post['id'] ?></div>
                
                <div class="post-info">
                    <div>
                        <a href="<?= profile_url($post['username']) ?>" class="post-author">
                            <?= htmlspecialchars($post['username']) ?>
                        </a>
                        <span class="muted small">· <?= htmlspecialchars($post['group_name']) ?></span>
                    </div>
                    <div class="post-time">
                        <?= format_time($post['created_at']) ?>
                    </div>
                </div>
            </div>
            
            <div class="post-content">
                <?= nl2br(linkify_mentions($post['content'])) ?>
            </div>

            <?php $post_poll = get_poll_for_group_post($post['id']); if (!empty($post_poll)): ?>
                <?php $poll = $post_poll; require __DIR__ . '/templates/poll-block.php'; ?>
            <?php endif; ?>

            <?php $post_test = get_test_for_group_post($post['id']); if (!empty($post_test)): ?>
                <?php $test = $post_test; require __DIR__ . '/templates/test-block.php'; ?>
            <?php endif; ?>
            
            <div class="comment-actions">
                <?php if ($user_id): ?>
                    <!-- Like Button -->
                    <form method="POST" action="<?= BASE_PATH ?>/api/group_post_like.php" class="action-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                        <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                        <button type="submit" class="action-btn like-btn <?= $user_liked ? 'liked' : '' ?>">
                            <?= $user_liked ? '♥' : '♡' ?> <?= $like_count ?> Beğen
                        </button>
                    </form>
                    
                    <!-- Comment Count -->
                    <span class="action-btn">💬 <?= count($comments) ?> Yorum</span>
                    
                    <?php if ($user_id == $post['user_id']): ?>
                        <?php $editUrl = htmlspecialchars(BASE_PATH . '/group_post_edit.php?' . http_build_query(['id' => (int)$post['id'], 'slug' => $slug])); ?>
                        <a href="<?= $editUrl ?>" class="action-btn edit-btn">
                            ✏️ Düzenle
                        </a>
                        <a href="<?= BASE_PATH ?>/group_post_delete_confirm.php?id=<?= $post['id'] ?>&slug=<?= urlencode($slug) ?>" class="action-btn delete-btn">
                            🗑️ Sil
                        </a>
                    <?php else: ?>
                        <form method="POST" action="<?= BASE_PATH ?>/api/report.php" class="action-form">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="target_type" value="group_post">
                            <input type="hidden" name="target_id" value="<?= $post['id'] ?>">
                            <input type="hidden" name="reason" value="spam">
                            <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                            <button type="submit" class="action-btn report-btn">
                                ⚠️ Bildir
                            </button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="<?= BASE_PATH ?>/login.php" class="action-btn">♡ <?= $like_count ?> Beğen</a>
                    <span class="action-btn">💬 <?= count($comments) ?> Yorum</span>
                    <a href="<?= BASE_PATH ?>/login.php" class="action-btn">⚠️ Bildir</a>
                <?php endif; ?>
            </div>
        </article>

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