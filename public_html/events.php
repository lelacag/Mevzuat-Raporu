<?php /* EN + TR comments used. */
// DEBUG: temporary diagnostics to find why this page renders blank. Remove before deploying.
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Print any fatal/shutdown errors directly to the response for fast diagnosis
register_shutdown_function(function(){
    $err = error_get_last();
    if ($err) {
        echo "<pre style=\"background:#fee;padding:12px;border:1px solid #fdd;\">SHUTDOWN ERROR: " . htmlspecialchars(print_r($err, true), ENT_QUOTES, 'UTF-8') . "</pre>\n";
        // Also mirror to server error log for completeness
        error_log('[events.php SHUTDOWN] ' . ($err['message'] ?? '') . ' in ' . ($err['file'] ?? '') . ' on line ' . ($err['line'] ?? 0));
    }
});

$included = @include_once __DIR__ . '/includes/header.php';
if ($included === false) {
    error_log('events.php: includes/header.php include FAILED');
} else {
    error_log('events.php: includes/header.php included OK');
}

$current_user_id = get_current_user_id();
error_log('events.php: early snapshot current_user_id=' . intval($current_user_id));

// Ensure these are always defined to avoid fatal errors in diagnostic/admin blocks
$events = [];
$page_error = null;
// Log incoming request snapshot to help debug in-app flow
try {
    $req_sid = !empty($_REQUEST['sid']) ? preg_replace('/[^A-Za-z0-9._-]/','', $_REQUEST['sid']) : '';
    // normalized sid variable used across the page to avoid undefined-index notices
    $sid_requested = $req_sid;
    $masked_sid = $req_sid ? (substr($req_sid,0,6) . '...' . substr($req_sid,-6)) : '(none)';
    $ua_short = substr($_SERVER['HTTP_USER_AGENT'] ?? '(none)',0,120);
} catch (Exception $e) {
    // ignore
}

// If we created an inline demo token earlier in this request, expose it to the client
// as a meta tag and small JS so WebViews can persist it and reload with sid parameter.


// If we don't have a sid param but a dev cookie was set, use it as a fallback (dev-only)
if (empty($sid_requested) && !empty($_COOKIE['dev_sid']) && (defined('ENVIRONMENT') && ENVIRONMENT !== 'production')) {
    $cookie_sid = preg_replace('/[^A-Za-z0-9._-]/','', $_COOKIE['dev_sid']);
    if (!empty($cookie_sid)) {
        $sid_requested = $cookie_sid;
    }

    if (!$current_user_id && !empty($sid_requested)) {
        $sid_try = $sid_requested;
        try {
            $uid = validate_compact_stateless_token($sid_try);
            if (!$uid) $uid = validate_stateless_url_token($sid_try);
            if ($uid) {
                // Trust this uid for the request (do not create a full session here)
                $current_user_id = (int)$uid;
                $GLOBALS['url_session_user_id'] = $current_user_id;
            }
        } catch (Exception $e) {
            // ignore validation errors
        }
    }
}

// Development fallback: if running in dev and request comes from our in-app WebView, auto-create a premium test token
// This allows testers to view premium-only pages without rebuilding the app. DO NOT enable in production.
if (defined('IAP_ENABLED') && IAP_ENABLED && defined('ENVIRONMENT') && ENVIRONMENT !== 'production') {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $is_in_app = preg_match('/WebView|wv|myapp|com\.example\.webviewwrapper|CFNetwork|CriOS|FxiOS/i', $ua);
    $already_auto = !empty($_REQUEST['dev_auto']);
    // Trigger: either explicit dev_force=1 param or UA looks like our in-app stub
    $dev_force = !empty($_REQUEST['dev_force']) || $is_in_app;

    // If a sid was provided but no resolved session user exists (possibly expired token), try to read historical owner
    $sid_provided = !empty($_REQUEST['sid']) ? preg_replace('/[^A-Za-z0-9._-]/','', $_REQUEST['sid']) : '';
    $found_owner = null;
    if ($sid_provided && empty($GLOBALS['url_session_user_id'])) {
        try {
            $sid_hash = hash_hmac('sha256', $sid_provided, URL_SESSION_SECRET);
            $raw_hash = hash('sha256', $sid_provided);
            $stmt = query("SELECT user_id, expires_at FROM url_sessions WHERE token_hash = ? OR raw_token_hash = ? LIMIT 1", [$sid_hash, $raw_hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['user_id'])) {
                $found_owner = (int)$row['user_id'];
            }
        } catch (Exception $e) {
            // ignore lookup errors
        }
    }

    // Also trigger auto-create when a sid was provided and it resolved to a non-premium user (covers app cases)
    if (!empty($sid_requested) && !empty($GLOBALS['url_session_user_id']) && !is_user_premium($GLOBALS['url_session_user_id'])) {
        $dev_force = true;
    }

    // If we found a historical owner for an expired sid, prefer that as the target and force dev flow
    if ($found_owner !== null) {
        $dev_force = true;
        $target_uid = $found_owner;
    }

    // If a sid was provided but we could not resolve or find a historical owner, and this looks like an in-app request, default to user=1 and force dev flow
    if ($sid_provided && empty($GLOBALS['url_session_user_id']) && $found_owner === null && ($is_in_app || !empty($_REQUEST['force_dev_for_app']))) {
        $dev_force = true;
        $target_uid = 1;
    }

    // If still unresolved but request comes from a local network address (likely tester device), auto-create for dev convenience
    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($sid_provided && empty($GLOBALS['url_session_user_id']) && $found_owner === null && !$dev_force) {
        if (preg_match('/^(127\.0\.0\.1|192\.168\.|10\.|::1)/', $remote_ip)) {
            $dev_force = true;
            $target_uid = 1;
        }
    }

    // As a last-resort in dev, if a sid was provided and nothing resolved, force-create a token for user 1 so in-app testers can see premium pages without extra steps
    if ($sid_provided && empty($GLOBALS['url_session_user_id']) && $found_owner === null && !$already_auto && !$dev_force) {
        $dev_force = true;
        $target_uid = 1;

        // If target_uid not set above, fall back to resolved uid or user 1
        if (!isset($target_uid)) {
            $target_uid = !empty($GLOBALS['url_session_user_id']) ? (int)$GLOBALS['url_session_user_id'] : 1;
        }
        // If target user is already premium, trust them for this request without creating a token
        if (is_user_premium($target_uid)) {
            $current_user_id = $target_uid;
            $GLOBALS['url_session_user_id'] = $target_uid;
        } elseif (!is_user_premium($target_uid)) {
            try {
                
                // Create a demo token that specifically grants premium without altering the user record
                $new_token = create_url_session($target_uid, 86400, true); // 1 day TTL, grants_premium=1
                if ($new_token) {
                    // dev token created (debug removed).

                    // Apply an inline grant for the current request so the first response renders events immediately
                    $current_user_id = $target_uid;
                    $GLOBALS['url_session_user_id'] = $target_uid;
                    $GLOBALS['url_session_grants_premium'] = 1;
                    $GLOBALS['url_session_used_grant'] = 1;
                    $GLOBALS['url_session_inline_token'] = $new_token;

                    $masked = substr($new_token,0,6) . '...' . substr($new_token,-6);
                    // inline grant created for dev-only flows (no debug header emitted)
                    // (dev debug header removed)
                }
        } catch (Exception $e) {
            // ignore
        }
    }
}


// Check if user is logged in or premium; show a consistent premium prompt if not
// If a sid was provided but no user was resolved, show a helpful dev hint for mobile testing
$sid_requested = isset($_REQUEST['sid']) ? preg_replace('/[^A-Za-z0-9._-]/','', $_REQUEST['sid']) : '';
$sid_provided = !empty($sid_requested);
// Additional logging: if we have a resolved url_session user but they're not premium, log details to help debug
if ($sid_provided && !empty($GLOBALS['url_session_user_id']) && !is_user_premium($GLOBALS['url_session_user_id'])) {
    $masked = substr($sid_requested, 0, 6) . '...' . substr($sid_requested, -6);
    // sid resolved to non-premium (debug removed).
}

// Apply token-level demo grants (dev-only): if the URL session granted premium, respect it for this request
try {
    if (!empty($GLOBALS['url_session_grants_premium']) && !empty($GLOBALS['url_session_user_id'])) {
        $sess_uid = (int)$GLOBALS['url_session_user_id'];
        if (empty($current_user_id)) {
            $current_user_id = $sess_uid;
            // applied url_session_grants_premium to current_user (debug removed).
        }
        // Mark that we used token-level grant for this request
        $GLOBALS['url_session_used_grant'] = 1;
    }
} catch (Exception $e) {
    // grants application error (debug removed).
}

// Final debug snapshot before access check — all HUD/debug output removed
try {
    $dbg_cur = $current_user_id ? (int)$current_user_id : 0;
    $dbg_url_uid = !empty($GLOBALS['url_session_user_id']) ? (int)$GLOBALS['url_session_user_id'] : 0;
    $dbg_is_premium_cur = $dbg_cur ? (is_user_premium($dbg_cur) ? 1 : 0) : 0;
    $dbg_is_premium_url = $dbg_url_uid ? (is_user_premium($dbg_url_uid) ? 1 : 0) : 0;
    $dbg_grant = !empty($GLOBALS['url_session_grants_premium']) ? 1 : 0;
    $dbg_used = !empty($GLOBALS['url_session_used_grant']) ? 1 : 0;
} catch (Exception $e) {
    // ignore
}
}

$has_premium = false;
// Robust premium detection (defensive): prefer direct DB user flag + premium_until, then fall back to helper checks
if ($current_user_id) {
    $u = null;
    try { $u = get_user($current_user_id); } catch (Exception $e) { /* ignore */ }

    if ($u) {
        // treat DB role 'admin' as privileged regardless of helper flags
        $is_role_admin = !empty($u['role']) && $u['role'] === 'admin';
        if (!empty($u['is_premium']) || (!empty($u['premium_until']) && strtotime($u['premium_until']) > time()) || $is_role_admin || is_admin()) {
            $has_premium = true;
        }
    }

    // fallback to existing helper in case get_user didn't return expected structure
    if (!$has_premium && (is_user_premium($current_user_id) || is_admin())) {
        $has_premium = true;
    }

    // token-level grants (URL sessions) still permitted
    if (!empty($GLOBALS['url_session_grants_premium']) && !empty($GLOBALS['url_session_user_id']) && $GLOBALS['url_session_user_id'] == $current_user_id) $has_premium = true;
}

// Defensive override/logging: if helper says premium but detection failed, force it and log
if ($current_user_id && is_user_premium($current_user_id) && !$has_premium) {
    error_log('events.php: premium detection mismatch for user ' . intval($current_user_id) . ' — forcing has_premium=1');
    $has_premium = true;
}



if ($current_user_id && !$has_premium) {
    // Log why a signed-in user isn't being treated as premium so we can diagnose blank pages
    try {
        error_log('events.php: user ' . intval($current_user_id) . ' premium=' . (is_user_premium($current_user_id) ? '1' : '0') . ' admin=' . (is_admin() ? '1' : '0'));
    } catch (Exception $e) {
        // ignore
    }
}

if (!$current_user_id || !$has_premium) {
    // Log why we're serving the premium prompt for this request
    try {
        $log_cur = $current_user_id ? (int)$current_user_id : 0;
        $log_is_p = $log_cur ? (is_user_premium($log_cur) ? 1 : 0) : 0;
        $log_url_uid = !empty($GLOBALS['url_session_user_id']) ? (int)$GLOBALS['url_session_user_id'] : 0;
    } catch (Exception $e) {
        // SERVE_PROMPT logging error (debug removed).
    }

    $events_css_path = __DIR__ . '/assets/css/events.css';
    $events_ver = file_exists($events_css_path) ? filemtime($events_css_path) : time();
    ?>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/events.css?v=<?= $events_ver ?>">
    <div class="main-container single-column page-events">
        <main class="content-area form-centered">
            <div class="events-container">
                <header class="events-header">
                    <h1 class="section-title">📅 Etkinlik Güncellemeleri</h1>
                </header>
                <div class="premium-prompt">
                    <h2>⭐ Premium Özellik</h2>
                    <p>Etkinlik güncellemelerini görmek için premium üye olmalısınız!</p>
                    <a href="<?= premium_url() ?>">Premium'a Geçin</a>
                </div>
            </div>
        </main>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Admin diagnostics are logged to error_log for debugging; visible admin HUD removed.



// Get active events (with diagnostics)
try {
    $db = db_connect();
    // Use NULL AS creator_event_code until DB migration is applied (prevents SQL errors)
    $stmt = $db->query("SELECT e.*, u.username as creator_username, COALESCE(u.event_code, '') AS creator_event_code
        FROM events e
        LEFT JOIN users u ON e.created_by = u.id
        WHERE e.is_active = 1
        ORDER BY e.event_date ASC");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('events.php DB error for user ' . intval($current_user_id) . ': ' . $e->getMessage());
    $events = [];
    $page_error = 'Etkinlikler yüklenirken dahili bir hata oluştu. Sunucu günlüklerini kontrol edin.';
}

// Separate upcoming and past events
$now = new DateTime();
$upcoming = [];
$past = [];

foreach ($events as $event) {
    $event_date = new DateTime($event['event_date']);
    if ($event_date >= $now) {
        $upcoming[] = $event;
    } else {
        $past[] = $event;
    }
}

// If `id` is passed, route to the dedicated detail page
if (!empty($_GET['id'])) {
    header('Location: ' . event_view_url(intval($_GET['id'])));
    exit;
}





?>

<?php $premium_css_path = __DIR__ . '/assets/css/premium.css'; $events_css_path = __DIR__ . '/assets/css/events.css'; $premium_ver = file_exists($premium_css_path) ? filemtime($premium_css_path) : time(); $events_ver = file_exists($events_css_path) ? filemtime($events_css_path) : time(); ?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/premium.css?v=<?= $premium_ver ?>">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/events.css?v=<?= $events_ver ?>">


<?php
$can_manage_events = function_exists('admin_has_perm') && admin_has_perm(null, 'manage_events');
$add_event_url = BASE_PATH . '/admin/events.php?action=create';
if (!empty($sid_requested)) {
    $add_event_url .= '&sid=' . rawurlencode($sid_requested);
}
?>
<div class="main-container single-column page-events">
    <main class="content-area form-centered">
        <div class="events-container">
            <header class="events-header">
                <div class="events-header-row">
                    <h1 class="section-title">Etkinlikler</h1>
                    <?php if ($can_manage_events): ?>
                        <a class="btn-add-event" href="<?= htmlspecialchars($add_event_url, ENT_QUOTES, 'UTF-8') ?>">+ Etkinlik Ekle</a>
                    <?php endif; ?>
                </div>
            </header>

            <?php if (!empty($page_error)): ?>
                <div class="events-error">
                    <strong>Hata:</strong> <?= htmlspecialchars($page_error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if (count($events) > 0): ?>
                <section class="events-section">
                    <div class="events-grid">
                    <?php foreach (array_merge($upcoming, $past) as $event):
                        $is_past = strtotime($event['event_date']) < time();
                    ?>
                        <article class="event-card<?= $is_past ? ' past' : '' ?>">
                            <div class="event-header">
                                <h2 class="event-title">
                                    <a href="<?= htmlspecialchars(event_view_url($event['id'], $event['title']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></a>
                                </h2>
                                <div class="event-date">📅 <?= date('d.m.Y H:i', strtotime($event['event_date'])) ?></div>
                            </div>
                            <?php if (!empty($event['description'])): ?>
                                <p class="event-description"><?= nl2br(linkify_text($event['description'])) ?></p>
                            <?php endif; ?>
                            <div class="event-meta">
                                Oluşturan: @<?= htmlspecialchars($event['creator_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($event['creator_event_code']) && ($current_user_id === (int)$event['created_by'] || is_admin() || $can_manage_events)): ?>
                                    · <strong>Kod:</strong> <code><?= htmlspecialchars($event['creator_event_code'], ENT_QUOTES, 'UTF-8') ?></code>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    </div>
                </section>
            <?php else: ?>
                <div class="empty-state">
                    <p>Şu anda aktif etkinlik bulunmuyor.</p>
                    <?php if ($can_manage_events): ?>
                        <p><a class="btn-add-event" href="<?= htmlspecialchars($add_event_url, ENT_QUOTES, 'UTF-8') ?>">+ Etkinlik Ekle</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
