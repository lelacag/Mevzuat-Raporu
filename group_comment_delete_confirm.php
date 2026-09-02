<?php
/**
 * Group Comment Delete Confirmation Page
 */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$comment_id = $_GET['id'] ?? 0;
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

// Get comment
$stmt = query("SELECT c.*, u.username, gp.group_id, g.slug, g.name as group_name
               FROM group_post_comments c 
               JOIN users u ON c.user_id = u.id 
               JOIN group_posts gp ON c.post_id = gp.id
               JOIN groups_table g ON gp.group_id = g.id
               WHERE c.id = ? AND c.deleted_at IS NULL", [$comment_id]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comment) {
    $_SESSION['flash_error'] = 'Yorum bulunamadı';
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

// Check ownership or admin
$is_admin = function_exists('is_admin') && is_admin();
if ($comment['user_id'] != $user_id && !$is_admin) {
    $_SESSION['flash_error'] = 'Bu yorumu silme yetkiniz yok';
    header('Location: ' . BASE_PATH . '/group_post.php?id=' . $post_id);
    exit;
}

$slug = $comment['slug'];
$has_child_replies = function_exists('group_comment_has_active_replies')
    ? group_comment_has_active_replies($comment_id)
    : ((int)query("SELECT COUNT(*) FROM group_post_comments WHERE parent_id = ? AND deleted_at IS NULL", [$comment_id])->fetchColumn() > 0);
$back_url = !empty($slug)
    ? (BASE_PATH . '/g/' . rawurlencode($slug) . '/post/' . (int)$post_id)
    : (BASE_PATH . '/group_post.php?id=' . (int)$post_id);
?>

<div class="main-container single-column">
    <main class="content-area form-centered">
        <h1 class="section-title">🗑️ Yorumu Sil</h1>

        <div style="padding:20px;">
            <div style="background:#fff3cd;border:1px solid #ffc107;padding:15px;border-radius:3px;margin-bottom:20px;">
                <p style="color:#856404;font-size:13px;margin-bottom:10px;">
                    <strong>⚠️ Dikkat!</strong> Bu işlem geri alınamaz.
                </p>
                <?php if ($has_child_replies): ?>
                    <p style="color:#856404;font-size:12px;">
                        Bu yanıtın altında başka yanıtlar var. İçerik silinmiş olarak işaretlenecek; alt yanıtlar görünmeye devam edecek.
                    </p>
                <?php else: ?>
                    <p style="color:#856404;font-size:12px;">
                        Aşağıdaki yorumu silmek istediğinizden emin misiniz? Yorum silinmiş olarak işaretlenecektir.
                    </p>
                <?php endif; ?>
            </div>

            <div style="background:#f9f9f9;border:1px solid #e0e0e0;padding:15px;border-radius:3px;margin-bottom:20px;">
                <div style="margin-bottom:10px;color:#666;font-size:12px;">
                    <span style="background:#5a9a3c;color:#fff;padding:3px 8px;border-radius:3px;font-weight:600;">#<?= $comment_id ?></span>
                    <span style="margin-left:10px;">@<?= htmlspecialchars($comment['username']) ?></span>
                    <span style="margin-left:10px;color:#999;"><?= format_time($comment['created_at']) ?></span>
                </div>
                <div style="font-size:13px;color:#333;line-height:1.6;padding:10px 0;">
                    <?= nl2br(htmlspecialchars($comment['content'])) ?>
                </div>
            </div>

            <div style="display:flex;gap:10px;">
                <a href="<?= htmlspecialchars($back_url) ?>" class="btn-cancel">İptal</a>
                <form method="POST" action="<?= BASE_PATH ?>/api/group_comment_delete.php">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                    <input type="hidden" name="post_id" value="<?= $post_id ?>">
                    <button type="submit" class="btn-post" style="background:#e74c3c;">🗑️ Evet, Sil</button>
                </form>
            </div>
        </div>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">ℹ️ Bilgi</div>
            <div style="padding:10px;font-size:12px;color:#666;line-height:1.6;">
                <p>Yorumlar silindiğinde soft-delete olarak işaretlenir. Alt yanıtlar varsa silinmiş bir yer tutucu olarak kalır.</p>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
