<?php
/**
 * Groups Page - List all groups
 */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$tab = $_GET['tab'] ?? 'all'; // 'all' or 'my'
$category = $_GET['category'] ?? 'all';

// Get all groups with member counts
$groups = $pdo->query("
    SELECT 
        g.*,
        u.username as creator_name,
        COUNT(DISTINCT gm.user_id) as member_count,
        COUNT(DISTINCT gp.id) as post_count
    FROM groups_table g
    LEFT JOIN users u ON g.created_by = u.id
    LEFT JOIN group_members gm ON g.id = gm.group_id
    LEFT JOIN group_posts gp ON g.id = gp.group_id
    GROUP BY g.id
    ORDER BY member_count DESC, g.name ASC
")->fetchAll();

// Get user's groups if logged in
$user_groups = [];
$my_groups = [];
if ($user_id) {
    $stmt = $pdo->prepare("
        SELECT group_id 
        FROM group_members 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $user_groups = array_column($stmt->fetchAll(), 'group_id');
    
    // Detailed list of user's groups with stats
    if (!empty($user_groups)) {
        $placeholders = implode(',', array_fill(0, count($user_groups), '?'));
        $stmtMy = $pdo->prepare("
            SELECT 
                g.*,
                u.username as creator_name,
                COUNT(DISTINCT gm2.user_id) as member_count,
                COUNT(DISTINCT gp.id) as post_count
            FROM groups_table g
            LEFT JOIN users u ON g.created_by = u.id
            LEFT JOIN group_members gm2 ON g.id = gm2.group_id
            LEFT JOIN group_posts gp ON g.id = gp.group_id
            WHERE g.id IN ($placeholders)
            GROUP BY g.id
            ORDER BY g.name ASC
        ");
        $stmtMy->execute($user_groups);
        $my_groups = $stmtMy->fetchAll();
    }
}
?>

<div class="main-container groups-layout">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <?php if ($user_id): ?>
        <div class="sidebar-section sidebar-banner">
            <div class="banner-overlay"><span class="badge badge-primary">Pek Yakında</span></div>
            <div class="sidebar-title">🌐 Mesh Network</div>
            <p class="muted small">İnternetsiz bağlan!</p>
            <span class="muted small">Bölgeleri Keşfet »</span>
        </div>

        <div class="sidebar-section">
            <a href="groups_create.php" class="btn-join-group full-width text-center">
                + Topluluk Oluştur
            </a>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main Content -->
    <main class="content-area narrow">
        <div class="page-header">
            <h1 class="page-title">Topluluklar</h1>
            <p class="muted">İlgilendiğin konulardaki topluluğa katıl</p>
        </div>

        <!-- Tab Navigation -->
        <div class="tabs">
            <a href="?tab=all" class="tab <?= $tab === 'all' ? 'tab-active' : '' ?>">Tüm Topluluklar</a>
            <?php if ($user_id): ?>
            <a href="?tab=my" class="tab <?= $tab === 'my' ? 'tab-active' : '' ?>">Topluluklarım</a>
            <?php endif; ?>
        </div>

        <?php if (!$user_id): ?>
        <div class="notice notice-info text-center">
            <p class="muted mb-12">Topluluklara katılmak için giriş yapın</p>
            <a href="<?= BASE_PATH ?>/giris" class="btn-join-group">Giriş Yap</a>
        </div>
        <?php endif; ?> 

        <!-- All Groups Tab -->
        <?php if ($tab === 'all'): ?>
        <div class="groups-grid padded">
            <?php foreach ($groups as $group): ?>
            <article class="group-card">
                <!-- Header with name and button -->
                <div class="group-card-header">
                    <div class="group-card-title">
                        <a href="<?= group_url($group['slug']) ?>" class="group-link"><?= htmlspecialchars($group['name']) ?></a>
                    </div>
                    <div class="group-card-actions">
                        <?php if ($user_id): ?>
                            <?php if (in_array($group['id'], $user_groups)): ?>
                            <form method="POST" action="<?= BASE_PATH ?>/groups_leave.php" class="form-inline">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                <button type="submit" class="btn btn-leave">Ayrıl</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" action="<?= BASE_PATH ?>/groups_join.php" class="form-inline">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                <button type="submit" class="btn btn-join">Katıl</button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Description -->
                <div class="group-desc">
                    <?= htmlspecialchars($group['description']) ?>
                </div>
                
                <!-- Footer with stats -->
                <div class="group-footer">
                    <table class="group-stats">
                        <tr>
                            <td>👥 <?= $group['member_count'] ?> üye</td>
                            <td class="text-right">📝 <?= $group['post_count'] ?> gönderi</td>
                        </tr>
                    </table>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <!-- My Groups Tab -->
        <?php elseif ($tab === 'my' && $user_id): ?>
        <?php if (empty($my_groups)): ?>
        <div class="empty-state padded text-center">
            <p class="muted">Henüz hiçbir topluluğa katılmadınız.</p>
            <a href="?tab=all" class="link-strong">Toplulukları keşfet →</a>
        </div>
        <?php else: ?> 
        <div class="groups-grid padded">
            <?php foreach ($my_groups as $group): ?>
            <article class="group-card">
                <div class="group-card-header">
                    <div class="group-card-title"><a href="<?= group_url($group['slug']) ?>" class="group-link"><?= htmlspecialchars($group['name']) ?></a></div>
                    <div class="group-card-actions">
                        <form method="POST" action="<?= BASE_PATH ?>/groups_leave.php" class="form-inline">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                            <button type="submit" class="btn btn-leave">Ayrıl</button>
                        </form>
                    </div>
                </div>
                <div class="group-desc"><?= htmlspecialchars($group['description']) ?></div>
                <div class="group-footer">
                    <table class="group-stats">
                        <tr>
                            <td>👥 <?= $group['member_count'] ?> üye</td>
                            <td class="text-right">📝 <?= $group['post_count'] ?> gönderi</td>
                        </tr>
                    </table>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">İstatistikler</div>
            <p class="sidebar-note">
                Toplam <?= count($groups) ?> topluluk<br>
                <?php
                $total_members = array_sum(array_column($groups, 'member_count'));
                $total_posts = array_sum(array_column($groups, 'post_count'));
                ?>
                <?= $total_members ?> toplam üyelik<br>
                <?= $total_posts ?> toplam gönderi
            </p>
        </div>

        <div class="sidebar-section">
            <div class="sidebar-title">Bilgi</div>
            <p class="sidebar-note">
                Topluluklar ilgi alanlarına göre organize edilmiş topluluklar oluşturmanızı sağlar.
            </p>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
