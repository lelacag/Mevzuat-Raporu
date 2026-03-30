<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$current_user = get_user($current_user_id);
require_admin_perm('manage_badges');

$id = intval($_GET['id'] ?? 0);
$badge = null;
$assigned_users = [];
if ($id) {
    $badge = get_badge($id);
    $stmt = query("SELECT u.id, u.username FROM user_badges ub JOIN users u ON ub.user_id = u.id WHERE ub.badge_id = ?", [$id]);
    $assigned_users = $stmt->fetchAll();
}

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>
        <h1 class="page-title">Rozet Düzenle</h1>

        <?php if (!$badge): ?>
            <div class="empty-state">Rozet bulunamadı.</div>
        <?php else: ?>
            <form method="POST" action="<?= BASE_PATH ?>/api/admin_update_badge.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/badges_edit.php?id=<?= $badge['id'] ?>">
                <input type="hidden" name="id" value="<?= $badge['id'] ?>">
                <div>
                    <label>Ad</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($badge['name']) ?>" required>
                </div>
                <div>
                    <label>Slug</label>
                    <input type="text" name="slug" value="<?= htmlspecialchars($badge['slug']) ?>" required>
                </div>
                <div>
                    <label>Min Beğeni</label>
                    <input type="number" name="min_likes" min="0" value="<?= intval($badge['min_likes']) ?>">
                </div>
                <div>
                    <label>Açıklama</label>
                    <textarea name="description"><?= htmlspecialchars($badge['description']) ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Güncelle</button>
            </form>

            <h2>Atanmış Kullanıcılar</h2>
            <?php if (empty($assigned_users)): ?>
                <p>Henüz kimseye atanmış değil.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($assigned_users as $u): ?>
                        <li>@<?= htmlspecialchars($u['username']) ?>
                            <form method="POST" action="<?= BASE_PATH ?>/api/admin_remove_badge.php" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/badges_edit.php?id=<?= $badge['id'] ?>">
                                <input type="hidden" name="badge_id" value="<?= $badge['id'] ?>">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-secondary">Kaldır</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h3>Manuel Atama</h3>
            <form method="POST" action="<?= BASE_PATH ?>/api/admin_assign_badge.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/badges_edit.php?id=<?= $badge['id'] ?>">
                <input type="hidden" name="badge_id" value="<?= $badge['id'] ?>">
                <div>
                    <label>Kullanıcı adı</label>
                    <input type="text" name="username" placeholder="kullanici adi">
                </div>
                <button type="submit" class="btn">Atama Yap</button>
            </form>
        <?php endif; ?>


<?php include __DIR__ . '/_footer.php'; ?>