<?php /* EN + TR comments used. */
/**
 * Group Comment Edit Page
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

// Check ownership
if ($comment['user_id'] != $user_id) {
    $_SESSION['flash_error'] = 'Bu yorumu düzenleme yetkiniz yok';
    header('Location: ' . BASE_PATH . '/group_post.php?id=' . $post_id);
    exit;
}

$slug = $comment['slug'];
?>

<div class="main-container single-column">
    <main class="content-area form-centered">
        <h1 class="section-title">✏️ Yorumu Düzenle</h1>

        <?php if (isset($_SESSION['flash_error'])): ?>
            <div style="background:#ffebee;border:1px solid #e74c3c;padding:12px;margin-bottom:15px;border-radius:3px;color:#c62828;font-size:13px;">
                <?= htmlspecialchars($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div class="post-form-container">
            <a href="<?= BASE_PATH ?>/group_post.php?id=<?= $post_id ?>" class="back-link">< Gönderiye Dön</a>
            
            <form method="POST" action="<?= BASE_PATH ?>/api/group_comment_edit.php" class="post-form">
                <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                <input type="hidden" name="post_id" value="<?= $post_id ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div style="padding:15px;background:#f9f9f9;border-radius:3px;margin-bottom:15px;border:1px solid #e0e0e0;">
                    <div style="margin-bottom:10px;color:#666;font-size:12px;">
                        <span style="background:#5a9a3c;color:#fff;padding:3px 8px;border-radius:3px;font-weight:600;">#<?= $comment_id ?></span>
                        <span style="margin-left:10px;">@<?= htmlspecialchars($comment['username']) ?></span>
                    </div>
                </div>
                
                <textarea 
                    name="content" 
                    id="content" 
                    rows="6" 
                    required 
                    placeholder="Yorumunuzu buraya yazın..."
                ><?= htmlspecialchars($comment['content']) ?></textarea>
                
                <div class="post-form-actions">
                    <span class="char-count">
                        <?= mb_strlen($comment['content']) ?> karakter
                    </span>
                    <div style="display:flex;gap:10px;">
                        <a href="<?= BASE_PATH ?>/group_post.php?id=<?= $post_id ?>" class="btn-cancel">İptal</a>
                        <button type="submit" class="btn-post">💾 Kaydet</button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">📌 Düzenleme Kuralları</div>
            <div style="padding:10px;font-size:12px;color:#666;">
                <ul style="margin:0;padding-left:20px;line-height:1.8;">
                    <li>Yorumunuzu istediğiniz zaman düzenleyebilirsiniz</li>
                    <li>Uygunsuz kelimeler otomatik olarak sansürlenir</li>
                    <li>Değişiklikler anında yayınlanır</li>
                </ul>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
