<?php /* EN + TR comments used. */
// start buffering immediately to allow headers() later in pages that include header.php
// sayfaların header(); çağırabilmesi için hemen tamponlama başlatılır
ob_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

// Shutdown handler removed — config.php already registers a log-only handler.
// Debug header output removed — was leaking internal routing info in HTML source.

// establish CSRF token and current user early; some logic below relies on them
$csrf_token = generate_csrf_token();
$current_user_id = get_current_user_id();

// Per-request CSP nonce; used in Content-Security-Policy headers and
// any <style nonce="..."> blocks that need to survive without 'unsafe-inline'.
$csp_nonce = base64_encode(random_bytes(16));

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_mobile = preg_match('/Android|webOS|iPhone|iPad|iPod|Mobile/i', $ua);
// IAP module: $is_in_app WebView detection is only meaningful when IAP is active.
$is_in_app = false;
if (defined('IAP_ENABLED') && IAP_ENABLED) {
    $is_in_app = preg_match('/WebView|wv|myapp|com\.example\.webviewwrapper|CFNetwork|CriOS|FxiOS/i', $ua);
    if (!$is_in_app && !empty($_SERVER['HTTP_X_IN_APP']) && $_SERVER['HTTP_X_IN_APP'] === '1') {
        $is_in_app = 1;
    }
}

// Simple maintenance/offline helper: do not crash if helper file doesn't exist.
function is_maintenance_mode(): bool {
    if (php_sapi_name() === 'cli') return false;
    if (getenv('MAINTENANCE') === '1') return true;
    $flag = __DIR__ . '/../tmp/MAINTENANCE';
    return file_exists($flag);
}

$script = basename($_SERVER['PHP_SELF']);
$maintenance_allowed = in_array($script, ['offline.php', 'login.php', 'register.php']);

if (is_maintenance_mode() && !$maintenance_allowed && !is_admin()) {
    // Render minimal offline page without requiring extra files (prevents 500 on missing helper)
    http_response_code(503);
    header('Retry-After: 300');
    $logo = BASE_PATH . '/assets/logo-green.svg';
    $site = htmlspecialchars(SITE_NAME);
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>' . $site . ' - Çevrimdışı</title>';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<style>body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f3f7f2;color:#1f3f2b;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center}.offline-card{max-width:480px;padding:32px 28px;border-radius:14px;background:#ffffff;border:1px solid rgba(90,154,60,0.25);box-shadow:0 18px 40px rgba(0,0,0,0.08)}.offline-logo{height:36px;margin-bottom:18px}.offline-title{margin:0;font-size:24px;font-weight:700;color:#21572d}.offline-text{margin:18px 0 0;font-size:16px;line-height:1.6;color:#2f4e3d}.offline-note{margin-top:22px;font-size:13px;color:#5a7d68}.offline-link{display:inline-block;margin-top:18px;padding:10px 18px;border-radius:8px;background:#5a9a3c;color:#fff;text-decoration:none;font-weight:600;}</style>';
    echo '</head><body>';
    echo '<div class="offline-card">';
    echo '<img src="' . $logo . '" class="offline-logo" alt="' . $site . '">';
    echo '<h1 class="offline-title">' . $site . ' şu anda çevrimdışıdır.</h1>';
    echo '<p class="offline-text">Deneme sürümümüzde günün her saati çevrimiçi olamıyoruz.  </p>';
    echo '<p class="offline-note">Birkaç saat sonra tekrar deneyin.>';
    echo '</div>';
    echo '</body></html>';
    exit;
}

// Cookie notice handler must run before any output.
require_once __DIR__ . '/cookie-notice-handler.php';

// Global security headers (applied to all web responses)
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    // Strict-Transport-Security is emitted by .htaccess to avoid duplicate HSTS headers.
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // X-Frame-Options, X-Content-Type-Options and Permissions-Policy are set in .htaccess to avoid duplicates.
    // Enforced CSP — emitted here (not .htaccess) so the per-request nonce can be interpolated into style-src.
    header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self' 'nonce-" . $csp_nonce . "'; img-src 'self' data:; font-src 'self'; object-src 'none'; form-action 'self'; base-uri 'self'; frame-ancestors 'self'");
    // Conservative CSP in Report-Only mode to detect violations before enforcing.
    header("Content-Security-Policy-Report-Only: default-src 'self'; script-src 'none'; style-src 'self' 'nonce-" . $csp_nonce . "'; img-src 'self' data: https:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; report-uri " . BASE_PATH . "/tools/csp_report.php");
}

// If a URL session id (`sid`) is provided, capture it and start output buffering
$CURRENT_SID = null;
if (!empty($_REQUEST['sid'])) {
    // Allow base64url and dot separator in sid values
    $CURRENT_SID = preg_replace('/[^A-Za-z0-9._-]/', '', $_REQUEST['sid']);
    // Start buffering so we can append sid to links/forms later in footer
    ob_start();
}

// debug_js support removed to enforce no-JS production policy (no output buffering started here)

// Track user activity on every page load (must never break whole site)
if ($current_user_id) {
    try {
        track_user_activity($current_user_id);
    } catch (Throwable $e) {
        error_log('track_user_activity failed: ' . $e->getMessage());
    }
}

if (function_exists('track_page_visit')) {
    try {
        track_page_visit();
    } catch (Throwable $e) {
        error_log('track_page_visit failed: ' . $e->getMessage());
    }
}

if ($current_user_id) {
    try {
        $current_user = get_user($current_user_id);

        
        if ($current_user && array_key_exists('birthday', $current_user)) {
            // Use REQUEST_URI for page detection — PHP_SELF is always
            // dispatch.php when requests go through the front controller.
            $req_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

            // Pages allowed without birthday or when underage
            $birthday_skip = ['/set_birthday.php', '/logout.php', '/cikis', '/underage.php', '/dogum-gunu'];
            $is_allowed = false;
            foreach ($birthday_skip as $skip) {
                if ($req_path === $skip || str_starts_with($req_path, $skip . '?')) {
                    $is_allowed = true;
                    break;
                }
            }
            
            if (empty($current_user['birthday']) && !$is_allowed) {
                header('Location: ' . BASE_PATH . '/dogum-gunu');
                exit;
            }
            
            if (!empty($current_user['birthday'])) {
                $birthday = new DateTime($current_user['birthday']);
                $today = new DateTime();
                $age = $today->diff($birthday)->y;
                
                if ($age < 16 && !$is_allowed) {
                    header('Location: ' . BASE_PATH . '/underage.php');
                    exit;
                }
            }
        }
    } catch (Exception $e) {
        error_log('Age verification error: ' . $e->getMessage());
    }
}

// Fetch celebration banner data here (before HTML template) so colors are available
// inside the nonce'd <style> block in <head>.
$celebration_banner_html = '';
$celebration_bg_color = '#ffffff';
$celebration_text_color = '#000000';
try {
    $today_cel = new DateTimeImmutable('now');
    $cel_month = (int)$today_cel->format('m');
    $cel_day   = (int)$today_cel->format('d');
    $celebration_stmt = query(
        'SELECT id, text, text_color, background_color FROM celebrations WHERE is_active = 1 AND MONTH(display_date) = ? AND DAY(display_date) = ? ORDER BY created_at DESC LIMIT 1',
        [$cel_month, $cel_day]
    );
    $celebration = $celebration_stmt->fetch();
    if ($celebration) {
        $celebration_text_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $celebration['text_color']) ? $celebration['text_color'] : '#000000';
        $celebration_bg_color   = preg_match('/^#[0-9A-Fa-f]{6}$/', $celebration['background_color']) ? $celebration['background_color'] : '#ffffff';
        $celebration_banner_html  = '<div class="header-content celebration-banner-outer">';
        $celebration_banner_html .= '<div class="celebration-banner-content">';
        $celebration_banner_html .= nl2br(htmlspecialchars($celebration['text'], ENT_QUOTES, 'UTF-8'));
        $celebration_banner_html .= '</div>';
        $celebration_banner_html .= '</div>';
    }
} catch (Exception $e) {
    error_log('celebration banner query failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="strict-origin-when-cross-origin">

    <?php
    // Generate page-specific meta information for SEO where possible
    // When requests go through dispatch.php, PHP_SELF is always 'dispatch.php'.
    // $__dispatch_file is set by dispatch.php to the absolute path of the actual
    // handler (e.g. /…/post.php) and is still in scope when header.php is included.
    $script = basename($__dispatch_file ?? $_SERVER['PHP_SELF'] ?? '');
    if ($script === 'post.php' && !empty($_GET['id'])) {
        try {
            $tmp_post = get_post((int)$_GET['id']);
            if (!empty($tmp_post)) {
                $raw_content = $tmp_post['content'];
                if (preg_match('/<img[^>]+src=["\'](https?:\/\/[^"\']+)["\']/', $raw_content, $m)) {
                    $META_IMAGE = $m[1];
                }
                $snippet = strip_tags($raw_content);
                $snippet = preg_replace('/\s+/', ' ', trim($snippet));
                $title_snippet = mb_substr($snippet, 0, 80);
                $META_TITLE = '@' . $tmp_post['username'] . ' — ' . ($title_snippet ?: SITE_NAME);
                $META_DESCRIPTION = $snippet;
            }
        } catch (Exception $e) {
            // ignore DB errors and fall back to defaults
        }
    } elseif ($script === 'group_post.php' && !empty($_GET['id'])) {
        try {
            // Attempt to include group post snippet for SEO (if available)
            require_once __DIR__ . '/db.php';
            $pdo = db_connect();
            $stmt = $pdo->prepare("SELECT gp.id, gp.content, g.slug, COALESCE(g.is_private, 0) AS is_private FROM group_posts gp JOIN groups_table g ON gp.group_id = g.id WHERE gp.id = ? LIMIT 1");
            $stmt->execute([ (int)$_GET['id'] ]);
            if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Never leak content of private group posts into public meta tags
                if (empty($r['is_private'])) {
                    $raw_content = $r['content'];
                    if (preg_match('/<img[^>]+src=["\'](https?:\/\/[^"\']+)["\']/', $raw_content, $m)) {
                        $META_IMAGE = $m[1];
                    }
                    $snippet = strip_tags($raw_content);
                    $snippet = preg_replace('/\s+/', ' ', trim($snippet));
                    $META_TITLE = 'Topluluk ' . $r['slug'] . ' — ' . mb_substr($snippet, 0, 80);
                    $META_DESCRIPTION = $snippet;
                }
            }
        } catch (Exception $e) {
            // ignore
        }
    }
    ?>

    <?php $default_title = SITE_NAME . ' - Rahat Sosyal Medya Platformu'; ?>
    <title><?= htmlspecialchars($META_TITLE ?? $default_title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/logo-green.svg">
    <link rel="shortcut icon" href="<?= BASE_PATH ?>/assets/logo-green.svg">
    <link rel="apple-touch-icon" href="<?= BASE_PATH ?>/assets/logo-green.svg">
    <?php if (!empty($META_DESCRIPTION)): ?>
        <meta name="description" content="<?= htmlspecialchars(mb_substr($META_DESCRIPTION, 0, 160), ENT_QUOTES, 'UTF-8') ?>">
        <meta property="og:description" content="<?= htmlspecialchars(mb_substr($META_DESCRIPTION, 0, 160), ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <?php if (!empty($META_TITLE)): ?>
        <meta property="og:title" content="<?= htmlspecialchars($META_TITLE, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <?php if (!empty($CANONICAL_URL)): ?>
        <meta property="og:url" content="<?= htmlspecialchars($CANONICAL_URL, ENT_QUOTES, 'UTF-8') ?>">
        <meta property="og:type" content="article">
    <?php endif; ?>
    <meta property="og:site_name" content="<?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?>">
    <?php
    // Fall back to site-wide default OG image if no post-specific image was found
    if (empty($META_IMAGE) && file_exists(__DIR__ . '/../assets/og-image.png')) {
        $META_IMAGE = rtrim(SITE_URL, '/') . rtrim(BASE_PATH, '/') . '/assets/og-image.png';
    }
    ?>
    <?php if (!empty($META_IMAGE)): ?>
        <meta property="og:image" content="<?= htmlspecialchars($META_IMAGE, ENT_QUOTES, 'UTF-8') ?>">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="<?= htmlspecialchars($META_IMAGE, ENT_QUOTES, 'UTF-8') ?>">
    <?php else: ?>
        <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <?php if (!empty($META_TITLE)): ?>
        <meta name="twitter:title" content="<?= htmlspecialchars($META_TITLE, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <?php if (!empty($META_DESCRIPTION)): ?>
        <meta name="twitter:description" content="<?= htmlspecialchars(mb_substr($META_DESCRIPTION, 0, 200), ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <?php
    // Cache-bust CSS by appending file modification times so updated styles load immediately after deploy
    $main_css_path = __DIR__ . '/../assets/css/main.css';
    $profile_css_path = __DIR__ . '/../assets/css/profile.css';
    $main_css_ver = file_exists($main_css_path) ? filemtime($main_css_path) : time();
    $profile_css_ver = file_exists($profile_css_path) ? filemtime($profile_css_path) : time();
    ?>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css?v=<?= $main_css_ver ?>">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/icons.css?v=<?= file_exists(__DIR__ . '/../assets/css/icons.css') ? filemtime(__DIR__ . '/../assets/css/icons.css') : time() ?>">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/profile.css?v=<?= $profile_css_ver ?>">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/post_comment.css?v=<?= filemtime(__DIR__ . '/../assets/css/post_comment.css') ?>">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/group_comment.css?v=<?= filemtime(__DIR__ . '/../assets/css/group_comment.css') ?>">

    <!-- allow individual pages to add extra <head> markup (styles, scripts) -->
    <?php if (!empty($extra_head)) { echo $extra_head; } ?>

    <style nonce="<?= htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8') ?>">
    /* Accessibility helpers: skip link and screen-reader text */
    .skip-link{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden}
    .skip-link:focus{position:static;left:0;top:0;width:auto;height:auto;padding:8px 12px;background:#fff59d;color:#000;z-index:9999}
    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
    <?php if (!empty($celebration_banner_html)): ?>.celebration-banner-content{background:<?= $celebration_bg_color ?>;color:<?= $celebration_text_color ?>;}<?php endif; ?>
    </style>

    <!-- Site-wide feeds -->
    <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars(SITE_NAME . ' - RSS', ENT_QUOTES, 'UTF-8') ?>" href="<?= BASE_PATH ?>/rss.xml">
    <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars(SITE_NAME . ' - RSS (xml)', ENT_QUOTES, 'UTF-8') ?>" href="<?= BASE_PATH ?>/rss.xml">
    <link rel="alternate" type="application/atom+xml" title="<?= htmlspecialchars(SITE_NAME . ' - Atom', ENT_QUOTES, 'UTF-8') ?>" href="<?= BASE_PATH ?>/atom.xml">

    <?php
    // Page-specific feed discovery (so readers can auto-discover user/group feeds)
    // Use REQUEST_URI instead of PHP_SELF — all requests go through dispatch.php (front controller)
    $_feed_path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
    if (preg_match('#^/(?:g|t)/([^/]+)$#', $_feed_path, $_feed_m)) {
        // Group page: /g/{slug} or /t/{slug}
        $gslug = $_feed_m[1];
        ?>
        <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($gslug . ' — Group RSS', ENT_QUOTES, 'UTF-8') ?>" href="<?= BASE_PATH ?>/g/<?= rawurlencode($gslug) ?>/rss.xml">
        <?php
    } elseif (!empty($_GET['username']) || preg_match('#^/([^/]+)$#', $_feed_path, $_feed_m)) {
        // Profile page
        $profile_username_for_feed = $_GET['username'] ?? $_feed_m[1] ?? null;
        if (!empty($profile_username_for_feed)) {
            ?>
            <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($profile_username_for_feed . ' — RSS', ENT_QUOTES, 'UTF-8') ?>" href="<?= BASE_PATH ?>/user/<?= rawurlencode($profile_username_for_feed) ?>/rss.xml">
            <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($profile_username_for_feed . ' — RSS (short)', ENT_QUOTES, 'UTF-8') ?>" href="<?= BASE_PATH ?>/<?= rawurlencode($profile_username_for_feed) ?>/rss.xml">
            <?php
        }
    }
    unset($_feed_path, $_feed_m);
    ?>

    <?php if (!empty($CANONICAL_URL)): ?>
        <link rel="canonical" href="<?= htmlspecialchars($CANONICAL_URL, ENT_QUOTES, 'UTF-8') ?>">
    <?php elseif (function_exists('canonical_url')): ?>
        <link rel="canonical" href="<?= htmlspecialchars(canonical_url(), ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <!-- Noscript defensive styles: applied only when JS is disabled in client -->
    <noscript>
        <style>
            /* Wider centered layout when JS is disabled */
            .main-container { display: grid; grid-template-columns: 160px minmax(560px, 1fr) 160px; gap: 24px; max-width: none; width:100%; }
            /* Slightly narrower sidebars to give the center more room */
            .sidebar-left, .sidebar-right { display: block; }
            /* Remove the 1px borders that normally appear on sidebars when JS is off */
            .sidebar { border: none !important; }
            /* Let content area take remaining space and be flexible */
            .content-area { width: 100%; /* remove restrictive min-width for small screens */ }
            .header-container { background: #fff; border-bottom: 3px solid #5a9a3c; }
            .menu-links a, .header-nav a { color: #666; }
            /* eliminate excessive horizontal gutter that looks like a border */
            .main-container { gap: 12px; }
        </style>
    </noscript>
</head>
<?php
$body_classes = [];
if (!empty($is_in_app)) $body_classes[] = 'in-app';
// Add a page-specific class so page-level CSS can target it
$script_name = basename($_SERVER['SCRIPT_NAME'] ?? '');
$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

if ($script_name === 'index.php' || $request_path === '/anasayfa' || $request_path === '/anasayfa/') {
    $body_classes[] = 'page-index';
}
if ($script_name === 'landing.php') $body_classes[] = 'page-landing';
if ($script_name === 'events.php') $body_classes[] = 'page-events';
if ($script_name === 'event_view.php') $body_classes[] = 'page-event-view';

// allow pages to supply additional classes via $extra_body_classes
if (!empty($extra_body_classes)) {
    if (is_array($extra_body_classes)) {
        $body_classes = array_merge($body_classes, $extra_body_classes);
    } else {
        $body_classes[] = $extra_body_classes;
    }
}

$body_attr = $body_classes ? ' class="' . implode(' ', $body_classes) . '"' : '';
?>
<body<?= $body_attr ?>>
<?php if (!empty($_GET['debug_header'])): ?>
    <!-- SCRIPT_NAME: <?= htmlspecialchars($script_name, ENT_QUOTES) ?> REQUEST_URI: <?= htmlspecialchars($request_path, ENT_QUOTES) ?> -->
<?php endif; ?>
    <!-- Header -->
    <?php
    $header_cta_html = '';
    if ($is_mobile || $is_in_app) {
        if ($is_in_app) {
            $sid_param = $CURRENT_SID ? '&sid=' . urlencode($CURRENT_SID) : '';
            $premium_href = 'myapp://open?path=premium' . $sid_param;
        } else {
            $premium_href = BASE_PATH . '/premium.php';
        }

        $premium_myapp = 'myapp://open?path=premium' . ($CURRENT_SID ? '&sid=' . urlencode($CURRENT_SID) : '');
        $header_cta_html .= '<a href="' . htmlspecialchars($premium_href, ENT_QUOTES, 'UTF-8') . '" data-myapp="' . htmlspecialchars($premium_myapp, ENT_QUOTES, 'UTF-8') . '" class="premium-btn" aria-label="Become a premium member">Premium</a>';
    }
    ?>

    <header class="header-container">
        <div class="header-content">
            <input type="checkbox" id="mobile-menu-toggle" class="mobile-menu-toggle" aria-hidden="true">
                <?php $logo_path = __DIR__ . '/../assets/logo-green.svg'; $logo_ver = (file_exists($logo_path) ? filemtime($logo_path) : time()); ?>
                <div class="logo-with-cta">
                    <a href="<?= BASE_PATH ?>/" class="landing-logo logo">
                        <img src="<?= BASE_PATH ?>/assets/logo-green.svg?v=<?= $logo_ver ?>" alt="logo" class="site-logo">
                        <span class="logo-text">
                            <span class="site-name"><?= SITE_NAME ?></span>
                            <span class="logo-version">deneme sürüm 1.03</span>
                        </span>
                    </a>
                </div>


            <div class="landing-header-center header-search">
                <form action="<?= BASE_PATH ?><?= search_url() ?>" method="GET" class="landing-search-form form-inline">
                    <input type="text" name="q" class="search-bar" placeholder="ara..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                </form>
                <label for="mobile-menu-toggle" class="mobile-menu-button" aria-label="Mobil menüyü aç veya kapat">
                    <span class="mobile-menu-icon">☰</span>
                </label>
            </div>

            <nav class="header-nav">
                <?php if (!empty($header_cta_html)): ?>
                    <div class="landing-header-right header-cta"><?= $header_cta_html ?></div>
                <?php endif; ?>

                <div class="menu-links">
                <?php if ($current_user_id):
                    $unread_notif_count = get_unread_count($current_user_id);
                    $home_link = BASE_PATH . (use_clean_urls() ? '/anasayfa' : '/index.php');
                    $groups_link = BASE_PATH . (use_clean_urls() ? '/topluluklar' : '/groups.php');
                    $notif_link = BASE_PATH . (use_clean_urls() ? '/bildirimler' : '/notification.php');
                    $events_link = BASE_PATH . (use_clean_urls() ? '/etkinlikler' : '/events.php');
                    $favorites_link = function_exists('favorites_url') ? favorites_url() : BASE_PATH . (use_clean_urls() ? '/favoriler' : '/favorites.php');
                    $logout_link = BASE_PATH . (use_clean_urls() ? '/cikis' : '/logout.php');
                ?>
                    <a href="<?= $home_link ?>" class="mobile-menu-link mobile-only"><span class="menu-icon icon-home" aria-hidden="true"></span><span class="menu-label">Ana Sayfa</span></a>
                    <a href="<?= $groups_link ?>" class="mobile-menu-link mobile-only"><span class="menu-icon icon-users" aria-hidden="true"></span><span class="menu-label">Topluluklar</span></a>
                    <a href="<?= $notif_link ?>" class="mobile-menu-link mobile-only"><span class="menu-icon icon-bell" aria-hidden="true"></span><span class="menu-label">Bildirimler</span><?php if ($unread_notif_count > 0): ?><span class="header-notif-badge"><?= $unread_notif_count ?></span><?php endif; ?></a>
                    <a href="<?= $events_link ?>" class="mobile-menu-link mobile-only"><span class="menu-icon icon-calendar" aria-hidden="true"></span><span class="menu-label">Etkinlikler</span></a>
                    <a href="<?= $favorites_link ?>" class="mobile-menu-link mobile-only"><span class="menu-icon icon-star" aria-hidden="true"></span><span class="menu-label">Favoriler</span></a>
                    <?php if (is_admin() || admin_has_perm(null, 'view_admin_dashboard')): ?>
                        <a href="<?= BASE_PATH ?>/admin/" class="admin-link mobile-menu-link"><span class="menu-icon icon-settings" aria-hidden="true"></span><span class="menu-label">Yönetim</span></a>
                    <?php endif; ?>

                    <div class="user-menu">
                        <?php
                    $current_username = is_array($current_user) && !empty($current_user['username']) ? $current_user['username'] : '';
                    if ($current_username):
                    ?>
                        <a href="<?= user_url($current_username) ?>" class="header-username">@<?= htmlspecialchars($current_username) ?></a>
                    <?php endif; ?>
                        <a href="<?= $logout_link ?>">Çıkış</a>
                    </div>
                <?php elseif (empty($hide_header_auth_links)): ?>
                    <a href="<?= BASE_PATH . (use_clean_urls() ? '/giris' : '/login.php') ?>">Giriş Yap</a>
                    <a href="<?= BASE_PATH . (use_clean_urls() ? '/kayit' : '/register.php') ?>">Kayıt Ol</a>
                <?php endif; ?>
                </div>

                    <?php
                    // Show rookie/awaiting-approval banner only for true rookies awaiting approval
                    if (!empty($current_user)) {
                        $is_rookie_role = (!empty($current_user['role']) && $current_user['role'] === 'rookie');
                        $has_rookie_badge = false;
                        try {
                            $ub = get_user_badges($current_user_id);
                            foreach ($ub as $b) {
                                if (!empty($b['slug']) && $b['slug'] === 'yeni-gelen') {
                                    $has_rookie_badge = true;
                                    break;
                                }
                            }
                        } catch (Exception $_) {
                            $has_rookie_badge = false;
                        }

                        if (!$current_user['is_approved'] && ($is_rookie_role || $has_rookie_badge) && !is_admin()) {
                            // Calculate how many auto-approved top-level posts remain (first N top-level posts auto-approved)
                            $posts_count = 0;
                            try { $posts_count = get_user_top_level_post_count($current_user_id); } catch (Exception $_) { $posts_count = 0; }
                            $remaining = max(0, (int)ROOKIE_AUTO_APPROVE_POST_COUNT - $posts_count);

                            // Prepare HTML but do not echo here so we can render it below the header
                            // use inline styles and an SVG bar to avoid any class-based adblock filtering
                            // build banner entirely inside a single SVG element so text and background cannot be detached
                            $used = min(10, $posts_count);
                            $percent = (int)round(($used / 10) * 100);
                            // debug logging (helps track complaints about stagnant bar)
                            error_log("rookie_banner user={$current_user_id} posts={$posts_count} used={$used} percent={$percent}");
                            $msg = 'Merhaba ' . htmlspecialchars($current_user['username']) . ' — "Yeni Gelen" büyük sahneye çağrılman için kafa ayarı yapılıyor. Mevzuat\'a uygunsan çağrı yakında gelecek. Bu süreçte ilk on mevzuatını tamamla. Onayı beklerken sayfanda yazmaya devam edebilirsin.';
                            // perform basic word wrapping to avoid overflow inside svg
                            // allow more chars per line to reduce truncation
                            $wrapped = wordwrap($msg, 120, "\n", true);
                            $lines = explode("\n", $wrapped);
                            // restrict to at most two lines; append ellipsis if truncated
                            if (count($lines) > 2) {
                                $lines = array_slice($lines, 0, 2);
                                $lines[1] = rtrim($lines[1]) . '...';
                            }
                            $lineCount = count($lines);
                            // adjust vertical placement based on number of lines
                            $barY = 24 + $lineCount * 12; // move bar further down
                            $svgHeight = $barY + 20;
                            // wrap in same container used by header-content so padding/width match
                            $rookie_banner_html = '<div class="header-content rookie-banner" style="padding:6px 12px;">';
                            // use fixed 900‑unit internal width and dynamic height; scales down via CSS
                            $rookie_banner_html .= '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="' . $svgHeight . '" style="width:100%;height:auto;display:block;" viewBox="0 0 900 ' . $svgHeight . '" preserveAspectRatio="xMidYMin meet">';
                            // yellow background
                            $rookie_banner_html .= '<rect width="900" height="' . $svgHeight . '" fill="#fff8e1"/>';
                            // multi-line message using tspans, centered; even larger font
                            $rookie_banner_html .= '<text x="50%" y="20" font-size="14" fill="#5a381e" font-family="sans-serif" text-anchor="middle">';
                            foreach ($lines as $i => $line) {
                                if ($i > 0) {
                                    $rookie_banner_html .= '<tspan x="50%" dy="1.2em">';
                                }
                                $rookie_banner_html .= htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
                                if ($i > 0) {
                                    $rookie_banner_html .= '</tspan>';
                                }
                            }
                            $rookie_banner_html .= '</text>';
                            // progress bar base and fill positioned below text
                            // rounded base and fill
                            $rookie_banner_html .= '<rect x="18" y="' . $barY . '" width="864" height="12" fill="#ccc" rx="6" ry="6"/>';
                            $rookie_banner_html .= '<rect x="18" y="' . $barY . '" width="' . ($percent * 9) . '" height="12" fill="#4caf50" rx="6" ry="6"/>';
                            // percent and fraction labels
                            // fraction at end of bar only
                            $rookie_banner_html .= '<text x="882" y="' . ($barY + 12) . '" font-size="14" fill="#5a381e" text-anchor="end">' . $used . '/10</text>';
                            $rookie_banner_html .= '</svg>';
                            $rookie_banner_html .= '</div>';
                        }
                    }
                    ?>
                </div>
            </nav>
        </div>
    </header>

    <?php
    // Render rookie banner vertically under the menu bar if it was prepared above
    if (!empty($rookie_banner_html)) {
        echo $rookie_banner_html;
    }
    if (!empty($celebration_banner_html)) {
        echo $celebration_banner_html;
    }

    // Global flash area (shows green success or red error messages under the header, above page content)
    if (!empty($_SESSION['flash']) || !empty($_SESSION['flash_error'])) {
        echo '<div class="global-flash-area" aria-live="polite">';
        if (!empty($_SESSION['flash'])) {
            echo '<div class="flash flash-success" role="status" aria-live="polite">' . $_SESSION['flash'] . '</div>';
            unset($_SESSION['flash']);
        }
        if (!empty($_SESSION['flash_error'])) {
            echo '<div class="flash flash-error" role="alert">' . htmlspecialchars($_SESSION['flash_error']) . '</div>';
            unset($_SESSION['flash_error']);
        }
        echo '</div>';
    }
    ?>
