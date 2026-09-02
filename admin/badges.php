<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}
$current_user = get_user($current_user_id);
require_admin_perm('manage_badges');

$badges = get_badges(500);
$csrf_token = generate_csrf_token();

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>
        <h1 class="page-title">Admin - Rozetler</h1>

        <section class="badges-list">
            <?php if (empty($badges)): ?>
                <div class="empty-state">Henüz rozet tanımlanmamış.</div>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                    <thead>
                        <tr><th>ID</th><th>Ad</th><th>Min Beğeni</th><th>Açıklama</th><th>Aksiyon</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($badges as $b): ?>
                            <tr>
                                <td><?= $b['id'] ?></td>
                                <td><?= htmlspecialchars($b['name']) ?> <small>(<?= htmlspecialchars($b['slug']) ?>)</small></td>
                                <td><?= intval($b['min_likes']) ?></td>
                                <td><?= nl2br(htmlspecialchars($b['description'] ?? '')) ?></td>
                                <td>
                                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_delete_badge.php" class="form-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="badge_id" value="<?= $b['id'] ?>">
                                        <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/badges.php">
                                        <button type="submit" class="btn btn-danger">Sil</button>
                                    </form>
                                    <a href="<?= BASE_PATH ?>/admin/badges_edit.php?id=<?= $b['id'] ?>" class="btn">Düzenle</a>
                                </td> 
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="badge-create">
            <h2>Yeni Rozet Ekle</h2>
            <form method="POST" action="<?= BASE_PATH ?>/api/admin_create_badge.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/badges.php">
                <div>
                    <label>Ad</label>
                    <input type="text" name="name" required>
                </div>
                <div>
                    <label>Slug (tek kelime)</label>
                    <input type="text" name="slug" required>
                </div>
                <div>
                    <label>Min Beğeni</label>
                    <input type="number" name="min_likes" min="0" value="0">
                </div>
                <div>
                    <label>Açıklama</label>
                    <textarea name="description"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Oluştur</button>
            </form>

            <form method="POST" action="<?= BASE_PATH ?>/api/admin_sync_badges.php" class="mt-12">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/badges.php">
                <button type="submit" class="btn">Tüm Kullanıcı Roztlerini Senkronize Et</button>
            </form>
        </section>

<?php include __DIR__ . '/_footer.php'; ?>