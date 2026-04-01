<?php /* EN + TR comments used. */
/**
 * Main Index Page - Sosyomat Style
 *
 * Bu dosya site anasayfasıdır. İngilizce ve Türkçe yorumlar kullanımda.
 * (English + Türkçe comments are used throughout the codebase.)
 */
require_once __DIR__ . '/includes/header.php';

// Normalize URL: /index.php should redirect to clean root URL (preserve query string).
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$scriptPath = rtrim(BASE_PATH, '/');
if ($scriptPath === '') {
    $indexPathCandidates = ['/index.php', '/index'];
    $rootPath = '/';
} else {
    $indexPathCandidates = [$scriptPath . '/index.php', $scriptPath . '/index'];
    $rootPath = $scriptPath . '/';
}

if (in_array($reqPath, $indexPathCandidates, true)) {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    $location = $rootPath . ($q ? ('?' . $q) : '');
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $location);
    exit;
}

// Guest visitors see the landing page directly (URL stays clean)
$user_id = get_current_user_id();
// If a Turkish friendly compare URL is requested, route to post.php before landing
if (preg_match('#^/post/([0-9]+)/karsilastirma(?:/([0-9]+)|/latest|/son-duzenleme)?/?$#', $reqPath, $m)) {
    $_GET['id'] = $m[1];
    if (isset($m[2]) && $m[2] !== '') {
        $_GET['compare'] = $m[2];
    } else {
        // if path contains 'latest' or no second capture, treat as latest
        if (strpos($reqPath, '/latest') !== false || strpos($reqPath, '/son-duzenleme') !== false) $_GET['compare'] = 'latest';
    }
    require __DIR__ . '/post.php';
    exit;
}

// Turkish history path shim -> route to post.php with compare=history
if (preg_match('#^/post/([0-9]+)/karsilastirma/history/?$#', $reqPath, $hm)) {
    $_GET['id'] = $hm[1];
    $_GET['compare'] = 'history';
    require __DIR__ . '/post.php';
    exit;
}

if (!$user_id) {
    // Discard any buffered header output so landing.php renders its own full page
    while (ob_get_level()) { ob_end_clean(); }
    require __DIR__ . '/landing.php';
    exit;
}


// Development helper: show session/cookie debug inline when requested

if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production' && isset($_GET['show_session'])) {
    $dbg = [];
    $dbg[] = "Session Debug (dev)";
    $dbg[] = "Request URI: " . ($_SERVER['REQUEST_URI'] ?? '');
    $dbg[] = "Remote Addr: " . ($_SERVER['REMOTE_ADDR'] ?? '');
    $dbg[] = "User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $dbg[] = "\nCookies:";
    $dbg[] = print_r($_COOKIE, true);
    $dbg[] = "\nSession ID: " . session_id();
    $dbg[] = "\n\
_SESSION:";
    $dbg[] = print_r($_SESSION, true);

    echo "<div class='dev-debug dev-debug-warning'>";
    echo "<h3>Session Debug (dev)</h3>";
    echo "<pre class='pre-debug'>" . htmlspecialchars(implode("\n", $dbg)) . "</pre>";
    echo "</div>";
}
// Also show request headers and response headers for debugging
if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production' && isset($_GET['show_session'])) {
    echo "<div class='dev-debug dev-debug-info'>";
    echo "<h3>Request & Response Headers (dev)</h3>";
    echo "<pre class='pre-debug'>Request Headers:\n" . htmlspecialchars(print_r(getallheaders(), true)) . "\n\nResponse Headers:\n" . htmlspecialchars(print_r(headers_list(), true)) . "</pre>";
    echo "</div>";
}

$usr = $user_id ? get_user($user_id) : null;
$invite_count = 0;  // will be populated for logged-in users


// Debug banner for sid — only in non-production environments
if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production' && isset($_GET['sid']) && empty($user_id)) {
    $sid = preg_replace('/[^A-Za-z0-9._-]/', '', $_GET['sid']);
    $masked = substr($sid, 0, 6) . '...' . substr($sid, -6);
    // Compute hash prefix using URL_SESSION_SECRET so we can match to stored token_hash
    $sid_hash = hash_hmac('sha256', $sid, URL_SESSION_SECRET);
    $hash_prefix = substr($sid_hash, 0, 12);
    // Compute UA and IP prefixes for comparison
    $ua_hash = isset($_SERVER['HTTP_USER_AGENT']) ? hash_hmac('sha256', $_SERVER['HTTP_USER_AGENT'], URL_SESSION_SECRET) : '';
    $ua_prefix = $ua_hash ? substr($ua_hash, 0, 8) : '<none>';
    $ip_pref = function_exists('_ip_prefix') ? _ip_prefix() : (substr($_SERVER['REMOTE_ADDR'] ?? '',0,16));

    echo "<div class='dev-debug dev-debug-error'>"; 
    echo "<strong>Debug:</strong> `sid` parameter present but not recognized.<br>";
    echo "Masked sid: " . htmlspecialchars($masked) . "<br>";
    echo "Computed token hash prefix: " . htmlspecialchars($hash_prefix) . "<br>";
    echo "Computed UA hash prefix: " . htmlspecialchars($ua_prefix) . "<br>";
    echo "Computed IP prefix: " . htmlspecialchars($ip_pref) . "<br>";
    // Debug: check if row exists
    try {
        $pdo = db_connect();
        $stmt = $pdo->prepare("SELECT user_id FROM url_sessions WHERE token_hash = ? OR raw_token_hash = ? LIMIT 1");
        $stmt->execute([$sid_hash, hash('sha256', $sid)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo "DB Row found for user_id=" . htmlspecialchars($row['user_id']) . "<br>";
        } else {
            echo "DB Row NOT found<br>";
        }
    } catch (Exception $e) {
        echo "DB check error: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
    echo "</div>";
}
$errors = [];

// Track last time user saw the main feed (session-based)
$last_feed_seen_at = isset($_SESSION['last_feed_seen_at']) ? $_SESSION['last_feed_seen_at'] : null;





if ($user_id && $_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['insert_type']) || isset($_POST['insert_tag']))) {
    $insert_type = $_POST['insert_type'] ?? null;
    // Prefer current textarea content (if present), then fall back to session draft
    $draft = $_POST['content'] ?? get_draft($user_id);

    // Tag insertion (supports both toolbar button and trending-tag buttons)
    if (isset($_POST['insert_tag']) || $insert_type === 'tag') {
        $term = trim($_POST['insert_tag'] ?? $_POST['tag_term'] ?? 'etiket');
        if ($term === '') $term = 'etiket';
        $tagtext = '#' . ltrim($term, '#');

        // Always run the heuristic insertion on the current draft (either posted content or last saved draft)
        $draft = insert_tag_into_text($draft, $tagtext);
    } elseif ($insert_type === 'spoiler') { 
        // Insert an "ekstra" block (spoiler-like) with editable inner text
        $label = 'Ekstra';
        $inner = trim($_POST['spoiler_text'] ?? 'Gizli içerik');
        if ($inner === '') $inner = 'Gizli içerik';
        $draft .= "\n[ekstra=" . $label . "]" . $inner . "[/ekstra]\n";
    } elseif ($insert_type === 'kod') {
        // Append a kod placeholder block; language optional
        $lang = preg_replace('/[^A-Za-z0-9_+-]/', '', $_POST['code_lang'] ?? '');
        $code = $_POST['code_text'] ?? '...kod buraya...';
        $draft .= "\n[kod" . ($lang ? "=" . $lang : '') . "]" . $code . "[/kod]\n";
    } elseif ($insert_type === 'link') {
        // Append a link placeholder so the user can fill the URL/text in-place
        $url = trim($_POST['link_url'] ?? 'https://example.com');
        $text = trim($_POST['link_text'] ?? 'link metni');
        if ($url === '') $url = 'https://example.com';
        $draft .= " [link url=\"" . $url . "\"]" . $text . "[/link] ";
    }

    save_draft($user_id, $draft);
    // Redirect back to composer
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

// Handle preview (buttonized)
$skip_create = false;
if ($user_id && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview') {
    $preview_content = $_POST['content'] ?? get_draft($user_id);
    if (trim($preview_content) === '') {
        $preview_error = 'Önizlemek için içerik gerekli.';
    } else {
        $preview_html = render_rich_text($preview_content);
    }
    // Persist draft so user can continue editing
    save_draft($user_id, $preview_content);
    // Prevent the regular create_post handler from running on this submission
    $skip_create = true;
}

// Handle post creation
if (!$skip_create && $user_id && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_post') {
    $content = $_POST['content'] ?? '';
    // If no content in POST, use draft
    if (trim($content) === '') {
        $content = get_draft($user_id);
    }
    
    if (empty($content)) {
        $errors[] = t('post_empty_error');
    } else {
        $res = create_post($user_id, $content);
        if (isset($res['error'])) {
            if ($res['error'] === 'suspended') {
                $errors[] = t('post_suspended_error', htmlspecialchars($res['until']));
            } elseif ($res['error'] === 'unapproved_limit') {
                $errors[] = $res['message'];
            } elseif ($res['error'] === 'limit_exceeded') {
                $errors[] = $res['message'];
            } else {
                $errors[] = t('post_failed_error');
            }
        } elseif (isset($res['id'])) {
            if ($res['has_bad_words']) {
                $_SESSION['flash'] = t('post_badwords_censored');
            } elseif ($res['approved']) {
                $_SESSION['flash'] = t('post_created_flash');
            } else {
                $_SESSION['flash'] = t('post_pending_flash');
            }
            // Clear draft on successful creation
            save_draft($user_id, '');
            // Prefer clean root URL over index.php to keep links tidy
            $redir = rtrim(BASE_PATH, '/') . '/';
            if ($redir === '') {
                $redir = '/';
            }
            if (!empty($_REQUEST['sid'])) {
                $sid_param = preg_replace('/[^A-Za-z0-9._-]/', '', $_REQUEST['sid']);
                if ($sid_param) {
                    $redir .= '?sid=' . rawurlencode($sid_param);
                }
            }
            header('Location: ' . $redir);
            exit;
        } else {
            $errors[] = t('post_failed_error');
        }
    }
}

// Get timeline posts with relevance algorithm and cursor-based pagination
$after = $_GET['after'] ?? null;
$before = $_GET['before'] ?? null;
$posts_limit = 40;

// Use relevance-based feed for logged-in users, chronological for guests
if ($user_id) {
    $pagination = get_relevant_posts_paginated($user_id, $posts_limit, $after);
} else {
    $pagination = get_posts_paginated($posts_limit, null, $after, $before);
}

$posts = $pagination['posts'];
$has_next = $pagination['has_next'];
$has_prev = isset($pagination['has_prev']) ? $pagination['has_prev'] : false;
$first_id = $pagination['first_id'];
$last_id = $pagination['last_id'];

// Get total post count for pagination display
$total_posts_stmt = $pdo->query("SELECT COUNT(*) as c FROM posts WHERE parent_id IS NULL AND deleted_at IS NULL");
$total_posts_row = $total_posts_stmt->fetch();
$total_posts = $total_posts_row['c'] ?? 0;

// Get new feed items count since last seen for Ana akış badge
$ana_akis_new_count = 0;
if ($user_id && $last_feed_seen_at) {
    try {
        $ana_akis_new_count = get_new_feed_count($user_id, $last_feed_seen_at);
    } catch (Exception $e) {
        error_log("get_new_feed_count error: " . $e->getMessage());
        $ana_akis_new_count = 0;
    }
}

// Get recent group posts for homepage (public; include private for admins)
$privacyFilter = is_admin() ? '' : 'AND COALESCE(g.is_private,0) = 0';
$stmt = $pdo->prepare("
    SELECT 
        gp.*, u.username, g.name AS group_name, g.slug,
        (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id) AS like_count,
        (SELECT COUNT(*) FROM group_post_comments WHERE post_id = gp.id) AS comment_count,
        (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id AND user_id = ?) AS user_has_liked
    FROM group_posts gp
    JOIN groups_table g ON gp.group_id = g.id $privacyFilter
    JOIN users u ON gp.user_id = u.id
    ORDER BY gp.created_at DESC
    LIMIT 10
");
$stmt->execute([$user_id ?: 0]);
$group_feed = $stmt->fetchAll();
foreach ($group_feed as &$gpf) { $gpf['poll'] = get_poll_for_group_post($gpf['id']); }

// Get event updates (premium users only) - defensive if table missing
$event_updates = [];
try {
    if (!empty($user_id) && (is_user_premium($user_id) || is_admin())) {
        $euStmt = $pdo->prepare("SELECT eu.*, e.title AS event_title FROM event_updates eu JOIN events e ON eu.event_id = e.id WHERE e.is_active = 1 ORDER BY eu.created_at DESC LIMIT 5");
        $euStmt->execute();
        $event_updates = $euStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $event_updates = [];
}

// Get data for sidebar widgets
$newest_users = $pdo->query("
    SELECT id, username, created_at 
    FROM users 
    ORDER BY created_at DESC 
    LIMIT 10
")->fetchAll();

// Expose small Event Updates widget (premium only) to the top of the feed — render later in the template using $event_updates

// Online users (true online now)
$online_users_total = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_online = 1")->fetchColumn();
$stmt = $pdo->prepare("SELECT id, username, last_activity FROM users WHERE is_online = 1 ORDER BY last_activity DESC LIMIT 5");
$stmt->execute();
$online_users = $stmt->fetchAll();

// Get announcements
$stmt = $pdo->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5");
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get popular groups
$popular_groups = $pdo->query("
    SELECT 
        g.*,
        COUNT(DISTINCT gm.user_id) as member_count
    FROM groups_table g
    LEFT JOIN group_members gm ON g.id = gm.group_id
    GROUP BY g.id
    ORDER BY member_count DESC
    LIMIT 8
")->fetchAll();

// Get trending tags based on relevancy and context
$trending_tags = get_trending_tags(8, $user_id);

// Get notification counts for left menu
$mentioned_count = 0;
$commented_count = 0;
$liked_count = 0;

if ($user_id) {
    try {
        // Consolidated notification counts (single query instead of 3)
        $notif_stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN type = 'mention' THEN 1 ELSE 0 END) as mention_count,
                SUM(CASE WHEN type IN ('reply', 'comment') THEN 1 ELSE 0 END) as comment_count,
                SUM(CASE WHEN type = 'like' THEN 1 ELSE 0 END) as like_count
            FROM notifications 
            WHERE user_id = ? AND read_at IS NULL AND type IN ('mention', 'reply', 'comment', 'like')
        ");
        $notif_stmt->execute([$user_id]);
        $notif_row = $notif_stmt->fetch(PDO::FETCH_ASSOC);
        $mentioned_count = (int)($notif_row['mention_count'] ?? 0);
        $commented_count = (int)($notif_row['comment_count'] ?? 0);
        $liked_count = (int)($notif_row['like_count'] ?? 0);

        // Count invitations sent so far (to enforce 10-limit in UI)
        $inv_stmt = $pdo->prepare("SELECT COUNT(*) as c FROM user_invitations WHERE invited_by = ?");
        $inv_stmt->execute([$user_id]);
        $invite_count = (int)$inv_stmt->fetch()['c'];
    } catch (Exception $e) {
        error_log("Error getting notification counts: " . $e->getMessage());
        $invite_count = 0;
    }
}
?>

<div class="main-container">
    <!-- Left Navigation Menu -->
    <aside class="sidebar sidebar-left sidebar-card">
        <div class="sidebar-section sidebar-banner">
            <div class="banner-overlay"><span class="badge badge-primary">Pek Yakında</span></div>
            <div class="sidebar-title">🌐 Mesh Network</div>
            <p class="muted small">İnternetsiz bağlan!</p>
            <span class="muted small">Bölgeleri Keşfet »</span>
        </div>

        <?php if ($user_id): ?>
        <!-- Notification Menu - aligned badges -->
        <div class="sidebar-section mb-12">
            <div class="sidebar-section-title">Bildirimler</div>
            <ul class="side-menu no-margin">
                <li>
                    <a href="<?= BASE_PATH ?>/notification.php?filter=mention">👤Bahsedildi</a>
                    <?php if ((int)$mentioned_count > 0): ?>
                    <span class="badge red"><?= (int)$mentioned_count ?></span>
                    <?php endif; ?>
                </li>
                <li>
                    <a href="<?= BASE_PATH ?>/notification.php?filter=comment">💬Yorum</a>
                    <?php if ((int)$commented_count > 0): ?>
                    <span class="badge blue"><?= (int)$commented_count ?></span>
                    <?php endif; ?>
                </li>
                <li>
                    <a href="<?= BASE_PATH ?>/notification.php?filter=like">❤️Beğenildi</a>
                    <?php if ((int)$liked_count > 0): ?>
                    <span class="badge pink"><?= (int)$liked_count ?></span>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
        <?php if ($user_id && !empty($usr['is_approved'])): ?>
        <div class="sidebar-section invite mb-12">
            <div class="sidebar-section-title">Davet Et</div>
            <?php if ($invite_count >= 10): ?>
                <div class="muted">10/10 davet gönderildi.</div>
            <?php else: ?>
                <a href="<?= BASE_PATH ?><?= use_clean_urls() ? '/davet-et' : '/invite.php' ?>" class="btn btn-outline full-width">🚀 <?= (10 - $invite_count) ?> adet davet gönder</a>
                <div class="muted small">Kayıt olurlarsa +1 ay premium</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Active Users Section -->
        <div class="sidebar-section mb-12">
            <div class="sidebar-section-title">Aktif Kullanıcılar (<?= (int)$online_users_total ?>)</div>
            <div class="sidebar-active-inline">
                <?php foreach ($online_users as $user): ?>
                    <a href="<?= user_url($user['username']) ?>" class="sidebar-active-link"><span class="sidebar-dot"></span><?= htmlspecialchars($user['username']) ?></a>
                <?php endforeach; ?>
                <?php if (empty($online_users)): ?>
                    <span class="muted small text-center">Aktif kullanıcı yok</span>
                <?php endif; ?>
            </div>
            <div class="text-right mt-5">
                <a href="<?= BASE_PATH ?>/active_users.php" class="sidebar-link-more">daha fazla »</a>
            </div>
        </div>
        
        <!-- My Groups Section -->
        <div class="sidebar-section mb-12">
            <div class="sidebar-section-title">Topluluklarım</div>
            <ul class="sidebar-list-plain">
                <?php 
                // Get user's groups
                $my_groups_stmt = $pdo->prepare("
                    SELECT g.*, 
                        (SELECT COUNT(*) FROM group_posts gp 
                         WHERE gp.group_id = g.id 
                           AND gp.created_at > (SELECT last_activity FROM users WHERE id = ?)) AS new_post_count
                    FROM groups_table g
                    JOIN group_members gm ON g.id = gm.group_id
                    WHERE gm.user_id = ? AND gm.role IN ('owner', 'moderator', 'member', 'admin')
                    ORDER BY g.name ASC
                    LIMIT 8
                ");
                $my_groups_stmt->execute([$user_id, $user_id]);
                $my_groups = $my_groups_stmt->fetchAll();

                // TEMP DEBUG: if the logged-in user is 'edmin', log the my_groups results for investigation
                if (isset($usr['username']) && $usr['username'] === 'edmin') {
                    $log = [];
                    $log[] = "DEBUG my_groups for user=edmin user_id=" . ($user_id ?? 'null');
                    $log[] = "count=" . count($my_groups);
                    foreach ($my_groups as $mg) { $log[] = "group: " . ($mg['id'] ?? '?') . " - " . ($mg['name'] ?? ($mg['slug'] ?? '?')); }
                    @file_put_contents('/tmp/group_debug.log', implode("\n", $log) . "\n---\n", FILE_APPEND | LOCK_EX);
                }
                
                foreach ($my_groups as $group):
                ?>
                <li>
                    <a href="<?= group_url($group['slug']) ?>"><?= htmlspecialchars($group['name']) ?></a>
                </li>
                <?php endforeach; ?>
                <?php if (empty($my_groups)): ?>
                <li><span class="muted small">Henüz kayıt olduğunuz bir topluluk yok</span></li>
                <?php endif; ?>
            </ul>
            <div class="text-right mt-5">
                <a href="<?= BASE_PATH ?>/topluluklar" class="sidebar-link-more">tüm topluluklar »</a>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Not Logged In -->
        <div class="stacked-actions">
            <a href="<?= BASE_PATH ?>/giris" class="btn btn-primary full-width">Giriş Yap</a>
            <a href="<?= BASE_PATH ?>/kayit" class="btn btn-secondary full-width">Kayıt Ol</a>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main Content -->
    <main class="content-area">
        <!-- Tag Navigation - Dynamic Trending Tags -->
        <?php if (!empty($trending_tags)): ?>
        <div class="tag-navigation">
            <span class="tag-label">Revaçtaki Mevzuatlar:</span>
            <?php foreach ($trending_tags as $tag):
                $tag_clean = ltrim($tag['tag'], '#');
            ?>
                <span class="tag-item">
                    <?php if ($user_id): ?>
                        <button type="submit" class="insert-tag" form="composer-form" name="insert_tag" value="<?= htmlspecialchars($tag_clean) ?>" aria-label="Insert #<?= htmlspecialchars($tag_clean) ?>">+</button>
                    <?php endif; ?>
                    <a href="<?= BASE_PATH ?>/ara?tag=<?= urlencode($tag_clean) ?>" class="tag-nav-item" data-tag="<?= htmlspecialchars($tag_clean) ?>">
                        <?= htmlspecialchars($tag_clean) ?>
                    </a>
                </span>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="tag-navigation tag-navigation-empty" style="padding: 10px 12px;">
            <span class="tag-label">Revaçtaki Mevzuatlar:</span>
            <span class="tag-empty" style="color:#666; font-size:12px;">Henüz popüler etiket yok. Yeni bir #etiket ile başlayın.</span>
        </div>
        <?php endif; ?>

        <?php if ($user_id): ?>
        <!-- Post Form -->
        <div class="post-form-container">
            <?php if (!empty($event_updates)): ?>
                <section class="event-updates-widget" style="margin-bottom:14px;">
                    <h3 class="section-title" style="margin:0 0 8px 0;">Etkinlik Güncellemeleri</h3>
                    <div class="updates-grid">
                        <?php foreach ($event_updates as $eu): ?>
                            <article class="update-card">
                                <?php if (!empty($eu['image_path'])): ?>
                                    <a href="<?= BASE_PATH ?>/event_view.php?id=<?= $eu['event_id'] ?>" class="update-thumb"><img src="<?= htmlspecialchars($eu['image_path']) ?>" alt=""></a>
                                <?php endif; ?>
                                <div class="update-content">
                                    <a href="<?= BASE_PATH ?>/event_view.php?id=<?= $eu['event_id'] ?>" class="update-event-title"><?= htmlspecialchars($eu['event_title']) ?></a>
                                    <div class="update-meta"><?= nl2br(htmlspecialchars(mb_substr($eu['content'] ?? '', 0, 120))) ?></div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= $error ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="alert alert-success">
                    <?= $_SESSION['flash'] ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <form method="POST" class="post-form" id="composer-form">
                <div class="post-toolbar">
                    <div class="toolbar-actions">
                        <button type="submit" name="insert_type" value="tag" class="btn-small">#</button>
                        <button type="submit" name="insert_type" value="link" class="btn-small">Link</button>
                        <button type="submit" name="insert_type" value="spoiler" class="btn-small">Ekstra</button>
                        <button type="submit" name="insert_type" value="kod" class="btn-small">Kod</button>
                        <?php if (!is_user_creation_restricted($user_id)): ?>
                            <a href="<?= BASE_PATH ?>/poll.php?action=new" class="btn-small">Anket</a>
                            <a href="<?= BASE_PATH ?>/tahlil/olustur" class="btn-small">Tahlil</a>
                        <?php else: ?>
                            <span class="btn-small muted" title="Yeni hesaplar için devre dışı">Anket</span>
                            <span class="btn-small muted" title="Yeni hesaplar için devre dışı">Tahlil</span>
                        <?php endif; ?>
                    </div>
                </div>



                <?php $current_draft = trim((string)($_POST['content'] ?? get_draft($user_id))); ?>

                <!-- Composer quick insert removed — plus buttons were unnecessary. Trending tags are available in the tag navigation. -->

                <textarea name="content" placeholder="Ne düşünüyorsun?"><?= sanitize_input($_POST['content'] ?? get_draft($user_id)) ?></textarea>
                <div class="post-form-actions">
                    <?php if ($user_id && get_user_post_limit($user_id) == 0): ?>
                        <span class="char-count">Sınırsız (Premium)</span>
                    <?php else: ?>
                        <span class="char-count">En fazla <?= MAX_POST_LENGTH ?> karakter</span>
                    <?php endif; ?>
                    <!-- Swapped buttons: Preview first, then Publish (grouped closely) -->
                    <div class="post-actions-buttons">
                        <button type="submit" name="action" value="preview" class="btn-outline">Önizleme</button>
                        <button type="submit" name="action" value="create_post" class="btn-post">Paylaş</button>
                    </div>
                </div>
            </form>

            <?php if ($user_id): ?>
                <?php if (!empty($preview_html) || !empty($preview_error)): ?>
                <div class="preview-box">
                    <div class="preview-title">Önizleme</div>
                    <?php if (!empty($preview_error)): ?>
                        <div class="preview-error"><?= htmlspecialchars($preview_error) ?></div>
                    <?php else: ?>
                        <div class="preview-html"><?= $preview_html ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <!-- Guest Landing -->
        <div class="guest-landing">
            <div class="guest-inner">
                <div class="guest-copy">
                    <h2 class="guest-title">Bize katıl</h2>
                    <p class="guest-text">Sosyal medya platformumuza hoş geldin. Burada kendi fikirlerini paylaş ve başkalarıyla bağlantı kur.</p>
                </div>
                <div class="join-actions">
                    <a href="<?= BASE_PATH ?>/kayit" class="btn-post">Kayıt Ol</a>
                    <a href="<?= BASE_PATH ?>/giris" class="btn-outline">Giriş Yap</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Unified Feed (Posts + Public Group Posts) -->
        <?php 
            $current_user_id = $user_id; 
            $combined_feed = [];
            foreach ($posts as $p) { $combined_feed[] = ['type' => 'post', 'created_at' => $p['created_at'], 'data' => $p]; }
            foreach ($group_feed as $gpf) { $combined_feed[] = ['type' => 'group', 'created_at' => $gpf['created_at'], 'data' => $gpf]; }

            // Inject event updates into unified feed for premium users (treat them like posts)
            if (!empty($event_updates)) {
                foreach ($event_updates as $eu) {
                    $combined_feed[] = ['type' => 'event_update', 'created_at' => $eu['created_at'], 'data' => $eu];
                }
            }
            // Inject recent event comments (render as feed post-cards)
            try {
                $ecStmt = $pdo->prepare("SELECT c.*, u.username, u.is_premium, u.role AS user_role, e.title AS event_title, (SELECT COUNT(*) FROM events_comments ec WHERE ec.parent_id = c.id) AS replies_count FROM events_comments c JOIN users u ON c.user_id = u.id JOIN events e ON c.event_id = e.id WHERE e.is_active = 1 AND (u.is_premium = 1 OR u.role = 'admin') AND c.parent_id IS NULL ORDER BY c.created_at DESC LIMIT 6");
                $ecStmt->execute();
                $recent_ecomments = $ecStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($recent_ecomments as $rc) {
                    // only show event comments from premium users in the main feed
                    $combined_feed[] = ['type' => 'event_comment', 'created_at' => $rc['created_at'], 'data' => $rc];
                }
            } catch (Exception $e) {
                // ignore feed injection errors
            }

            // De-duplicate timeline rows by source type + ID to avoid repeated entries from duplicate insertion routes
            $seen_feed = [];
            $unique_feed = [];
            foreach ($combined_feed as $item) {
                $id = isset($item['data']['id']) ? $item['data']['id'] : 0;
                $key = $item['type'] . '_' . $id;
                if (isset($seen_feed[$key])) {
                    continue;
                }
                $seen_feed[$key] = true;
                $unique_feed[] = $item;
            }
            $combined_feed = $unique_feed;

            usort($combined_feed, function($a, $b) { return strtotime($b['created_at']) <=> strtotime($a['created_at']); });
        ?>
        <div class="posts-feed">
            <?php if (empty($combined_feed)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <p>Henüz gönderi yok. İlk gönderen sen ol!</p>
                </div>
            <?php else: ?>
                <?php foreach ($combined_feed as $item): ?>
                    <?php if ($item['type'] === 'post'): ?>
                        <?php $post = $item['data']; require __DIR__ . '/templates/post-card.php'; ?>
                    <?php elseif ($item['type'] === 'group'): ?>
                        <?php $gp = $item['data']; require __DIR__ . '/templates/group-post-card.php'; ?>
                    <?php elseif ($item['type'] === 'event_update'): ?>
                        <?php $eu = $item['data']; require __DIR__ . '/templates/event-update-card.php'; ?>
                    <?php elseif ($item['type'] === 'event_comment'): ?>
                        <?php $ec = $item['data']; $item = ['data' => $ec]; require __DIR__ . '/templates/event-comment-post-card.php'; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Cursor-Based Pagination -->
        <?php if (!empty($posts) || $has_prev): ?>
        <div class="footer-cta">
            <div class="footer-note">
                <?php 
                $start = $after ? 'Sonraki gönderiler' : '1. gönderiden itibaren';
                $approx_total = $total_posts;
                echo $start . ' · Toplam: <strong>' . number_format($approx_total) . '</strong> gönderi';
                ?>
            </div>
            
            <div class="footer-actions">
                <?php if ($has_prev && $first_id): ?>
                    <form method="GET" class="inline-form">
                        <input type="hidden" name="before" value="<?= $first_id ?>">
                        <button type="submit" class="btn btn-join">‹ Önceki <?= $posts_limit ?> Gönderi</button>
                    </form>
                <?php endif; ?>
                
                <?php if ($has_next && $last_id): ?>
                    <form method="GET" class="inline-form">
                        <input type="hidden" name="after" value="<?= $last_id ?>">
                        <button type="submit" class="btn btn-join">Sonraki <?= $posts_limit ?> Gönderi ›</button>
                    </form>
                <?php endif; ?>
            </div>
            
            <?php if (!$has_next && count($posts) > 0): ?>
                <div class="muted small">
                    ✓ Tüm gönderileri görüntülediniz
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>

    <?php 
    // After rendering feed, mark as seen now so next visit shows 0 new items
    if ($user_id) { $_SESSION['last_feed_seen_at'] = date('Y-m-d H:i:s'); }
    ?>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">

        <!-- Announcements -->
        <div class="sidebar-section">
            <div class="sidebar-title">📢 Duyurular</div>
            <?php if (empty($announcements)): ?>
                <div class="muted small text-center padded">
                    Henüz duyuru yok
                </div>
            <?php else: ?>
                <?php foreach ($announcements as $announcement): ?>
                <a href="<?= announcement_url($announcement['slug'] ?? '', $announcement['id'], $announcement['created_at'] ?? null) ?>" class="announcement-link">
                    <div class="announcement-box">
                        <div class="announcement-title"><?= htmlspecialchars(mb_substr($announcement['title'], 0, 50)) ?></div>
                        <div class="announcement-date muted"><?= date('d.m.Y H:i', strtotime($announcement['created_at'])) ?></div>
                        <div class="announcement-text"><?= htmlspecialchars(mb_substr($announcement['summary'], 0, 80)) ?>...</div>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Friend Suggestions -->
        <?php if ($user_id):
            $suggestions = get_friend_suggestions($user_id, 5);
        ?>
        <div class="sidebar-section">
            <div class="sidebar-title">Takip etsen güzel olur</div>
            <?php if (empty($suggestions)): ?>
                <div class="muted small text-center">Henüz öneri yok</div> 
            <?php else: ?>
                <ul class="sidebar-list">
                    <?php foreach ($suggestions as $s): ?>
                    <li class="nav-item">
                        <div class="suggestion-row">
                            <div class="suggestion-name">
                                <a href="<?= profile_url($s['username']) ?>" class="link-reset">@<?= htmlspecialchars($s['username']) ?></a>
                            </div>
                            <div class="suggestion-action">
                                <form method="POST" action="<?= BASE_PATH ?>/api/follow.php" class="form-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="following_id" value="<?= $s['id'] ?>">
                                    <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                                    <button class="follow-btn-compact" type="submit">kuyruk</button>
                                </form>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="text-right mt-5"><a href="<?= BASE_PATH ?>/suggestions.php">Daha fazla »</a></div> 
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Quick Links -->
        <div class="sidebar-section">
            <div class="sidebar-title">Hızlı Erişim</div>
            <ul class="sidebar-list">
                <?php $groups_link = BASE_PATH . (use_clean_urls() ? '/topluluklar' : '/groups.php'); ?>
                <li><a href="<?= $groups_link ?>">👥 Topluluklar</a></li>
                <li><a href="<?= BASE_PATH ?><?= use_clean_urls() ? '/etkinlikler' : '/events.php' ?>">📅 Etkinlikler</a></li>
                <li><a href="<?= BASE_PATH ?><?= use_clean_urls() ? '/ara' : '/search.php' ?>">🔍 Arama</a></li>
            </ul>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
