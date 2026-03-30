<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header("Location: " . BASE_PATH . "/login.php");
    exit;
}

$post_id = $_GET['id'] ?? null;
$return_id = isset($_GET['return_id']) ? (int)$_GET['return_id'] : null;
if (!$post_id) {
    header("Location: " . BASE_PATH . "/index.php");
    exit;
}

// Get post with username
$stmt = query("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ? AND p.deleted_at IS NULL", [$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    $_SESSION['flash_error'] = 'Gönderi bulunamadı';
    header("Location: " . BASE_PATH . "/index.php");
    exit;
}

// Check if user can edit
$user_info = get_user($user_id);
// Allow premium users even if they still have role 'rookie'
if ($user_info && isset($user_info['role']) && $user_info['role'] === 'rookie' && !is_user_premium($user_id)) {
    // Show green notification (Premium CTA) and redirect back to the post
    error_log("edit page blocked: rookie user={$user_id} post={$post_id} is_premium=" . (is_user_premium($user_id) ? '1' : '0'));
    $_SESSION['flash'] = sprintf(t('rookie_restricted_edit'), BASE_PATH . '/premium.php');
    header("Location: " . BASE_PATH . "/post.php?id=" . $post_id);
    exit;
}
if (!can_edit_post($user_id, $post_id)) {
    error_log("edit page blocked: can_edit_post returned false user={$user_id} post={$post_id}");
    $_SESSION['flash_error'] = 'Bu gönderiyi düzenleme yetkiniz yok';
    header("Location: " . BASE_PATH . "/post.php?id=" . $post_id);
    exit;
}
?>

<div class="main-container">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= BASE_PATH ?>/index.php">← Ana Sayfa</a></li>
                <li><a href="<?= BASE_PATH ?>/post.php?id=<?= $return_id ?: $post_id ?>">← Gönderiye Dön</a></li>
                <li><a href="<?= profile_url($post['username']) ?>">Profilim</a></li>
            </ul>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-title">Düzenleme Bilgisi</div>
            <div class="sidebar-note padded">
                <p class="muted mb-8"><strong>Gönderi #<?= $post_id ?></strong></p>
                <p class="muted mb-8">✏️ Gönderi düzenleme yalnızca <strong>Premium</strong> kullanıcılar içindir</p>
                <p class="muted mb-8">📝 Düzenleme geçmişi kaydedilir</p>
                <p class="muted">🔒 Kötü kelimeler otomatik sansürlenir</p>
            </div>
        </div>
    </aside>

    <main class="content-area narrow">
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
            <a href="<?= BASE_PATH ?>/post.php?id=<?= $return_id ?: $post_id ?>" class="back-link">< Gönderiye Dön</a>
            
            <form method="POST" action="<?= BASE_PATH ?>/api/edit_post.php" class="post-form">
                <input type="hidden" name="post_id" value="<?= $post_id ?>">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <?php if ($return_id): ?>
                    <input type="hidden" name="return_id" value="<?= $return_id ?>">
                <?php endif; ?>
                
                <div class="padded info-box" style="margin-bottom:15px;border:1px solid #e0e0e0;border-radius:3px;background:#f9f9f9;">
                    <div class="muted" style="margin-bottom:10px;font-size:12px;">
                        <span class="badge-pill color-2ecc71">#<?= $post_id ?></span>
                        <span class="ml-10">@<?= htmlspecialchars($post['username']) ?></span>
                    </div>
                </div>
                
                <textarea name="content" id="content" rows="8" required placeholder="Gönderinizi buraya yazın..." class="input-full textarea-large"><?= htmlspecialchars($post['content']) ?></textarea>
                
                <div class="post-form-actions">
                    <span class="char-count">
                        <?= mb_strlen($post['content']) ?> / 
                        <?php 
                        $limit = get_user_post_limit($user_id);
                        if ($limit == 0): ?>
                            ∞ karakter
                        <?php else: ?>
                            <?= $limit ?> karakter
                        <?php endif; ?>
                    </span>
                    <div class="flex-row">
                        <a href="<?= BASE_PATH ?>/post.php?id=<?= $return_id ?: $post_id ?>" class="btn-cancel">İptal</a>
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
                    <li>Gönderi düzenleme yalnızca <strong>Premium</strong> kullanıcılar içindir</li>
                    <li>Düzenleme geçmişi sistem tarafından kaydedilir</li>
                    <li>Uygunsuz kelimeler otomatik olarak sansürlenir</li>
                    <li>Değişiklikler anında yayınlanır</li>
                </ul>
            </div>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-title">ℹ️ Yardım</div>
            <div style="padding:10px;font-size:12px;color:#666;line-height:1.6;">
                <p><strong>Karakter Limiti:</strong></p>
                <p style="margin:5px 0;">
                    <?php if (get_user_post_limit($user_id) == 0): ?>
                        Sınırsız karakter kullanabilirsiniz
                    <?php else: ?>
                        En fazla <?= get_user_post_limit($user_id) ?> karakter
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>