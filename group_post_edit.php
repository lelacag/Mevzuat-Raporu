<?php
/**
 * Edit Group Post Page
 */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header("Location: " . BASE_PATH . "/login.php");
    exit;
}

$post_id = $_GET['id'] ?? null;
$slug = $_GET['slug'] ?? '';

if (!$post_id || !$slug) {
    header("Location: " . BASE_PATH . "/groups.php");
    exit;
}

// Get group post
$stmt = query("SELECT gp.*, u.username, g.slug, g.name as group_name 
               FROM group_posts gp 
               JOIN users u ON gp.user_id = u.id 
               JOIN groups_table g ON gp.group_id = g.id 
               WHERE gp.id = ? AND g.slug = ?", [$post_id, $slug]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    $_SESSION['flash_error'] = 'Gönderi bulunamadı';
    header("Location: " . BASE_PATH . "/group.php?slug=" . urlencode($slug));
    exit;
}

// Check if user owns this post
if ($post['user_id'] != $user_id) {
    $_SESSION['flash_error'] = 'Bu gönderiyi düzenleme yetkiniz yok';
    header("Location: " . BASE_PATH . "/group.php?slug=" . urlencode($slug));
    exit;
}
?>

<div class="main-container">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= BASE_PATH ?>/group.php?slug=<?= urlencode($slug) ?>">← <?= htmlspecialchars($post['group_name']) ?></a></li>
                <li><a href="<?= BASE_PATH ?>/group_post.php?id=<?= $post_id ?>">← Gönderiye Dön</a></li>
                <li><a href="<?= BASE_PATH ?>/topluluklar">Tüm Topluluklar</a></li>
            </ul>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-title">Düzenleme Bilgisi</div>
            <div class="sidebar-note padded">
                <p class="muted mb-8"><strong>Gönderi #<?= $post_id ?></strong></p>
                <p class="muted mb-8">✏️ Tüm kullanıcılar kendi gönderlerini düzenleyebilir</p>
                <p class="muted mb-8">📝 Düzenleme geçmişi kaydedilir</p>
                <p class="muted">🔒 Kötü kelimeler otomatik sansürlenir</p>
            </div>
        </div>
    </aside>

    <main class="content-area">
        <h1 class="section-title">✏️ Gönderiyi Düzenle</h1>

        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash'])): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($_SESSION['flash']) ?>
            </div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <div class="post-form-container">
            <a href="<?= BASE_PATH ?>/group_post.php?id=<?= $post_id ?>" class="back-link">< Gönderiye Dön</a>
            
            <form method="POST" action="<?= BASE_PATH ?>/api/group_post_edit.php" class="post-form">
                <input type="hidden" name="post_id" value="<?= $post_id ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="referer" value="<?= BASE_PATH ?>/group_post.php?id=<?= $post_id ?>">
                
                <div class="padded info-box" style="margin-bottom:15px;border:1px solid #e0e0e0;border-radius:3px;background:#f9f9f9;">
                    <div class="muted" style="margin-bottom:10px;font-size:12px;">
                        <span class="badge-pill color-2ecc71">#<?= $post_id ?></span>
                        <span class="ml-10">@<?= htmlspecialchars($post['username']) ?></span>
                        <span class="ml-10 muted small">· <?= htmlspecialchars($post['group_name']) ?></span>
                    </div>
                </div>
                
                <textarea name="content" id="content" rows="8" required placeholder="Gönderinizi buraya yazın..." class="input-full textarea-large"><?= htmlspecialchars($post['content']) ?></textarea>
                
                <div class="post-form-actions">
                    <span class="char-count">
                        <?= mb_strlen($post['content']) ?> karakter
                    </span>
                    <div class="flex-row">
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
            <div class="sidebar-note padded">
                <ul style="margin:0;padding-left:20px;line-height:1.8;">
                    <li>Gönderinizi istediğiniz zaman düzenleyebilirsiniz</li>
                    <li>Düzenleme geçmişi sistem tarafından kaydedilir</li>
                    <li>Uygunsuz kelimeler otomatik olarak sansürlenir</li>
                    <li>Değişiklikler anında yayınlanır</li>
                </ul>
            </div>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-title">ℹ️ Yardım</div>
            <div style="padding:10px;font-size:12px;color:#666;line-height:1.6;">
                <p><strong>Grup:</strong></p>
                <p style="margin:5px 0;">
                    <a href="<?= BASE_PATH ?>/group.php?slug=<?= urlencode($slug) ?>"><?= htmlspecialchars($post['group_name']) ?></a>
                </p>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
