<?php
require_once __DIR__ . '/includes/header.php';

if (!$current_user_id) {
    header('Location: ' . home_url());
    exit;
}

$META_TITLE = 'Favoriler — ' . SITE_NAME;
$CANONICAL_URL = favorites_url();

$favorites = [];
$favorites_enabled = favorites_table_exists();

if ($favorites_enabled) {
    try {
        $sql = 'SELECT p.*, u.username, EXISTS(SELECT 1 FROM post_edits WHERE post_id = p.id) AS has_edits, '
             . '(SELECT COUNT(*) FROM posts WHERE parent_id = p.id AND deleted_at IS NULL) AS comment_count, '
             . '(SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS like_count '
             . 'FROM favorites f '
             . 'JOIN posts p ON p.id = f.post_id '
             . 'JOIN users u ON u.id = p.user_id '
             . 'WHERE f.user_id = ? AND p.deleted_at IS NULL AND u.deleted_at IS NULL '
             . 'ORDER BY f.created_at DESC '
             . 'LIMIT 100';
        $stmt = query($sql, [$current_user_id]);
        $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($favorites)) {
            $post_ids = array_column($favorites, 'id');
            $images_map = function_exists('batch_get_images_for_posts') ? batch_get_images_for_posts($post_ids) : [];
            $polls_map  = function_exists('batch_get_polls_for_posts')  ? batch_get_polls_for_posts($post_ids)  : [];
            $tests_map  = function_exists('batch_get_tests_for_posts')  ? batch_get_tests_for_posts($post_ids)  : [];

            foreach ($favorites as &$post) {
                $post['image'] = $images_map[$post['id']] ?? null;
                $post['poll']  = $polls_map[$post['id']]  ?? null;
                $post['test']  = $tests_map[$post['id']]  ?? null;
            }
            unset($post);
        }
    } catch (Exception $e) {
        error_log('favorites page query failed: ' . $e->getMessage());
    }
}
?>

<div class="main-container">
    <aside class="sidebar sidebar-left sidebar-card">
        <div class="sidebar-section mb-12">
            <div class="sidebar-section-title">Gezinti</div>
            <ul class="side-menu no-margin">
                <li><a href="<?= home_url() ?>"><span class="menu-icon icon-home" aria-hidden="true"></span>Ana Sayfa</a></li>
                <li><a href="<?= search_url() ?>"><span class="menu-icon icon-search" aria-hidden="true"></span>Ara</a></li>
                <li><a href="<?= notification_url() ?>"><span class="menu-icon icon-bell" aria-hidden="true"></span>Bildirimler</a></li>
                <li><a href="<?= BASE_PATH ?>/topluluklar"><span class="menu-icon icon-users" aria-hidden="true"></span>Topluluklar</a></li>
                <li><a href="<?= BASE_PATH ?><?= use_clean_urls() ? '/etkinlikler' : '/events.php' ?>"><span class="menu-icon icon-calendar" aria-hidden="true"></span>Etkinlikler</a></li>
                <li><a href="<?= favorites_url() ?>"><span class="menu-icon icon-star" aria-hidden="true"></span>Favoriler</a></li>
                <li><a href="<?= profile_url($current_user['username']) ?>"><span class="menu-icon icon-user" aria-hidden="true"></span>Profil</a></li>
                <li><a href="<?= settings_url() ?>"><span class="menu-icon icon-settings" aria-hidden="true"></span>Ayarlar</a></li>
            </ul>
        </div>
        <div class="sidebar-section invite">
            <div class="sidebar-section-title">Davet</div>
            <div class="sidebar-note padded">
                Arkadaşlarını davet ederek topluluğu büyütebilirsin.
            </div>
            <a href="<?= BASE_PATH ?>/davet-et" class="invite-btn">📩 Davet Et</a>
        </div>
    </aside>

    <main class="content-area page-favorites">
        <h1 class="section-title">Favoriler</h1>
        <?php if (!$favorites_enabled): ?>
            <div class="card-box padded">
                <p class="muted">Favoriler tablosu veritabanınızda bulunamadı. Favoriler özelliğini kullanabilmek için tabloyu eklemeniz gerekiyor.</p>
            </div>
        <?php elseif (empty($favorites)): ?>
            <div class="card-box padded">
                <p class="muted">Henüz favoriye aldığınız bir gönderi yok. Gönderileri favorilere eklemek için yıldız düğmesine basabilirsiniz.</p>
            </div>
        <?php else: ?>
            <div class="stacked-posts">
                <?php foreach ($favorites as $post): ?>
                    <?php $post['user_has_favorited'] = true; require __DIR__ . '/templates/post-card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">İpucu</div>
            <div class="sidebar-note padded">
                Favorilere eklediğiniz gönderiler burada saklanır. Yıldız düğmesine basarak mesajları hızlıca tekrar bulabilirsiniz.
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
