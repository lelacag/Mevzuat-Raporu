<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$user_id = get_current_user_id();

// Get search parameters
$tag = $_GET['tag'] ?? '';
$query = $_GET['q'] ?? '';
$view = $_GET['view'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
// Tabs (no JS): posts | tags | users | groups
$tab = $_GET['tab'] ?? 'posts';

// Pagination settings
$items_per_page = 40;
$offset = ($page - 1) * $items_per_page;

// Prepare search query
$search_results = [];
$user_results = [];
$group_results = [];
$search_title = '';
$total_pages = 1;

if ($view === 'user_posts') {
    // Show all posts for a specific user
    $username = $_GET['username'] ?? '';
    if (empty($username)) {
        header('Location: ' . BASE_PATH . '/');
        exit;
    }
    
    $target_user = get_user_by_username($username);
    if (!$target_user) {
        $search_title = 'Kullanici Bulunamadi';
        $search_results = [];
    } else {
        $search_title = '@' . htmlspecialchars($username) . '\'nin Gonderileri';
        $pdo = db_connect();
        
        // Get total count
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM posts
            WHERE user_id = ? AND deleted_at IS NULL AND parent_id IS NULL
        ");
        $count_stmt->execute([$target_user['id']]);
        $total_count = $count_stmt->fetch()['total'];
        $total_pages = ceil($total_count / $items_per_page);
        
        // Get paginated posts
        $stmt = $pdo->prepare("
            SELECT p.*, u.username, u.is_premium,
                    COUNT(DISTINCT l.id) as like_count,
                    COUNT(DISTINCT r.id) as comment_count,
                    CASE WHEN ul.post_id IS NOT NULL THEN 1 ELSE 0 END as user_has_liked
             FROM posts p
             JOIN users u ON p.user_id = u.id
             LEFT JOIN likes l ON p.id = l.post_id
             LEFT JOIN posts r ON p.id = r.parent_id AND r.deleted_at IS NULL
             LEFT JOIN likes ul ON p.id = ul.post_id AND ul.user_id = ?
             WHERE p.user_id = ? AND p.deleted_at IS NULL AND p.parent_id IS NULL
             GROUP BY p.id
             ORDER BY p.created_at DESC
             LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user_id ?: 0, $target_user['id'], $items_per_page, $offset]);
        $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($view === 'newest_users') {
    $search_title = 'Yeni Üyeler';
    $stmt = query(
        "SELECT id, username, created_at FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 50"
    );
    $user_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($view === 'active_users') {
    $search_title = 'Aktif Üyeler';
    $pdo = db_connect();
    
    // Get total count
    $count_stmt = $pdo->query("
        SELECT COUNT(DISTINCT u.id) as total
        FROM users u
        JOIN posts p ON u.id = p.user_id
        WHERE u.is_online = 1 AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $total_count = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_count / $items_per_page);
    
    // Get paginated results
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.username, u.is_online, u.last_activity, COUNT(p.id) as post_count
        FROM users u
        JOIN posts p ON u.id = p.user_id
        WHERE u.is_online = 1 AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY u.id
        ORDER BY post_count DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$items_per_page, $offset]);
    $user_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($tab === 'tags' || $tag) {
    // Tag search with relevancy sorting
    $active_tag = $tag ?: ltrim($query, '#');
    if ($active_tag) { record_tag_click($active_tag); }
    $search_title = '#' . htmlspecialchars($active_tag);
    $pdo = db_connect();
    
    // Get total count for pagination
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM posts p
        WHERE p.deleted_at IS NULL 
          AND p.parent_id IS NULL
          AND p.content LIKE ?
    ");
    $count_stmt->execute(['%#' . $active_tag . '%']);
    $total_count = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_count / $items_per_page);
    
    // Get posts sorted by relevancy: likes + comments (engagement), then recency
    $stmt = $pdo->prepare("
        SELECT p.*, u.username, u.is_premium,
                COUNT(DISTINCT l.id) as like_count,
                COUNT(DISTINCT r.id) as comment_count,
                CASE WHEN ul.post_id IS NOT NULL THEN 1 ELSE 0 END as user_has_liked,
                (COUNT(DISTINCT l.id) * 0.5 + COUNT(DISTINCT r.id) * 1.0 - DATEDIFF(NOW(), p.created_at) * 0.1) as relevance_score
         FROM posts p
         JOIN users u ON p.user_id = u.id
         LEFT JOIN likes l ON p.id = l.post_id
         LEFT JOIN posts r ON p.id = r.parent_id AND r.deleted_at IS NULL
         LEFT JOIN likes ul ON p.id = ul.post_id AND ul.user_id = ?
         WHERE p.deleted_at IS NULL 
           AND p.parent_id IS NULL
           AND p.content LIKE ?
         GROUP BY p.id
         ORDER BY relevance_score DESC, p.created_at DESC
         LIMIT ? OFFSET ?
    ");
    $stmt->execute([$user_id ?: 0, '%#' . $active_tag . '%', $items_per_page, $offset]);
    $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($view === 'user_polls') {
    $username = $_GET['username'] ?? '';
    if (empty($username)) {
        header('Location: ' . BASE_PATH . '/');
        exit;
    }
    $target_user = get_user_by_username($username);
    if (!$target_user) {
        $search_title = 'Kullanıcı Bulunamadi';
        $search_results = [];
    } else {
        $search_title = '@' . htmlspecialchars($username) . "'nin Anketleri";
        $pdo = db_connect();
        $count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM polls WHERE user_id = ?");
        $count_stmt->execute([$target_user['id']]);
        $total_count = $count_stmt->fetch()['total'];
        $total_pages = ceil($total_count / $items_per_page);

        $stmt = $pdo->prepare("SELECT pol.*, p.content as post_content, p.id as post_id, gp.content as gp_content, gp.id as group_post_id, g.slug as group_slug
            FROM polls pol
            LEFT JOIN posts p ON pol.post_id = p.id
            LEFT JOIN group_posts gp ON pol.group_post_id = gp.id
            LEFT JOIN groups_table g ON gp.group_id = g.id
            WHERE pol.user_id = ?
            ORDER BY pol.created_at DESC
            LIMIT ? OFFSET ?");
        $stmt->execute([$target_user['id'], $items_per_page, $offset]);
        $search_polls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($tab === 'groups' && $query) {
    // Groups search
    $search_title = 'Gruplar: "' . htmlspecialchars($query) . '"';
    $stmt = query(
        "SELECT 
            g.id, g.name, g.slug, g.description,
            (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) as member_count,
            (SELECT COUNT(*) FROM group_posts gp WHERE gp.group_id = g.id) as post_count
         FROM groups_table g
         WHERE g.name LIKE ? OR g.description LIKE ?
         ORDER BY member_count DESC, g.name ASC
         LIMIT 50",
        ['%' . $query . '%', '%' . $query . '%']
    );
    $group_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($query) {
    // Text search
    $search_title = 'Gönderiler: "' . htmlspecialchars($query) . '"';
    $stmt = query(
        "SELECT p.*, u.username, u.is_premium,
                COUNT(DISTINCT l.id) as like_count,
                COUNT(DISTINCT r.id) as comment_count,
                CASE WHEN ul.post_id IS NOT NULL THEN 1 ELSE 0 END as user_has_liked
         FROM posts p
         JOIN users u ON p.user_id = u.id
         LEFT JOIN likes l ON p.id = l.post_id
         LEFT JOIN posts r ON p.id = r.parent_id AND r.deleted_at IS NULL
         LEFT JOIN likes ul ON p.id = ul.post_id AND ul.user_id = ?
         WHERE p.deleted_at IS NULL 
           AND p.parent_id IS NULL
           AND (p.content LIKE ?)
         GROUP BY p.id
         ORDER BY p.created_at DESC
         LIMIT 50",
        [$user_id ?: 0, '%' . $query . '%']
    );
    $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$csrf_token = generate_csrf_token();
?>

<div class="main-container">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= BASE_PATH ?>/index.php">← Ana Sayfa</a></li>
                <li><a href="<?= search_url() ?>">🔍 Arama</a></li>
            </ul>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-title">Popüler Etiketler</div>
            <div class="tag-cloud">
                <?php $top_tags = get_top_tags(12); ?>
                <?php if (empty($top_tags)): ?>
                    <div class="muted small">Henüz etiket verisi yok.</div>
                <?php else: ?>
                    <?php foreach ($top_tags as $t): ?>
                        <a href="<?= search_url() ?>?tab=tags&q=<?= urlencode($t['tag']) ?>" class="post-tag tag-inline">#<?= htmlspecialchars($t['tag']) ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </aside>

    <main class="content-area">
        <h1 class="section-title">🔍 Arama</h1>

          <!-- Tabs (no JS) -->
          <div class="tabs">
            <?php 
                $base = search_url();
                $qparam = $query ? ('&q=' . urlencode($query)) : '';
            ?>
                <a href="<?= $base ?>?tab=posts<?= $qparam ?>" 
                    class="tab <?= $tab==='posts' && $view!== 'newest_users' ? 'tab-active' : '' ?>">
                Gönderiler
            </a>
                <a href="<?= $base ?>?tab=tags<?= $qparam ?>" 
                    class="tab <?= $tab==='tags' ? 'tab-active' : '' ?>">
                Etiketler
            </a>
                <a href="<?= $base ?>?tab=users<?= $qparam ?>" 
                    class="tab <?= ($tab==='users' || $view==='newest_users') ? 'tab-active' : '' ?>">
                Kullanıcılar
            </a>
                <a href="<?= $base ?>?tab=groups<?= $qparam ?>" 
                    class="tab <?= $tab==='groups' ? 'tab-active' : '' ?>">
                Gruplar
            </a>
        </div>
        
        <!-- Search Form -->
        <div class="post-form-container mb-20">
            <form method="GET" action="<?= search_url() ?>" class="form-padded">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                <input 
                    type="text" 
                    name="q" 
                    placeholder="<?= $tab==='tags' ? 'Etiket ara (# ile veya # olmadan)' : ($tab==='users' ? 'Kullanıcı ara' : ($tab==='groups' ? 'Grup ara' : 'Gönderi ara')) ?>" 
                    value="<?= htmlspecialchars($query) ?>"
                    class="input-full"
                >
                <button type="submit" class="btn-post">Ara</button>
            </form>
        </div>

        <?php if ($search_title): ?>
            <h2 class="results-header">
                <?php if ($view === 'newest_users'): ?>
                    <?= $search_title ?> (<?= count($user_results) ?> kullanıcı)
                <?php elseif ($tab === 'users'): ?>
                    <?= $search_title ?> (<?= count($user_results) ?> kullanıcı)
                <?php elseif ($tab === 'groups'): ?>
                    <?= $search_title ?> (<?= count($group_results) ?> grup)
                <?php else: ?>
                    Arama Sonuçları: <?= $search_title ?> (<?= count($search_results) ?> sonuç)
                <?php endif; ?>
            </h2>
        <?php endif; ?>

        <!-- Search Results -->
        <div class="posts-feed">
            <?php if ($view === 'newest_users' || $view === 'active_users' || $tab === 'users'): ?>
                <?php if (empty($user_results)): ?>
                    <div class="empty-state">
                        <p>Henüz kullanıcı bulunamadı.</p>
                    </div>
                <?php else: ?>
                    <div style="padding:10px 15px;">
                        <ul class="sidebar-list">
                            <?php foreach ($user_results as $u): ?>
                                <li>
                                    <div class="user-item">
                                        <a href="<?= profile_url($u['username']) ?>">@<?= htmlspecialchars($u['username']) ?></a>
                                        <?php if ($view === 'active_users'): ?>
                                            <span class="online-status" style="margin-left:auto;">
                                                <span class="status-dot <?= (!empty($u['is_online'])) ? 'online' : 'offline' ?>"></span>
                                            </span>
                                            <span class="count-badge" style="margin-left:4px;"><?= $u['post_count'] ?></span>
                                        <?php else: ?>
                                            <span class="text-muted" style="margin-left:auto;font-size:10px;"><?= date('d.m.Y', strtotime($u['created_at'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <!-- Pagination for Active Users -->
                    <?php if ($view === 'active_users' && $total_pages > 1): ?>
                    <div style="padding:15px;text-align:center;border-top:1px solid #eee;">
                        <div style="display:flex;gap:5px;justify-content:center;flex-wrap:wrap;margin-bottom:10px;">
                            <?php if ($page > 1): ?>
                                <a href="?view=active_users&page=1" class="pagination-link" style="padding:6px 10px;border:1px solid #ddd;border-radius:3px;text-decoration:none;color:#666;">« İlk</a>
                                <a href="?view=active_users&page=<?= $page - 1 ?>" class="pagination-link" style="padding:6px 10px;border:1px solid #ddd;border-radius:3px;text-decoration:none;color:#666;">‹ Önceki</a>
                            <?php endif; ?>
                            
                            <?php
                            // Show page numbers
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if ($start_page > 1) echo '<span style="padding:6px 10px;">...</span>';
                            
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                if ($i == $page) {
                                    echo '<span style="padding:6px 10px;background:#5a9a3c;color:#fff;border-radius:3px;font-weight:bold;">' . $i . '</span>';
                                } else {
                                    echo '<a href="?view=active_users&page=' . $i . '" style="padding:6px 10px;border:1px solid #ddd;border-radius:3px;text-decoration:none;color:#666;">' . $i . '</a>';
                                }
                            }
                            
                            if ($end_page < $total_pages) echo '<span style="padding:6px 10px;">...</span>';
                            ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?view=active_users&page=<?= $page + 1 ?>" class="pagination-link" style="padding:6px 10px;border:1px solid #ddd;border-radius:3px;text-decoration:none;color:#666;">Sonraki ›</a>
                                <a href="?view=active_users&page=<?= $total_pages ?>" class="pagination-link" style="padding:6px 10px;border:1px solid #ddd;border-radius:3px;text-decoration:none;color:#666;">Son »</a>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:12px;color:#999;">
                            Sayfa <?= $page ?> / <?= $total_pages ?> (Toplam: <?= $total_count ?? 0 ?> üye)
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php elseif ($view === 'user_polls'): ?>
                <?php if (empty($search_polls)): ?>
                    <div class="empty-state">
                        <p>Bu kullanıcıya ait anket bulunamadı.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($search_polls as $pol): ?>
                        <div class="card-box padded" style="margin-bottom:12px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <div style="font-weight:600;">
                                    <?= htmlspecialchars($pol['title'] ?: ($pol['post_content'] ?? $pol['gp_content'] ?? 'Anket')) ?>
                                </div>
                                <div class="muted small">
                                    <?= format_time($pol['created_at']) ?>
                                </div>
                            </div>
                            <div style="margin-top:8px;">
                                <?php
                                    $opts = $pdo->prepare("SELECT id, text, votes_count FROM poll_options WHERE poll_id = ? ORDER BY id ASC");
                                    $opts->execute([$pol['id']]);
                                    $opt_rows = $opts->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($opt_rows as $o) {
                                        echo '<div style="padding:6px 0;">' . htmlspecialchars($o['text']) . ' <span class="muted">(' . (int)$o['votes_count'] . ')</span></div>';
                                    }
                                ?>
                            </div>
                            <div style="margin-top:8px;">
                                <?php if (!empty($pol['post_id'])): ?>
                                    <a href="<?= htmlspecialchars(get_post_url($pol['post_id'], $profile_user['username'])) ?>" class="btn-outline">Gönderiyi Gör</a>
                                <?php elseif (!empty($pol['group_post_id'])): ?>
                                    <a href="<?= htmlspecialchars(group_post_url($pol['group_slug'] ?? '', $pol['group_post_id'])) ?>" class="btn-outline">Grup Gönderisini Gör</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php elseif ($tab === 'groups'): ?>
                <?php if (empty($group_results) && $query): ?>
                    <div class="empty-state">
                        <p>Aramanızla eşleşen grup bulunamadı.</p>
                        <a href="<?= search_url() ?>?tab=groups" style="color:#5a9a3c;">← Aramayı temizle</a>
                    </div>
                <?php elseif (empty($group_results)): ?>
                    <div class="empty-state">
                        <p>Grup aramak için yukarıdaki formu kullanın.</p>
                    </div>
                <?php else: ?>
                    <div style="padding:10px 15px;">
                        <ul class="sidebar-list">
                            <?php foreach ($group_results as $g): ?>
                                <li>
                                    <a href="<?= group_url($g['slug']) ?>">
                                        <span style="font-weight:600;"><?= htmlspecialchars($g['name']) ?></span>
                                        <span class="text-muted" style="margin-left:6px;font-size:11px;">(<?= (int)$g['member_count'] ?> üye · <?= (int)$g['post_count'] ?> gönderi)</span>
                                    </a>
                                    <?php if (!empty($g['description'])): ?>
                                        <div style="font-size:11px;color:#666;margin-top:3px;">
                                            <?= htmlspecialchars(mb_strimwidth($g['description'], 0, 80, '…')) ?>
                                        </div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php if (empty($search_results) && ($tag || $query)): ?>
                    <div class="empty-state">
                        <p>Aramanızla eşleşen gönderi bulunamadı.</p>
                        <a href="<?= search_url() ?>" style="color:#5a9a3c;">← Aramayı temizle</a>
                    </div>
                <?php elseif (empty($search_results)): ?>
                    <div class="empty-state">
                        <p>Arama yapmak için yukarıdaki formu kullanın veya etiketlere tıklayın.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($search_results as $post): ?>
                        <?php 
                        $current_user_id = $user_id;
                        require __DIR__ . '/templates/post-card.php'; 
                        ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">💡 Arama İpuçları</div>
            <div style="padding:10px;font-size:12px;color:#666;line-height:1.6;">
                <p><strong>Etiket Araması:</strong></p>
                <p style="margin:5px 0 10px;">Gönderilerdeki etiketlere tıklayın veya #etiket formatında arayın</p>
                
                <p><strong>Metin Araması:</strong></p>
                <p style="margin:5px 0;">Gönderi içeriği veya kullanıcı adı arayın</p>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
