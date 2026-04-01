<?php

// early routing for common paths so we never include the global header
// when we're simply trying to render index/landing/register/logout etc.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
// NB: the htaccess catch‑all sometimes rewrites even real files to
// profile.php; handle those cases before pulling in header.php.
if ($path === '/anasayfa' || $path === '/anasayfa/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    exit;
}
if ($path === '/landing' || $path === '/landing.php') {
    require __DIR__ . '/landing.php';
    exit;
}
if ($path === '/kayit' || $path === '/kayit/' || $path === '/kayit.php' || $path === '/register.php') {
    // register page must be served directly so that its own header.php
    // include runs exactly once (avoid double headers and rewrites loops).
    require __DIR__ . '/register.php';
    exit;
}
if (strpos($path, '/captcha_image.php') === 0) {
    // image generation must bypass header completely
    require __DIR__ . '/captcha_image.php';
    exit;
}
if ($path === '/cikis' || $path === '/cikis/' || $path === '/logout' ||
    $path === '/logout/' || $path === '/logout.php') {
    require __DIR__ . '/logout.php';
    exit;
}

// Load core helpers early as this file performs slug lookups before the normal header include.
require_once __DIR__ . '/includes/functions.php';

// Handle reserved clean‑URL keywords that may still arrive as a
// username parameter (e.g. /giris, /kayit etc).  Perform this
// redirect before header output so PHP's Location header works more
// reliably and we avoid spurious buffering.
$username = isset($_GET['username']) ? trim(rawurldecode($_GET['username'])) : '';
$slug = isset($_GET['slug']) ? trim(rawurldecode($_GET['slug'])) : '';

// If slug is provided, use slug lookup as canonical for user pages.
if ($slug !== '') {
    $profile_user = get_user_by_slug($slug);
    if ($profile_user) {
        // Keep this page as a valid slug-based profile but prefer canonical URL in header.
        // Do not redirect to avoid possible loops and 500 on complex names.
        $username = $profile_user['username'];
    }
}

// If this request came in from clean URL path, try slug/username resolution.
$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (!empty($requestedPath) && strpos($requestedPath, '.php') === false && preg_match('#^/([^/]+)$#', $requestedPath, $pm) && !empty($pm[1])) {
    $pathUsername = rawurldecode($pm[1]);

    // Avoid reserved path names (index, login, etc.) here. Those are handled later.
    if ($pathUsername !== '' && $pathUsername !== $username) {
        // If path segment is not URL-safe, redirect to query parameter version.
        if (function_exists('is_username_clean_url_safe') && !is_username_clean_url_safe($pathUsername)) {
            header('Location: ' . BASE_PATH . '/profile.php?username=' . rawurlencode($pathUsername), true, 301);
            exit;
        }

        // First, try slug lookup for clean-path-friendly values (including hyphens).
        $bySlug = get_user_by_slug($pathUsername);
        if ($bySlug) {
            $profile_user = $bySlug;
            $username = $bySlug['username'];
        } else {
            // Fallback to username lookup for clean values.
            $username = $pathUsername;
        }
    }
}

$reserved_paths = [
    'anasayfa'      => '/index.php',
    'index.php'     => '/index.php',
    'giris'         => '/login.php',
    'giris.php'     => '/login.php',
    'kayit'         => '/kayit',
    'kayit.php'     => '/kayit',
    'bildirimler'   => '/notification.php',
    'premium'       => '/premium.php',
    'etkinlikler'   => '/events.php',
    'ara'           => '/search.php',
    'kurallar'      => '/kurallar-sartlar',
    'kurallar-sartlar' => '/kurallar-sartlar',
    'topluluklar'   => '/groups.php',
    'davet-et'      => '/invite.php',
    'landing'       => '/landing.php',
    'landing.php'   => '/landing.php',
    'cikis'         => '/logout.php',
    'logout'        => '/logout.php',
    'logout.php'    => '/logout.php',
    'gizlilik'      => '/gizlilik',
    'kvkk'          => '/kvkk',
    'cerezler'      => '/cerezler',
    'kurallar-sartlar' => '/kurallar-sartlar',
];
if ($username !== '' && isset($reserved_paths[$username])) {
    header('Location: ' . BASE_PATH . $reserved_paths[$username]);
    exit;
}

// At this point nothing special needs to run, load the standard header.
require_once __DIR__ . '/includes/header.php';

// the rest of the original file follows unchanged

// after header has been loaded we still log some useful values
error_log('profile.php: header included; REQUEST_URI=' . ($_SERVER['REQUEST_URI'] ?? '') );

$current_user_id = get_current_user_id();
error_log('profile.php: current_user_id=' . intval($current_user_id));
$profile_user = null;
$errors = [];

// Get username from URL (used later for profile lookup)
$username = isset($_GET['username']) ? trim(rawurldecode($_GET['username'])) : '';
error_log('profile.php: username from URL="' . ($username ?? '') . '"');

// debug display of username to help diagnose routing problems
if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production') {
    echo "<!-- debug username='" . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . "' -->\n";
}

// If no username, try slug; otherwise use current user.
if (empty($username) && $slug !== '') {
    $profile_user = get_user_by_slug($slug);
} elseif (empty($username)) {
    if ($current_user_id) {
        $profile_user = get_user($current_user_id);
    } else {
        header('Location: ' . BASE_PATH . '/login.php');
        exit;
    }
} else {
    $profile_user = get_user_by_username($username);
}
error_log('profile.php: fetched profile_user=' . ($profile_user ? ('id=' . intval($profile_user['id']) . ', username=' . ($profile_user['username'] ?? '')) : 'NULL'));

if (!$profile_user) {
    ?>
    <div class="main-container">
        <div class="content-wrapper">
            <h1 class="section-title">Kullanici Bulunamadi</h1>
            <div class="empty-state">
                <p>Aradiginiz kullanici mevcut degil veya silinmis.</p>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Check if account is disabled (only hide from others, not from owner)
$is_own_profile_check = ($current_user_id == $profile_user['id']);
if (!$is_own_profile_check && isset($profile_user['is_active']) && $profile_user['is_active'] == 0) {
    ?>
    <div class="main-container">
        <div class="content-wrapper">
            <h1 class="section-title">Profil Mevcut Değil</h1>
            <div class="empty-state">
                <p>Bu kullanıcı hesabını devre dışı bırakmış.</p>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$profile_user_id = $profile_user['id'];
$is_own_profile = ($current_user_id == $profile_user_id);
// Defensive initialization to avoid undefined variable warnings in some flows
$is_suspended = false;

// Handle follow/unfollow and post creation
$skip_create = false;
if ($current_user_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Insert tag helper (no-JS buttons submit here)
    if (isset($_POST['insert_tag'])) {
        $draft = $_POST['content'] ?? get_draft($current_user_id);
        $term = trim($_POST['insert_tag'] ?? 'etiket');
        $tagtext = '#' . ltrim($term, '#');
        $draft = insert_tag_into_text($draft, $tagtext);
        save_draft($current_user_id, $draft);
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    // Insert type helper (Link / Ekstra / Kod)
    if (isset($_POST['insert_type'])) {
        insert_type_or_append_to_draft($current_user_id, $_POST['insert_type'], $_POST);
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    // Preview request
    if (isset($_POST['action']) && $_POST['action'] === 'preview') {
        $preview_content = $_POST['content'] ?? get_draft($current_user_id);
        if (trim($preview_content) === '') {
            $preview_error = 'Önizlemek için içerik gerekli.';
        } else {
            $preview_html = render_rich_text($preview_content);
        }
        save_draft($current_user_id, $preview_content);
        $skip_create = true;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'follow') {
        toggle_follow($current_user_id, $profile_user_id);
    } elseif (!$skip_create && isset($_POST['action']) && $_POST['action'] === 'create_post') {
        // Post creation from profile page
        $content = $_POST['content'] ?? '';
        if (trim($content) === '') {
            $content = get_draft($current_user_id);
        }
        if (empty($content)) {
            $errors[] = 'Gönderi içeriği boş olamaz.';
        } else {
            $res = create_post($current_user_id, $content);
            if (isset($res['error'])) {
                if ($res['error'] === 'suspended') {
                    $errors[] = 'Hesabınız geçici olarak yasaklıdır (süresince: ' . htmlspecialchars($res['until']) . ').';
                } elseif ($res['error'] === 'limit_exceeded') {
                    $errors[] = $res['message'];
                } else {
                    $errors[] = 'Gönderi oluşturulamadı.';
                }
            } elseif (isset($res['id'])) {
                if ($res['has_bad_words']) {
                    $_SESSION['flash'] = t('post_badwords_censored');
                } elseif ($res['approved']) {
                    $_SESSION['flash'] = 'Gönderiniz paylaşıldı.';
                } else {
                    $_SESSION['flash'] = 'Gönderiniz onay bekliyor; onaylandıktan sonra görünür.';
                }
                // Clear draft on successful creation
                save_draft($current_user_id, '');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            } else {
                $errors[] = 'Gönderi oluşturulamadı.';
            }
        }
    }

    // Refresh to avoid form resubmission when nothing else redirected
    if (!$skip_create) {
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Get profile data
$is_following = $current_user_id ? is_following($current_user_id, $profile_user_id) : false;
$followers_count = get_followers_count($profile_user_id);
$following_count = get_following_count($profile_user_id);
$posts = get_user_posts($profile_user_id, 50, $current_user_id);
$group_posts = get_user_group_posts($profile_user_id, 50, $current_user_id);

// Count polls the user has created
try {
    // Ensure we have a DB connection (defensive: some flows may not have $pdo in scope)
    $pdo = $pdo ?? db_connect();
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM polls WHERE user_id = ?");
    $stmt->execute([$profile_user_id]);
    $polls_count = (int)($stmt->fetch()['c'] ?? 0);
} catch (Exception $e) {
    // If polls table does not exist yet, treat as zero and log for debugging
    error_log('profile polls count error: ' . $e->getMessage());
    $polls_count = 0;
}

// Mark posts and group posts with type for template rendering
foreach ($posts as &$p) {
    $p['_type'] = 'post';
}
foreach ($group_posts as &$gp) {
    $gp['_type'] = 'group_post';
}

// Pull recent event comments by this profile to include in the merged timeline
$event_comments_for_profile = [];
try {
    $ecStmt = $pdo->prepare("SELECT c.*, u.username, u.is_premium, e.title AS event_title, (SELECT COUNT(*) FROM events_comments ec WHERE ec.parent_id = c.id) AS replies_count FROM events_comments c JOIN users u ON c.user_id = u.id JOIN events e ON c.event_id = e.id WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT 20");
    $ecStmt->execute([$profile_user_id]);
    $ecs = $ecStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ecs as $c) {
        // Ensure username exists for template rendering
        if (empty($c['username'])) $c['username'] = $profile_user['username'];
        $c['_type'] = 'event_comment';
        $event_comments_for_profile[] = $c;
    }
} catch (Exception $e) {
    // ignore — table may not exist yet
}

// Merge and sort by created_at descending
$all_posts = array_merge($posts, $group_posts, $event_comments_for_profile);
usort($all_posts, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

$profile_view_username = $profile_user['username'];
$badges = get_user_badges($profile_user_id);
?>

<div class="main-container">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Profil Bilgileri</div>
            <div class="sidebar-meta">
                <p class="meta-row"><strong>Kullanıcı:</strong> @<?= htmlspecialchars($profile_user['username']) ?></p>
                <?php if (!empty($profile_user['is_premium']) && ($is_own_profile || is_admin())): ?>
                    <p class="meta-row"><strong>Etkinlik Kodu:</strong> <code><?= htmlspecialchars($profile_user['event_code'] ?? get_or_create_event_code($profile_user['id'])) ?></code></p>
                <?php endif; ?>
                <p class="meta-row"><strong>Gönderi:</strong> <?= count($all_posts) ?> <span class="muted">· Anket: <?= $polls_count ?></span></p>
                <p class="meta-row">Kuyrukta <?= $followers_count ?></p>
                <p>Kuyruktaki <?= $following_count ?></p>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="content-area form-centered">
        <!-- Profile Header -->
        <section class="profile-header">
            <div class="profile-avatar-wrapper">
                <!-- Avatar column only; edit button moved under username for better visual flow -->
            </div>
            
            <div class="profile-info">
                <div class="profile-username-wrap">
                    <h1 class="profile-username">@<?= htmlspecialchars($profile_user['username']) ?>
                        <?php if (is_user_premium($profile_user_id)): ?>
                            <span class="premium-star">⭐</span>
                            
                            <?php
                            // Custom badge for premium users
                            $custom_badge = get_user_custom_badge($profile_user_id);
                            if ($custom_badge) {
                                $color_map = [
                                    '#2ecc71' => 'green',
                                    '#3498db' => 'blue',
                                    '#e74c3c' => 'red',
                                    '#f39c12' => 'orange',
                                    '#9b59b6' => 'purple',
                                    '#1abc9c' => 'turquoise',
                                    '#34495e' => 'darkgray',
                                    '#e67e22' => 'orangered'
                                ];
                                // Normalize color value (allow values with or without # and mixed case)
                                $badge_color_raw = trim(strtolower($custom_badge['badge_color'] ?? ''));
                                if ($badge_color_raw !== '' && $badge_color_raw[0] !== '#') $badge_color_raw = '#' . $badge_color_raw;
                                $color_class = $color_map[$badge_color_raw] ?? 'green';
                                echo '<span class="custom-badge custom-badge-' . $color_class . '">' . htmlspecialchars($custom_badge['badge_text']) . '</span>';
                            }
                            ?>
                        <?php endif; ?>
                        
                        <?php if (!empty($badges)): ?>
                            <?php foreach ($badges as $b): ?>
                                <span class="badge profile-badge"><?= htmlspecialchars($b['name']) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Per-user RSS feed -->
                        <a class="profile-rss-link btn-compact btn-outline" href="<?= BASE_PATH ?>/user/<?= rawurlencode($profile_user['username']) ?>/rss.xml" rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($profile_user['username'] . ' — RSS', ENT_QUOTES, 'UTF-8') ?>">RSS</a>
                        
                        <!-- Online Status Indicator -->
                        <span class="online-status">
                            <span class="status-dot <?= (!empty($profile_user['is_online'])) ? 'online' : 'offline' ?>"></span>
                            <span class="small muted"><?= (!empty($profile_user['is_online'])) ? 'Çevrimiçi' : 'Çevrimdışı' ?></span>
                        </span>
                    </h1>
                    <?php if ($is_own_profile): ?>
                        <div class="edit-profile-undername">
                            <a href="<?= BASE_PATH ?>/profile_edit.php" class="edit-profile-btn"><?= t('profile_edit_btn') ?></a>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Action buttons next to username -->
                    <div class="profile-action-buttons">
                        <?php if (!$is_own_profile && $current_user_id): ?>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="follow">
                                <button type="submit" class="follow-btn-compact <?= $is_following ? 'following' : '' ?>">
                                    <?= $is_following ? 'kuyruğu bırak' : 'kuyruk' ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php $cur = get_user($current_user_id); ?>
                        <?php $is_suspended = !empty($profile_user['suspended_until']) && strtotime($profile_user['suspended_until']) > time(); ?>
                        <?php if ($cur && $cur['role'] === 'admin' && $profile_user_id != $cur['id']): ?>
                            <?php if (!$is_suspended): ?>
                                <form method="GET" action="<?= BASE_PATH ?>/profile_ban.php" class="inline-form">
                                    <input type="hidden" name="username" value="<?= htmlspecialchars($profile_user['username']) ?>">
                                    <button type="submit" class="btn-compact btn-warning-compact"><?= t('profile_ban_btn') ?></button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="<?= BASE_PATH ?>/api/admin_unsuspend_user.php" class="inline-form">
                                    <input type="hidden" name="user_id" value="<?= $profile_user_id ?>">
                                    <button type="submit" class="btn-compact">Kaldır</button>
                                </form>
                                <span class="suspended-badge-compact">Suspended until <?= htmlspecialchars($profile_user['suspended_until']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (!empty($profile_user['bio'])): ?>
                    <p class="profile-bio"><?= nl2br(htmlspecialchars($profile_user['bio'])) ?></p>
                <?php endif; ?>
                
                <p class="profile-joined">
                    Katilim: <?= date('d.m.Y', strtotime($profile_user['created_at'])) ?>
                </p>
                
                <div class="profile-stats">
                    <a href="<?= BASE_PATH ?>/search.php?view=user_posts&username=<?= rawurlencode($profile_user['username']) ?>" class="stat">
                        <span class="stat-value"><?= count($all_posts) ?></span>
                        <span class="stat-label">Gonderi</span>
                    </a>
                    <a href="<?= BASE_PATH ?>/search.php?view=user_polls&username=<?= rawurlencode($profile_user['username']) ?>" class="stat">
                        <span class="stat-value"><?= $polls_count ?></span>
                        <span class="stat-label">Anket</span>
                    </a>
                    <a href="<?= followers_url($profile_user['username']) ?>" class="stat">
                        <span class="stat-value"><?= $followers_count ?></span>
                        <span class="stat-label">Kuyrukta</span>
                    </a>
                    <a href="<?= following_url($profile_user['username']) ?>" class="stat">
                        <span class="stat-value"><?= $following_count ?></span>
                        <span class="stat-label">Kuyruktaki</span>
                    </a>
                </div>
            </div>
            
            <div class="profile-actions">
                <?php if ($is_own_profile): ?>
                    <!-- Post Creation Form on Profile (allow long posts and auto-splitting like index) -->
                    <div class="post-form-container">
                        <?php $trending_tags = get_trending_tags(8); // show small set near the composer ?>
                        <?php if (!empty($trending_tags)): ?>
                            <div class="tag-navigation" style="margin-bottom:10px;">
                                <span class="tag-label">Revaçtaki Mevzuatlar:</span>
                                <?php foreach ($trending_tags as $tag):
                                    $tag_clean = ltrim($tag['tag'], '#');
                                ?>

                                    <span class="tag-item">
                                        <?php if ($current_user_id): ?>
                                            <button type="submit" class="insert-tag" form="composer-form" name="insert_tag" value="<?= htmlspecialchars($tag_clean) ?>" aria-label="Insert #<?= htmlspecialchars($tag_clean) ?>">+</button>
                                        <?php endif; ?>
                                        <a href="<?= BASE_PATH ?>/ara?tag=<?= urlencode($tag_clean) ?>" class="tag-nav-item" data-tag="<?= htmlspecialchars($tag_clean) ?>">
                                            <?= htmlspecialchars($tag_clean) ?>
                                        </a>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- insert-helpers removed: helper mini-forms were intentionally removed to simplify composer UI -->

                        <form method="POST" class="post-form" id="composer-form">
                            <div class="post-toolbar" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
                                <div style="display:flex;gap:6px;">
                                    <button type="submit" name="insert_type" value="tag" class="btn-small">#</button>
                                    <button type="submit" name="insert_type" value="link" class="btn-small">Link</button>
                                    <button type="submit" name="insert_type" value="spoiler" class="btn-small">Ekstra</button>
                                    <button type="submit" name="insert_type" value="kod" class="btn-small">Kod</button>
                                    <?php if (!is_user_creation_restricted($current_user_id)): ?>
                                        <a href="<?= BASE_PATH ?>/poll.php?action=new" class="btn-small">Anket</a>
                                        <a href="<?= BASE_PATH ?>/tahlil/olustur" class="btn-small">Tahlil</a>
                                    <?php else: ?>
                                        <span class="btn-small muted" title="Yeni hesaplar için devre dışı">Anket</span>
                                        <span class="btn-small muted" title="Yeni hesaplar için devre dışı">Tahlil</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php $prefill = !$is_own_profile ? '@' . htmlspecialchars($profile_user['username']) . ' ' : ''; ?>
                            <textarea name="content" placeholder="bugün ne düşünüyorsun?" required><?= sanitize_input($_POST['content'] ?? get_draft($current_user_id)) ?></textarea>

                            <?php if (!empty($preview_html)): ?>
                                <div class="post-preview" style="border:1px solid #eee;padding:12px;margin-top:8px;background:#fafafa;"><?= $preview_html ?></div>
                            <?php endif; ?>

                            <?php if (!empty($show_upgrade_cta)): ?>
                                <div class="upgrade-cta"><?= $errors ? implode('<br>', array_map('htmlspecialchars', $errors)) : '' ?></div>
                            <?php endif; ?>
                            <div class="post-form-actions">
                                <?php if (get_user_post_limit($current_user_id) == 0): ?>
                                    <span class="char-count">Sınırsız (Premium)</span>
                                <?php else: ?>
                                    <span class="char-count">En fazla <?= MAX_POST_LENGTH ?> karakter</span>
                                <?php endif; ?>
                                <div class="post-actions-buttons">
                                    <button type="submit" name="action" value="preview" class="btn-outline">Önizleme</button>
                                    <button type="submit" name="action" value="create_post" class="btn-post">Paylaş</button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Controls moved next to username for compact layout -->
            </div>
        </section>
        
        <!-- User's Posts and Group Posts (merged by timestamp) -->
        <section class="profile-posts">
            <h2 class="section-title"><?= $is_own_profile ? 'Gonderilerim' : '@' . htmlspecialchars($profile_user['username']) . '\'nin Gonderileri' ?></h2>
            
            <?php if (empty($all_posts)): ?>
                <div class="empty-state">
                    <p><?= $is_own_profile ? 'Henuz hic gonderi paylasmadin.' : 'Bu kullanici henuz gonderi paylasmamis.' ?></p>
                </div>
            <?php else: ?>
                <div class="posts-feed">
                <?php foreach ($all_posts as $item): ?>
                    <?php if ($item['_type'] === 'post'): ?>
                        <?php $post = $item; ?>
                        <?php require __DIR__ . '/templates/post-card.php'; ?>
                    <?php elseif (!empty($item['_type']) && $item['_type'] === 'group_post'): ?>
                        <?php $gp = $item; ?>
                        <?php require __DIR__ . '/templates/group-post-card.php'; ?>
                    <?php elseif (!empty($item['_type']) && $item['_type'] === 'event_comment'): ?>
                        <?php $ec = $item; $item = ['data' => $ec]; require __DIR__ . '/templates/event-comment-post-card.php'; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        
        <!-- User's Group Posts -->
        <?php if (!empty($group_posts)): ?>
        <section class="profile-posts">
            <h2 class="section-title"><?= $is_own_profile ? 'Grup Gonderilerim' : '@' . htmlspecialchars($profile_user['username']) . '\'nin Grup Gonderileri' ?></h2>
            <div class="posts-feed">
                <?php foreach ($group_posts as $gp): ?>
                    <?php require __DIR__ . '/templates/group-post-card.php'; ?>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">Hakkında</div>
            <div style="padding:10px;font-size:11px;color:#666;">
                <?php if (!empty($profile_user['bio'])): ?>
                    <p style="margin-bottom:8px;"><?= nl2br(htmlspecialchars($profile_user['bio'])) ?></p>
                <?php endif; ?>
                <p style="color:#999;font-size:10px;">Kayıt: <?= date('d.m.Y', strtotime($profile_user['created_at'])) ?></p>
            </div>
        </div>
    </aside>
</div>

<?php error_log('profile.php: reached footer for profile_user_id=' . intval($profile_user_id)); require_once __DIR__ . '/includes/footer.php'; ?>

