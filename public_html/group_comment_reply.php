<?php
/**
 * Group Comment Reply Page - Reply to a comment
 */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$comment_id = $_GET['comment_id'] ?? 0;
$post_id = $_GET['post_id'] ?? 0;

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

if (!$comment_id || !$post_id) {
    $_SESSION['flash_error'] = 'Geçersiz istek';
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

// Get the parent comment
$stmt = query("SELECT c.*, u.username, gp.group_id, g.slug, g.name as group_name
               FROM group_post_comments c 
               JOIN users u ON c.user_id = u.id 
               JOIN group_posts gp ON c.post_id = gp.id
               JOIN groups_table g ON gp.group_id = g.id
               WHERE c.id = ?", [$comment_id]);
$parent_comment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$parent_comment) {
    $_SESSION['flash_error'] = 'Yorum bulunamadı';
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

$slug = $parent_comment['slug'];

// Check if user is member
$stmt = query("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?", [$parent_comment['group_id'], $user_id]);
$is_member = (bool)$stmt->fetch();

if (!$is_member) {
    $_SESSION['flash_error'] = 'Yanıt vermek için gruba katılmalısınız';
    header('Location: ' . BASE_PATH . '/group_post.php?id=' . $post_id);
    exit;
}

// Handle reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_reply') {
    require_csrf();
    $content = trim($_POST['content'] ?? '');
    
    if (!empty($content)) {
        // Censor bad words
        $censored = censor_bad_words($content);
        $filtered_content = $censored['clean'];
        
        $stmt = query("INSERT INTO group_post_comments (post_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)", 
                      [$post_id, $user_id, $comment_id, $filtered_content]);
        // Notifications: reply to comment and notify post author too
        try {
            // Notify parent comment author
            if (!empty($parent_comment['user_id']) && $parent_comment['user_id'] != $user_id) {
                $text = "Yorumunuza cevap geldi: " . $parent_comment['group_name'];
                query("INSERT INTO notifications (user_id, type, text, from_user_id, post_id, created_at) VALUES (?, 'system', ?, ?, ?, NOW())",
                    [$parent_comment['user_id'], $text, $user_id, $post_id]);
            }
            // Notify post author
            $stmtPA = query("SELECT user_id FROM group_posts WHERE id = ?", [$post_id]);
            $pa = $stmtPA->fetch(PDO::FETCH_ASSOC);
            if ($pa && (int)$pa['user_id'] !== (int)$user_id) {
                $text2 = "Grup gönderinize yanıt yapıldı: " . $parent_comment['group_name'];
                query("INSERT INTO notifications (user_id, type, text, from_user_id, post_id, created_at) VALUES (?, 'system', ?, ?, ?, NOW())",
                    [$pa['user_id'], $text2, $user_id, $post_id]);
            }
        } catch (Exception $ex) {
            error_log('group reply notification error: ' . $ex->getMessage());
        }
        $_SESSION['flash'] = 'Yanıtınız eklendi';
        header('Location: ' . BASE_PATH . '/group_post.php?id=' . $post_id);
        exit;
    }
}
?>

<div class="main-container groups-layout">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= BASE_PATH ?>/g/<?= urlencode($slug) ?>">← <?= htmlspecialchars($parent_comment['group_name']) ?></a></li>
                <li><a href="<?= BASE_PATH ?>/group_post.php?id=<?= $post_id ?>">← Gönderiye Dön</a></li>
                <li><a href="<?= BASE_PATH ?>/topluluklar">Tüm Topluluklar</a></li>
            </ul>
        </div>
    </aside>

    <main class="content-area form-centered">
        <h1 class="section-title">↩️ Yanıt Ver</h1>

        <!-- Parent Comment -->
        <div class="comment-reply-box">
            <div class="muted small" style="margin-bottom:8px;">
                <strong>Yanıtlanıyor:</strong>
            </div>
            <div class="panel-muted">
                <div style="margin-bottom:5px;">
                    <a href="<?= profile_url($parent_comment['username']) ?>" class="link-strong">@<?= htmlspecialchars($parent_comment['username']) ?></a>
                    <span class="muted small" style="margin-left:8px;"><?= format_time($parent_comment['created_at']) ?></span>
                </div> 
                <div style="font-size:13px;color:#333;line-height:1.5;">
                    <?= nl2br(linkify_mentions($parent_comment['content'])) ?>
                </div>
            </div>
        </div>

        <!-- Reply Form -->
        <div class="post-form-container">
            <form method="POST" class="post-form">
                <input type="hidden" name="action" value="create_reply">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <textarea name="content" placeholder="Yanıtınızı yazın..." required></textarea>
                <div class="post-form-actions">
                    <span class="char-count">500+ karakter</span>
                    <div style="display:flex;gap:10px;">
                        <a href="<?= BASE_PATH ?>/group_post.php?id=<?= $post_id ?>" class="btn-cancel">İptal</a>
                        <button type="submit" class="btn-post">↩️ Yanıtla</button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">ℹ️ Bilgi</div>
            <div style="padding:10px;font-size:12px;color:#666;line-height:1.6;">
                <p style="margin-bottom:8px;"><strong>Grup:</strong> <?= htmlspecialchars($parent_comment['group_name']) ?></p>
                <p>Yanıtınız herkese açık olarak görünecektir.</p>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
