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

if (preg_match('#^/bulk-optout(?:/([A-Fa-f0-9]{64}))?$#', $reqPath, $m)) {
    ensure_bulk_optin_tables();
    $token = trim($_GET['token'] ?? ($m[1] ?? ''));
    $message = null;
    $error = null;
    $email = null;
    $alreadyOptedOut = false;

    if ($token === '') {
        $error = 'Geçersiz ya da eksik token.';
    } else {
        $email = validate_bulk_optout_token($token);
        if (!$email) {
            $pdo = db_connect();
            $stmt = $pdo->prepare("SELECT email FROM bulk_optout_tokens WHERE token = ? LIMIT 1");
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && is_bulk_opted_out($row['email'])) {
                $alreadyOptedOut = true;
                $email = $row['email'];
            } else {
                $error = 'Geçersiz veya süresi dolmuş bağlantı.';
            }
        }
    }

    if (!$error && $email && $_SERVER['REQUEST_METHOD'] === 'GET' && !$alreadyOptedOut) {
        add_bulk_optout($email, 'user_requested');
        mark_bulk_optout_token_used($token);
        $message = 'E-posta listesinden çıkarıldınız.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token'])) {
        require_csrf();
        $token = trim($_POST['token']);
        $email = validate_bulk_optout_token($token);
        if (!$email) {
            $error = 'Bağlantınız artık geçerli değil.';
        } else {
            add_bulk_optout($email, 'user_requested');
            mark_bulk_optout_token_used($token);
            $message = 'E-posta listesinden çıkarıldınız.';
        }
    }

    ?>
    <main class="page-content" style="max-width: 720px; margin: 0 auto; padding: 24px;">
        <h1>E-posta Abonelikten Çık</h1>
        <?php if ($error): ?>
            <div style="background: #ffe5e5; color: #900; border-radius: 8px; padding: 18px; margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($message): ?>
            <div style="background: #e8f7e8; color: #0a660a; border-radius: 8px; padding: 18px; margin-bottom: 20px;"><?= htmlspecialchars($message) ?></div>
        <?php elseif ($alreadyOptedOut): ?>
            <div style="background: #f4f4f4; color: #333; border-radius: 8px; padding: 18px; margin-bottom: 20px;">Bu e-posta adresi zaten abonelikten çıkarılmış durumda.</div>
        <?php else: ?>
            <p>Bu bağlantı <strong><?= htmlspecialchars($email) ?></strong> için geçerli.</p>
            <p>E-posta almak istemiyorsanız aşağıdaki butona tıklayarak abonelikten çıkabilirsiniz.</p>
            <form method="post">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <button type="submit" style="padding: 12px 18px; border: none; border-radius: 6px; background: #c00; color: white; cursor: pointer;">Abonelikten Çık</button>
            </form>
        <?php endif; ?>
    </main>
    <?php require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Guest visitors see the landing page directly (URL stays clean)
$user_id = get_current_user_id();
// If a session cookie exists but no authenticated user was resolved, clear the stale cookie.
// Preserve the root URL and serve landing.php internally instead of redirecting.
if (!$user_id && !empty($_COOKIE[session_name()]) && empty($_REQUEST['sid'])) {
    if (in_array($reqPath, ['/anasayfa', '/anasayfa/', '/index.php'], true)) {
        error_log('Stale session cookie detected on ' . $reqPath . '; clearing session and redirecting to landing.');
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
        }
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        header('Location: ' . BASE_PATH . '/landing.php', true, 302);
        exit;
    }
    if ($reqPath === '/') {
        error_log('Stale session cookie detected on root; clearing session and serving landing page with preserved URL.');
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
        }
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        // Continue to landing.php below without external redirect.
    }
}
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

// Username-scoped friendly compare URL: /{username}/p/{id}/karsilastirma/... 
if (preg_match('#^/([^/]+)/p/([0-9]+)/karsilastirma(?:/(history|gecmis|latest|son-duzenleme|[0-9]+))?/?$#', $reqPath, $um)) {
    $_GET['username'] = $um[1];
    $_GET['id'] = $um[2];
    if (isset($um[3]) && $um[3] !== '') {
        $_GET['compare'] = $um[3];
    } elseif (strpos($reqPath, '/latest') !== false || strpos($reqPath, '/son-duzenleme') !== false) {
        $_GET['compare'] = 'latest';
    } elseif (strpos($reqPath, '/history') !== false || strpos($reqPath, '/gecmis') !== false) {
        $_GET['compare'] = 'history';
    }
    require __DIR__ . '/post.php';
    exit;
}

// Turkish history path shim -> route to post.php with compare=history
if (preg_match('#^/post/([0-9]+)/karsilastirma/(history|gecmis)/?$#', $reqPath, $hm)) {
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

$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$normalizedPath = rtrim($reqPath, '/') ?: '/';

$feed = 'general';
$basePathTrimmed = rtrim(BASE_PATH, '/');
$feedRoutes = [
    'general' => $basePathTrimmed . '/akis',
    'followed' => $basePathTrimmed . '/akis/kuyruk',
];

$sid_value = '';
$sid_query = '';
if (!empty($_REQUEST['sid'])) {
    $sid_value = preg_replace('/[^A-Za-z0-9._-]/', '', $_REQUEST['sid']);
    if ($sid_value !== '') {
        $sid_query = '?sid=' . rawurlencode($sid_value);
    }
}

if (isset($_GET['feed'])) {
    $feed = $_GET['feed'] === 'followed' ? 'followed' : 'general';
} elseif ($normalizedPath === $feedRoutes['followed'] || $normalizedPath === $feedRoutes['general']) {
    $feed = $normalizedPath === $feedRoutes['followed'] ? 'followed' : 'general';
}
if (!$user_id && $feed === 'followed') {
    $feed = 'general';
}

// Track last time user saw the main feed (session-based)
$last_feed_seen_at = isset($_SESSION['last_feed_seen_at']) ? $_SESSION['last_feed_seen_at'] : null;
$general_last_seen = $last_feed_seen_at;
// Followed feed uses its own last-seen timestamp if available.
// If the user has never opened Kuyruk, fall back to the last Ana Akış visit.
// Also treat a newer Ana Akış visit as having already exposed followed content.
$followed_last_seen = $last_feed_seen_at;
if (isset($_SESSION['last_followed_feed_seen_at'])) {
    $followed_last_seen = $_SESSION['last_followed_feed_seen_at'];
}
if ($last_feed_seen_at && isset($_SESSION['last_followed_feed_seen_at']) && $last_feed_seen_at > $followed_last_seen) {
    $followed_last_seen = $last_feed_seen_at;
}
$general_new_count = 0;
$followed_new_count = 0;
if ($user_id) {
    if ($general_last_seen) {
        $general_new_count = get_new_feed_count($user_id, $general_last_seen);
    }
    if ($followed_last_seen) {
        $followed_new_count = get_new_posts_count_for_feed($user_id, 'followed', $followed_last_seen);
    }
}





if ($user_id && $_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['insert_type']) || isset($_POST['insert_tag']))) {
    require_csrf();
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

// Handle preview / schedule-mode
$skip_create = false;
$show_schedule = false;
if ($user_id && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_cancel'])) {
    require_csrf();
    $show_schedule = false;
    $_POST['scheduled_at'] = '';
    $preview_content = $_POST['content'] ?? get_draft($user_id);
    if (trim($preview_content) === '') {
        $preview_error = 'Önizlemek için içerik gerekli.';
    } else {
        $preview_html = render_rich_text($preview_content);
    }
    save_draft($user_id, $preview_content);
    $skip_create = true;
} elseif ($user_id && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_mode'])) {
    $show_schedule = true;
    $preview_content = $_POST['content'] ?? get_draft($user_id);
    if (trim($preview_content) === '') {
        $preview_error = 'Önizlemek için içerik gerekli.';
    } else {
        $preview_html = render_rich_text($preview_content);
    }
    save_draft($user_id, $preview_content);
    $skip_create = true;
} elseif ($user_id && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview'])) {
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
    require_csrf();
    $content = $_POST['content'] ?? '';
    if (trim($content) === '') {
        $content = get_draft($user_id);
    }

    $is_schedule_mode = isset($_POST['schedule_mode']);
    $is_schedule_submit = isset($_POST['schedule_submit']);
    $is_preview = isset($_POST['preview']);
    $is_publish = isset($_POST['publish']);

    if ($is_schedule_mode) {
        $show_schedule = true;
        $preview_content = $content;
        if (trim($preview_content) !== '') {
            $preview_html = render_rich_text($preview_content);
        }
        save_draft($user_id, $preview_content);
    }

    if ($is_preview) {
        $show_schedule = false;
        $preview_content = $content;
        if (trim($preview_content) !== '') {
            $preview_html = render_rich_text($preview_content);
        }
        save_draft($user_id, $preview_content);
        $skip_create = true;
    }

    if (!$is_publish && !$is_schedule_submit) {
        // Either in preview/schedule mode for display only
        if ($is_preview || $is_schedule_mode) {
            $skip_create = true;
        }
    }

    if ($skip_create) {
        // do not create post now
    } else {
        if (empty($content)) {
            $errors[] = t('post_empty_error');
        } else {
            $scheduled_at = null;
            if ($is_schedule_submit) {
                $scheduled_at = trim($_POST['scheduled_at'] ?? '');
                if ($scheduled_at === '') {
                    $errors[] = 'Zamanlanmış zaman gereklidir.';
                    $show_schedule = true;
                }
            } elseif (!$is_schedule_submit && isset($_POST['scheduled_at']) && trim($_POST['scheduled_at']) !== '') {
                $scheduled_at = trim($_POST['scheduled_at']);
            }

            if (empty($errors)) {
                $res = create_post($user_id, $content, null, $scheduled_at);
                if (isset($res['error'])) {
                    if ($res['error'] === 'suspended') {
                        $errors[] = t('post_suspended_error', htmlspecialchars($res['until']));
                    } elseif ($res['error'] === 'unapproved_limit') {
                        $errors[] = $res['message'];
                    } elseif ($res['error'] === 'limit_exceeded') {
                        $errors[] = $res['message'];
                    } elseif ($res['error'] === 'scheduled_at_invalid') {
                        $errors[] = 'Geçersiz planlanmış tarih/saat formatı.';
                        $show_schedule = true;
                    } elseif ($res['error'] === 'scheduled_at_past') {
                        $errors[] = 'Planlanan gönderi geçmiş zamanda olamaz.';
                        $show_schedule = true;
                    } elseif ($res['error'] === 'scheduled_at_too_far') {
                        $errors[] = 'Planlama tarihi çok uzak (1 yıl içinde olmalı).';
                        $show_schedule = true;
                    } elseif ($res['error'] === 'premium_required_scheduled_post') {
                        $errors[] = 'Planlama özelliği yalnızca premium kullanıcılar için uygundur.';
                    } else {
                        $errors[] = t('post_failed_error');
                    }
                } elseif (isset($res['id'])) {
                    if ($res['has_bad_words']) {
                        $_SESSION['flash'] = t('post_badwords_censored');
                    } elseif (!empty($scheduled_at)) {
                        $_SESSION['flash'] = t('post_scheduled_success');
                    } elseif ($res['approved']) {
                        $_SESSION['flash'] = t('post_created_flash');
                    } else {
                        $_SESSION['flash'] = t('post_pending_flash');
                    }
                    save_draft($user_id, '');
                    $redir = rtrim(BASE_PATH, '/') . '/';
                    if ($redir === '') { $redir = '/'; }
                    if (!empty($_REQUEST['sid'])) {
                        $sid_param = preg_replace('/[^A-Za-z0-9._-]/', '', $_REQUEST['sid']);
                        if ($sid_param) { $redir .= '?sid=' . rawurlencode($sid_param); }
                    }
                    header('Location: ' . $redir);
                    exit;
                }
            }
        }
    }
}

// Get timeline posts with relevance algorithm and cursor-based pagination
$after = $_GET['after'] ?? null;
$before = $_GET['before'] ?? null;
$posts_limit = 40;

// Use relevance-based feed for logged-in users, chronological for guests
if ($user_id && $feed === 'followed') {
    $pagination = get_followed_posts_paginated($user_id, $posts_limit, $after, $before);
} elseif ($user_id) {
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

// Get recent group posts for homepage (public; include private for admins or members of private groups)
//group_post_edits post_id used to be grou_post_id
$group_feed = [];
try {
    if (is_admin()) {
        $stmt = $pdo->prepare("
            SELECT
        gp.*,
        u.username,
        u.is_premium,
        g.name AS group_name,
        g.slug,
        ui.id as image_id,
        ui.filename as image_filename,      -- Add alias
        ui.publish_date as image_publish_date,  -- Add alias
        ui.tags as image_tags,               -- Add alias
        ui.user_id as image_user_id,        -- Add alias
        EXISTS(SELECT 1 FROM group_post_edits WHERE post_id = gp.id) AS has_edits,
        (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id) AS like_count,
        (SELECT COUNT(*) FROM group_post_comments WHERE post_id = gp.id) AS comment_count,
        (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id AND user_id = ?) AS user_has_liked
    FROM group_posts gp
    JOIN groups_table g ON gp.group_id = g.id
    JOIN users u ON gp.user_id = u.id
    LEFT JOIN user_images ui ON gp.image_id = ui.id
    ORDER BY gp.created_at DESC
    LIMIT 10
        ");
        $stmt->execute([$user_id ?: 0]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
        gp.*,
        u.username,
        u.is_premium,
        g.name AS group_name,
        g.slug,
        ui.id as image_id,
        ui.filename as image_filename,      -- Add alias
        ui.publish_date as image_publish_date,  -- Add alias
        ui.tags as image_tags,               -- Add alias
        ui.user_id as image_user_id,        -- Add alias
        EXISTS(SELECT 1 FROM group_post_edits WHERE post_id = gp.id) AS has_edits,
        (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id) AS like_count,
        (SELECT COUNT(*) FROM group_post_comments WHERE post_id = gp.id) AS comment_count,
        (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id AND user_id = ?) AS user_has_liked
    FROM group_posts gp
    JOIN groups_table g ON gp.group_id = g.id
    LEFT JOIN group_members gm ON g.id = gm.group_id AND gm.user_id = ?
    JOIN users u ON gp.user_id = u.id
    LEFT JOIN user_images ui ON gp.image_id = ui.id
    WHERE COALESCE(g.is_private, 0) = 0 OR gm.user_id IS NOT NULL
    ORDER BY gp.created_at DESC
    LIMIT 10
        ");
        $stmt->execute([$user_id ?: 0, $user_id ?: 0]);
    }
    $group_feed = $stmt->fetchAll();
    foreach ($group_feed as &$gpf) {
    $gpf['poll'] = get_poll_for_group_post($gpf['id']);
    if (!empty($gpf['image_id'])) {
        $gpf['image'] = [
            'id' => $gpf['image_id'],
            'filename' => $gpf['image_filename'],
            'publish_date' => $gpf['image_publish_date'],
            'tags' => $gpf['image_tags'],
            'user_id' => $gpf['image_user_id']
        ];
    }
}
unset($gpf);
} catch (Throwable $e) {
    error_log('group_feed query failed (table may not exist): ' . $e->getMessage());
    $group_feed = [];
}

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
$stmt = $pdo->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 3");
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
$notification_total = 0;
$new_group_count = 0;

if ($user_id) {
    try {
        $notification_total = get_unread_count($user_id);
        $new_group_count = get_new_group_posts_count($user_id);

        // Count invitations sent so far (to enforce 10-limit in UI)
        $inv_stmt = $pdo->prepare("SELECT COUNT(*) as c FROM user_invitations WHERE invited_by = ?");
        $inv_stmt->execute([$user_id]);
        $invite_count = (int)$inv_stmt->fetch()['c'];
    } catch (Exception $e) {
        error_log("Error getting notification counts: " . $e->getMessage());
        $invite_count = 0;
        $new_group_count = 0;
    }
}
?>

<div class="main-container">
    <!-- Left Navigation Menu -->
    <aside class="sidebar sidebar-left sidebar-card">
        <?php if ($user_id): ?>
            <div class="sidebar-section mb-12">
                <div class="sidebar-section-title">Gezinti</div>
                <ul class="side-menu no-margin">
                    <li><a href="<?= home_url() ?>"><span class="menu-icon icon-home" aria-hidden="true"></span>Ana Sayfa</a></li>
                    <li><a href="<?= search_url() ?>"><span class="menu-icon icon-search" aria-hidden="true"></span>Ara</a></li>
                    <li>
                        <a href="<?= notification_url() ?>"><span class="menu-icon icon-bell" aria-hidden="true"></span>Bildirimler</a>
                        <?php if ($notification_total > 0): ?>
                            <span class="badge red"><?= $notification_total ?></span>
                        <?php endif; ?>
                    </li>
                    <li>
                        <a href="<?= BASE_PATH ?>/topluluklar"><span class="menu-icon icon-users" aria-hidden="true"></span>Topluluklar</a>
                        <?php if ($new_group_count > 0): ?>
                            <span class="badge blue"><?= $new_group_count ?></span>
                        <?php endif; ?>
                    </li>
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
        <?php else: ?>
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
                                    <a href="<?= htmlspecialchars(event_view_url($eu['event_id'], $eu['event_title'] ?? '')) ?>" class="update-thumb"><img src="<?= htmlspecialchars($eu['image_path']) ?>" alt=""></a>
                                <?php endif; ?>
                                <div class="update-content">
                                    <a href="<?= htmlspecialchars(event_view_url($eu['event_id'], $eu['event_title'] ?? '')) ?>" class="update-event-title"><?= htmlspecialchars($eu['event_title']) ?></a>
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
                <input type="hidden" name="action" value="create_post">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="post-toolbar">
                    <div class="toolbar-actions">
                        <button type="submit" name="insert_type" value="tag" class="btn-small">#</button>
                        <button type="submit" name="insert_type" value="link" class="btn-small">Link</button>
                        <button type="submit" name="insert_type" value="spoiler" class="btn-small">Ekstra</button>
                        <button type="submit" name="insert_type" value="kod" class="btn-small">Kod</button>
                        <?php if (!is_user_creation_restricted($user_id)): ?>
                            <a href="<?= BASE_PATH ?>/anket/olustur" class="btn-small">Anket</a>
                            <a href="<?= BASE_PATH ?>/tahlil/olustur" class="btn-small">Tahlil</a>
                        <?php else: ?>
                            <span class="btn-small muted" title="Yeni hesaplar için devre dışı">Anket</span>
                            <span class="btn-small muted" title="Yeni hesaplar için devre dışı">Tahlil</span>
                        <?php endif; ?>
                        <a href="<?= BASE_PATH ?>/fotograf-yukle?context=profile" class="btn-small">📷 Fotoğraf</a>
                    </div>
                </div>



                <?php $current_draft = trim((string)($_POST['content'] ?? get_draft($user_id))); ?>

                <!-- Composer quick insert removed — plus buttons were unnecessary. Trending tags are available in the tag navigation. -->

                <textarea name="content" placeholder="Ne düşünüyorsun?"><?= sanitize_input($_POST['content'] ?? get_draft($user_id)) ?></textarea>
                <?php if ((is_user_premium($user_id) || is_admin()) && ($show_schedule || !empty($_POST['scheduled_at']))) : ?>
                    <div class="post-schedule-row">
                        <label for="scheduled_at">⏰ Zamanla:</label>
                        <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="<?= htmlspecialchars($_POST['scheduled_at'] ?? '') ?>" />
                        <button type="submit" name="schedule_submit" value="1" class="btn-post">Kaydet</button>
                        <button type="submit" name="schedule_cancel" value="1" class="btn-outline">İptal</button>
                    </div>
                <?php endif; ?>
                <div class="post-form-actions">
                    <?php if ($user_id && get_user_post_limit($user_id) == 0): ?>
                        <span class="char-count">Sınırsız (Premium)</span>
                    <?php else: ?>
                        <span class="char-count">En fazla <?= MAX_POST_LENGTH ?> karakter</span>
                    <?php endif; ?>
                    <!-- Swapped buttons: Preview first, then Publish (grouped closely) -->
                    <div class="post-actions-buttons">
                        <button type="submit" name="preview" value="1" class="btn-outline">Önizleme</button>
                        <?php if (is_user_premium($user_id) || is_admin()): ?>
                            <button type="submit" name="schedule_mode" value="1" class="btn-outline">Zamanla</button>
                        <?php endif; ?>
                        <button type="submit" name="publish" value="1" class="btn-post">Paylaş</button>
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

        <div class="timeline-tabs">
            <div class="tab-links" role="tablist" aria-label="Zaman akışı sekmeleri">
                <a href="<?= $basePathTrimmed ?>/akis<?= $sid_query ?>" class="tab-link <?= $feed === 'general' ? 'active' : '' ?>">
                    Akış
                    <?php if ($general_new_count > 0): ?>
                        <span class="tab-count"><?= $general_new_count > 99 ? '99+' : $general_new_count ?></span>
                    <?php endif; ?>
                </a>
                <?php if ($user_id): ?>
                    <a href="<?= $basePathTrimmed ?>/akis/kuyruk<?= $sid_query ?>" class="tab-link <?= $feed === 'followed' ? 'active' : '' ?>">
                        Kuyruk
                        <?php if ($followed_new_count > 0): ?>
                            <span class="tab-count"><?= $followed_new_count > 99 ? '99+' : $followed_new_count ?></span>
                        <?php endif; ?>
                    </a>
                <?php else: ?>
                    <span class="tab-link muted">Kuyruk</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Unified Feed (Posts + Public Group Posts) -->
        <?php 
            $current_user_id = $user_id; 
            $combined_feed = [];

            // Build a map of user_id → [timestamps] from regular posts
            // Used to suppress duplicate cross-posts in group feed
                $post_user_timestamps = [];
                foreach ($posts as $p) {
                    $combined_feed[] = ['type' => 'post', 'created_at' => $p['created_at'], 'data' => $p];
                    $uid = (int)$p['user_id'];
                    $post_user_timestamps[$uid][] = strtotime($p['created_at']);
                }

            // Skip group posts if the same user has a regular post within ±5 minutes
            $dedup_window = 300; // seconds
            foreach ($group_feed as $gpf) {
                $uid = (int)$gpf['user_id'];
                $gts = strtotime($gpf['created_at']);
                if (isset($post_user_timestamps[$uid])) {
                    $dominated = false;
                    foreach ($post_user_timestamps[$uid] as $pts) {
                        if (abs($gts - $pts) <= $dedup_window) {
                            $dominated = true;
                            break;
                        }
                    }
                    if ($dominated) {
                        continue;
                    }
                }
                $combined_feed[] = ['type' => 'group', 'created_at' => $gpf['created_at'], 'data' => $gpf];
            }
            foreach ($event_updates as $eu) {
                $combined_feed[] = ['type' => 'event_update', 'created_at' => $eu['created_at'], 'data' => $eu];
            }
            // Inject recent event comments (render as feed post-cards)
            try {
                $ecStmt = $pdo->prepare("SELECT c.*, u.username, u.is_premium, u.role AS user_role, e.title AS event_title, (SELECT COUNT(*) FROM events_comments ec WHERE ec.parent_id = c.id AND ec.deleted_at IS NULL) AS replies_count FROM events_comments c JOIN users u ON c.user_id = u.id JOIN events e ON c.event_id = e.id WHERE e.is_active = 1 AND (u.is_premium = 1 OR u.role = 'admin') AND c.parent_id IS NULL AND c.deleted_at IS NULL ORDER BY c.created_at DESC LIMIT 6");
                $ecStmt->execute();
                $recent_ecomments = $ecStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($recent_ecomments as $rc) {
                    // only show event comments from premium users in the main feed
                    $combined_feed[] = ['type' => 'event_comment', 'created_at' => $rc['created_at'], 'data' => $rc];
                }
            } catch (Exception $e) {
                // ignore feed injection errors
            }
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
                        <?php if ($feed === 'followed'): ?><input type="hidden" name="feed" value="followed"><?php endif; ?>
                        <?php if ($sid_value !== ''): ?><input type="hidden" name="sid" value="<?= htmlspecialchars($sid_value) ?>"><?php endif; ?>
                        <input type="hidden" name="before" value="<?= $first_id ?>">
                        <button type="submit" class="btn btn-join">‹ Önceki <?= $posts_limit ?> Gönderi</button>
                    </form>
                <?php endif; ?>
                
                <?php if ($has_next && $last_id): ?>
                    <form method="GET" class="inline-form">
                        <?php if ($feed === 'followed'): ?><input type="hidden" name="feed" value="followed"><?php endif; ?>
                        <?php if ($sid_value !== ''): ?><input type="hidden" name="sid" value="<?= htmlspecialchars($sid_value) ?>"><?php endif; ?>
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
    if ($user_id) {
        if ($feed === 'followed') {
            $_SESSION['last_followed_feed_seen_at'] = date('Y-m-d H:i:s');
        } else {
            $_SESSION['last_feed_seen_at'] = date('Y-m-d H:i:s');
        }
    }
    ?>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">

        <!-- Announcements -->
        <div class="sidebar-section">
            <div class="sidebar-title"><span class="menu-icon icon-announce" aria-hidden="true"></span>Duyurular</div>
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
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
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

    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
