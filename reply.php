<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$post_id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;

if ($post_id <= 0 || $parent_id <= 0) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

$root_post = get_post($post_id);
$parent_post = get_post($parent_id);

if (!$root_post || !$parent_post) {
    $_SESSION['flash_error'] = 'Yanıt verilecek içerik bulunamadı.';
    header('Location: ' . BASE_PATH . '/post.php?id=' . $post_id);
    exit;
}

$parent_username = $parent_post['username'] ?? '';
$parent_content = rtrim(trim(strip_tags($parent_post['content'] ?? '')));
if (mb_strlen($parent_content) > 300) {
    $parent_content = mb_substr($parent_content, 0, 297) . '...';
}
?>

<div class="main-container">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= BASE_PATH ?>/index.php">← Ana Sayfa</a></li>
                <li><a href="<?= BASE_PATH ?>/post.php?id=<?= $post_id ?>">← Gönderiye Dön</a></li>
            </ul>
        </div>
    </aside>

    <main class="content-area form-centered">
        <h1 class="section-title">Yanıt Yaz</h1>

        <div class="post-form-container">
            <div class="panel-muted small" style="margin-bottom:12px;">
                <div> 
                    <strong>@<?= htmlspecialchars($parent_username) ?></strong> kullanıcısına yanıt
                </div>
            </div>
            <div class="panel-muted" style="margin-bottom:12px; padding:10px; background:#f9f9f9; border:1px solid #ddd;">
                <div style="font-size:13px; font-weight:600; margin-bottom:4px;">Şu içerik için yanıt veriyorsun:</div>
                <blockquote style="margin:0; padding-left:12px; border-left:3px solid #ccc; color:#555;">
                    <?= htmlspecialchars($parent_content, ENT_QUOTES, 'UTF-8') ?>
                </blockquote>
            </div>

            <?php $post_action = post_url($post_id) . (!empty($_REQUEST['sid']) ? ('?sid=' . rawurlencode($_REQUEST['sid'])) : ''); ?>
            <form method="POST" action="<?= $post_action ?>" class="post-form">
                <input type="hidden" name="action" value="create_reply">
                <input type="hidden" name="parent_id" value="<?= $parent_id ?>">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <?php if (!empty($_REQUEST['sid'])): ?>
                    <input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid']) ?>">
                <?php endif; ?>
                <textarea name="content" placeholder="@<?= htmlspecialchars($parent_username) ?> yanıtını yaz..." required><?= sanitize_input($_POST['content'] ?? '') ?></textarea>
                <div class="post-form-actions">
                    <span class="char-count">500+ karakter (otomatik bölünür)</span>
                    <div style="display:flex;gap:10px;">
                        <a href="<?= post_url($post_id) ?>" class="btn-cancel">İptal</a>
                        <button type="submit" class="btn-post">Yanıtla</button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">İpuçları</div>
            <div style="padding:10px;font-size:11px;color:#666;line-height:1.6;">
                <p style="margin-bottom:8px;">💬 Yanıtlarınız herkes tarafından görülebilir</p>
                <p style="margin-bottom:8px;">🚫 Küfür ve hakaret içeren yanıtlar silinir</p>
                <p>📝 500+ karakter yazabilirsiniz</p>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
