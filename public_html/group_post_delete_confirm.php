<?php /* EN + TR comments used. */
/**
 * Delete Group Post Confirmation Page
 */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header("Location: " . BASE_PATH . "/giris");
    exit;
}

$post_id = $_GET['id'] ?? null;
$slug = $_GET['slug'] ?? '';

if (!$post_id || !$slug) {
    header("Location: " . BASE_PATH . "/topluluklar");
    exit;
}

// Get group post
$stmt = query("SELECT gp.*, u.username, g.slug, g.name as group_name, g.created_by as group_owner 
               FROM group_posts gp 
               JOIN users u ON gp.user_id = u.id 
               JOIN groups_table g ON gp.group_id = g.id 
               WHERE gp.id = ? AND g.slug = ?", [$post_id, $slug]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    $_SESSION['flash_error'] = 'Gönderi bulunamadı';
    header("Location: " . BASE_PATH . "/g/" . urlencode($slug));
    exit;
}

// Check if user owns this post or is group owner
if ($post['user_id'] != $user_id && $post['group_owner'] != $user_id) {
    $_SESSION['flash_error'] = 'Bu gönderiyi silme yetkiniz yok';
    header("Location: " . BASE_PATH . "/g/" . urlencode($slug));
    exit;
}
?>

<div class="main-container">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= BASE_PATH ?>/g/<?= urlencode($slug) ?>">← Gruba Dön</a></li>
                <li><a href="<?= BASE_PATH ?>/topluluklar">Tüm Topluluklar</a></li>
            </ul>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="content-area">
        <div style="padding:15px;border-bottom:1px solid #eee;">
            <h1 style="font-size:18px;color:#e74c3c;margin-bottom:5px;">⚠️ Gönderiyi Sil</h1>
            <p style="font-size:12px;color:#666;">Bu işlem geri alınamaz</p>
        </div>

        <div style="padding:20px;">
            <div style="background:#fff;border:1px solid #ddd;border-radius:3px;padding:15px;margin-bottom:20px;">
                <div style="font-size:12px;color:#666;margin-bottom:10px;">
                    <strong><?= htmlspecialchars($post['username']) ?></strong> · <?= format_time($post['created_at']) ?>
                </div>
                <div style="font-size:14px;color:#333;">
                    <?= nl2br(htmlspecialchars($post['content'])) ?>
                </div>
            </div>

            <p style="font-size:13px;color:#666;margin-bottom:20px;">
                Bu gönderiyi silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.
            </p>

            <form method="POST" action="<?= BASE_PATH ?>/api/group_post_delete.php">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                <input type="hidden" name="referer" value="<?= BASE_PATH ?>/g/<?= urlencode($slug) ?>">
                
                <div style="display:flex;gap:10px;">
                    <button type="submit" class="btn-join-group btn-leave-group" style="background:#e74c3c;">Evet, Sil</button>
                    <a href="<?= BASE_PATH ?>/g/<?= urlencode($slug) ?>" class="btn-join-group" style="text-decoration:none;">Hayır, İptal</a>
                </div>
            </form>
        </div>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">Dikkat</div>
            <p style="font-size:11px;color:#666;line-height:1.6;">
                Gönderi silindikten sonra geri getirilemez. Emin değilseniz iptal edin.
            </p>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
