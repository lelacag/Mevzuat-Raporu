<?php
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$slug = $_GET['slug'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

if (!$slug) {
    $_SESSION['flash_error'] = 'Grup bulunamadı.';
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

if (USE_CLEAN_URLS && !empty($slug) && strpos($_SERVER['REQUEST_URI'], '/group_members.php') !== false) {
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . group_members_url($slug));
    exit;
}

$pdo = db_connect();
$groupSelect = 'SELECT * FROM groups_table WHERE slug = ?';
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM groups_table LIKE 'deleted_at'")->fetchColumn();
    if ($colCheck) {
        $groupSelect .= ' AND deleted_at IS NULL';
    }
} catch (Exception $_) {
    // If schema doesn't have deleted_at, ignore
}
$groupSelect .= ' LIMIT 1';
$group_stmt = $pdo->prepare($groupSelect);
$group_stmt->execute([$slug]);
$group = $group_stmt->fetch(PDO::FETCH_ASSOC);
if (!$group) {
    $canon_slug = generate_slug($slug);
    if ($canon_slug !== $slug) {
        $altGroupSelect = 'SELECT * FROM groups_table WHERE slug = ?';
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM groups_table LIKE 'deleted_at'")->fetchColumn();
            if ($colCheck) {
                $altGroupSelect .= ' AND deleted_at IS NULL';
            }
        } catch (Exception $_) {
            // If schema doesn't have deleted_at, ignore
        }
        $altGroupSelect .= ' LIMIT 1';

        $alt_stmt = $pdo->prepare($altGroupSelect);
        $alt_stmt->execute([$canon_slug]);
        $alt_group = $alt_stmt->fetch(PDO::FETCH_ASSOC);
        if ($alt_group) {
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: ' . group_members_url($canon_slug));
            exit;
        }
    }
    $_SESSION['flash_error'] = 'Grup bulunamadı.';
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

$is_member = false;
if ($user_id) {
    $member_check = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
    $member_check->execute([$group['id'], $user_id]);
    $is_member = (bool)$member_check->fetchColumn();
}

if (!empty($group['is_private']) && !$is_member && !is_admin()) {
    $_SESSION['flash_error'] = 'Bu grup özel. Üyeleri görmek için katılmanız gerekir.';
    header('Location: ' . group_url($slug));
    exit;
}

// Count total members
$count_stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM group_members WHERE group_id = ?');
$count_stmt->execute([$group['id']]);
$total_members = (int)$count_stmt->fetchColumn();

// Get members ordered by last_activity desc (active first)
$members_stmt = $pdo->prepare(
    'SELECT gm.*, u.username, u.role, u.is_online, u.last_activity
     FROM group_members gm
     JOIN users u ON gm.user_id = u.id
     WHERE gm.group_id = ?
     ORDER BY u.last_activity DESC, gm.joined_at DESC
     LIMIT ? OFFSET ?'
);
$members_stmt->execute([$group['id'], $per_page, $offset]);
$members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_pages = (int)ceil($total_members / $per_page);

?>

<div class="main-container groups-layout">
    <aside class="sidebar sidebar-left"></aside>
    <main class="content-area form-centered">
        <div class="card-box padded">
            <h2>Üyeler • <?= htmlspecialchars($group['name']) ?></h2>
            <p><?= $total_members ?> üye</p>
            <ul class="sidebar-list-plain" style="margin-bottom:16px;">
                <?php foreach ($members as $member): ?>
                    <li>
                        <a href="<?= profile_url($member['username']) ?>">
                            <?= htmlspecialchars($member['username']) ?>
                            <?php if (!empty($member['role']) && $member['role'] === 'admin'): ?>
                                <span class="badge">yönetici</span>
                            <?php endif; ?>
                            <?php if (!empty($member['is_online'])): ?>
                                <span class="badge badge-success">aktif</span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="pagination" style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php if ($page > 1): ?>
                    <a class="btn btn-outline" href="<?= BASE_PATH ?>/group_members.php?slug=<?= rawurlencode($slug) ?>&page=<?= $page - 1 ?>">‹ Önceki</a>
                <?php endif; ?>
                <?php if ($page < $total_pages): ?>
                    <a class="btn btn-outline" href="<?= BASE_PATH ?>/group_members.php?slug=<?= rawurlencode($slug) ?>&page=<?= $page + 1 ?>">Sonraki ›</a>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <aside class="sidebar sidebar-right"></aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
