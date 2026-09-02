<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/header.php';

$current_user_id = get_current_user_id();

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Show only currently online users (is_online = 1)
$count_stmt = query("SELECT COUNT(*) as c FROM users WHERE deleted_at IS NULL AND is_online = 1 AND email_verified = 1");
$count_row = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_active = $count_row ? (int)$count_row['c'] : 0;
$total_pages = max(1, (int)ceil($total_active / $per_page));

// (no JSON mode any more – count is fetched directly by server controller)

// Fetch users who are currently online
$stmt = query("SELECT id, username, bio, is_premium, last_activity FROM users WHERE deleted_at IS NULL AND is_online = 1 AND email_verified = 1 ORDER BY last_activity DESC LIMIT ? OFFSET ?", [$per_page, $offset]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/users.css">

<div class="main-container single-column">
    <main class="content-area">
        <h1 class="section-title">📈 Çevrimiçi Kullanıcılar</h1>
        <div class="section-sub">Toplam aktif kullanıcı: <strong><?= number_format($total_active) ?></strong></div>

        <?php if (empty($users)): ?>
            <div class="empty-state">Aktif kullanıcı bulunmuyor.</div>
        <?php else: ?>
            <div class="active-users-grid">
                <?php foreach ($users as $u): ?>
                    <div class="user-card">
                        <div class="avatar"><?= htmlspecialchars(strtoupper(substr($u['username'], 0, 1))) ?></div>
                        <div class="user-info">
                            <div class="user-online"><div class="online-dot"></div><div class="online-text">Çevrimiçi</div></div>
                            <a class="username" href="<?= profile_url($u['username']) ?>">@<?= htmlspecialchars($u['username']) ?></a>
                            <?php if (!empty($u['is_premium'])): ?><span class="premium-star">⭐</span><?php endif; ?>
                            <div class="bio"><?= htmlspecialchars(mb_strimwidth($u['bio'] ?? '', 0, 120, '...')) ?></div>
                        </div>
                        <div class="last-activity">Son aktif: <?= $u['last_activity'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($u['last_activity']))) : 'bilinmiyor' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a class="page-link" href="?page=<?= $page - 1 ?>">‹ Önceki</a>
                <?php else: ?>
                    <span class="page-link disabled">‹ Önceki</span>
                <?php endif; ?>

                <span class="page-info">Sayfa <?= $page ?> / <?= $total_pages ?></span>

                <?php if ($page < $total_pages): ?>
                    <a class="page-link" href="?page=<?= $page + 1 ?>">Sonraki ›</a>
                <?php else: ?>
                    <span class="page-link disabled">Sonraki ›</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
