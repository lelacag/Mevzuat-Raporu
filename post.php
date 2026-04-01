<?php /* EN + TR comments used. */

require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$errors = [];

// Handle new post creation
if ($user_id && isset($_GET['action']) && $_GET['action'] === 'new') {
    // Redirect to index to create post or show dedicated form
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_post') {
        require_csrf();
        $content = $_POST['content'] ?? '';
        
        if (empty($content)) {
            $errors[] = 'Gönderi içeriği boş olamaz.';
        } else {
            $res = create_post($user_id, $content);
            if (isset($res['error'])) {
                if ($res['error'] === 'suspended') {
                    $errors[] = 'Hesabınız geçici olarak yasaklıdır (süresince: ' . htmlspecialchars($res['until']) . ').';
                } else {
                    $errors[] = 'Gönderi oluşturulamadı.';
                }
            } elseif (isset($res['id'])) {
                if ($res['has_bad_words']) {
                    $_SESSION['flash'] = 'Gönderiniz kötü sözcükler için filtrelendi.';
                } elseif ($res['approved']) {
                    $_SESSION['flash'] = 'Gönderiniz paylaşıldı.';
                } else {
                    $_SESSION['flash'] = 'Gönderiniz onay bekliyor; onaylandıktan sonra görünür.';
                }
                header('Location: ' . BASE_PATH . '/index.php');
                exit;
            } else {
                $errors[] = 'Gönderi oluşturulamadı.';
            }
        }
    }
    
    // Display new post form
    ?>
    <div class="main-container post-new">
        <!-- Left Sidebar -->
        <aside class="sidebar sidebar-left">
            <a href="<?= BASE_PATH ?>/index.php" class="btn btn-primary">← Geri Dön</a>
        </aside>

        <!-- Main Content -->
        <main class="content-area">
            <h1 class="page-title">Yeni Gönderi</h1>
            
            <?php if (!empty($errors)): ?>
                <div class="form-alert form-alert-error">
                    <?php foreach ($errors as $error): ?>
                        <div class="form-alert-item"><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="card-box padded">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="create_post">
                <textarea name="content" placeholder="Ne düşünüyorsun?" required class="post-textarea" autofocus></textarea>
                <div class="post-form-actions">
                    <span class="char-count">500+ karakter (otomatik bölünür)</span>
                    <button type="submit" class="btn-post">Gönder</button>
                </div>
            </form>
        </main>

        <!-- Right Sidebar -->
        <aside class="sidebar sidebar-right">
            <div class="sidebar-help">
                <p class="sidebar-help-title">İpuçları:</p>
                <ul class="sidebar-help-list">
                    <li>Gönderiniz otomatik olarak bölünür</li>
                    <li>Kötü sözcükler filtrelenir</li>
                    <li>#etiket kullanarak etiket ekle</li>
                </ul>
            </div>
        </aside>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Get post ID from URL
$post_id = $_GET['id'] ?? 0;



// Handle new reply
if ($user_id && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_reply') {
    require_csrf();
    $content = $_POST['content'] ?? '';
    $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : (int)$post_id;
    if ($parent_id <= 0) {
        $parent_id = (int)$post_id;
    }

    if (empty($content)) {
        $errors[] = 'Yanit icerigi bos olamaz.';
    } else {
        $parent_post = get_post($parent_id);
        if (!$parent_post) {
            $parent_id = (int)$post_id;
        }

        try {
            $res = create_post($user_id, $content, $parent_id);
        } catch (Exception $e) {
            error_log("ERROR create_reply exception: " . $e->getMessage());
            $errors[] = 'Yanit olusturulamadi.';
            $res = null;
        }

        if (isset($res['error'])) {
            if ($res['error'] === 'suspended') {
                $errors[] = 'Hesabınız geçici olarak yasaklıdır (süresince: ' . htmlspecialchars($res['until']) . ').';
            } else {
                $errors[] = 'Yanit olusturulamadi.';
            }
        } elseif (isset($res['id'])) {
            if ($res['approved']) {
                $_SESSION['flash'] = 'Yanitiniz paylaşıldı.';
            } else {
                $_SESSION['flash'] = 'Yanitiniz onay bekliyor; onaylandıktan sonra görünür.';
            }
            // Redirect to the newly created reply so users land where they replied
            // Only include fragment anchor if the reply was auto-approved and will appear on the page
            $anchor = '';
            if (isset($res['id']) && !empty($res['approved'])) {
                $anchor = '#comment-' . (int)$res['id'];
            }

            // Preserve sid parameter (if any) and always include highlight so client can emphasize the created reply
            $params = [];
            if (!empty($_REQUEST['sid'])) {
                $params[] = 'sid=' . urlencode($_REQUEST['sid']);
            }
            if (isset($res['id'])) {
                $params[] = 'highlight=' . (int)$res['id'];
            }

            $loc = post_url($post_id);
            if (!empty($params)) {
                $loc .= (strpos($loc, '?') === false ? '?' : '&') . implode('&', $params);
            }

            // Log approved state to help debug cases where anchor is intentionally omitted
            error_log('post.php: reply_id=' . (isset($res['id']) ? (int)$res['id'] : 0) . ' approved=' . (!empty($res['approved']) ? '1' : '0') . ' redirect_anchor=' . ($anchor ?: '<none>'));

            $loc .= $anchor;
            header('Location: ' . $loc);
            exit;
        } else {
            $errors[] = 'Yanit olusturulamadi.';
            if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production') {
                // Surface debug information to the user in development so we can triage quickly
            }
        }
    }
}

// Handle like/unlike
if ($user_id && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_like') {
    require_csrf();
    $like_post_id = $_POST['post_id'] ?? 0;
    if ($like_post_id) {
        toggle_like($user_id, $like_post_id);
    }
    // Refresh page
    header('Location: ' . post_url($like_post_id ?: $post_id));
    exit;
}

// Get the main post
$post = get_post($post_id);

if (!$post) {
    // If the post was just deleted via a delete action, prefer redirecting
    // the user back to their timeline or profile with a friendly flash.
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $current_host = ($_SERVER['HTTP_HOST'] ?? '');
    $redirect_target = '';
    if ($referer) {
        $r = parse_url($referer);
        if (!empty($r['host']) && $r['host'] === $current_host && (!isset($r['path']) || $r['path'] !== ($_SERVER['REQUEST_URI'] ?? ''))) {
            // If referer looks like a profile page, keep user there; otherwise send to referer
            $redirect_target = $referer;
        }
    }
    if (empty($redirect_target)) {
        // Fallback: send to timeline
        $redirect_target = BASE_PATH . '/index.php';
    }
    $_SESSION['flash'] = 'Gönderi silindi.';
    header('Location: ' . $redirect_target);
    exit;
}

// If the server routed directly to post.php (without index.php shim), detect
// friendly compare path segments in the request URI and populate $_GET['compare']
// so downstream compare handling works for both '/latest' and '/son-duzenleme'.
if (!isset($_GET['compare'])) {
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (strpos($reqPath, '/karsilastirma/son-duzenleme') !== false) {
        $_GET['compare'] = 'son-duzenleme';
    } elseif (strpos($reqPath, '/karsilastirma/latest') !== false) {
        $_GET['compare'] = 'latest';
    }
}

// Comparison view: if ?compare=latest or ?compare=<edit_id> is provided, render server-side diff
$compare = $_GET['compare'] ?? null;
if ($compare) {
    // special-case: show history list
    if ($compare === 'history') {
        // permission: only owner or admins
        $current_uid = get_current_user_id();
        if (!($current_uid === (int)$post['user_id'] || is_admin())) {
            $_SESSION['flash_error'] = 'Geçmiş yalnızca sahibine ve yöneticilere açıktır.';
            header('Location: ' . post_url($post['id']));
            exit;
        }

        $edits = get_post_edits($post['id'], 1000);
        require_once __DIR__ . '/includes/header.php';
        ?>
        <div class="main-container">
            <main class="content-area narrow">
                <div class="card-box padded">
                    <h2>Düzenleme Geçmişi</h2>
                    <div class="muted small">Gönderi #: <?= (int)$post['id'] ?> — <?= htmlspecialchars($post['username'] ?? '') ?></div>
                    <hr>
                    <?php if (empty($edits)): ?>
                        <div class="muted">Bu gönderi için düzenleme kaydı yok.</div>
                    <?php else: ?>
                        <ol>
                        <?php foreach ($edits as $e):
                            $editor = !empty($e['editor_id']) ? get_user((int)$e['editor_id']) : null;
                            $editor_label = $editor ? ('@' . htmlspecialchars($editor['username'])) : 'Sistem';
                            $compare_link = htmlspecialchars(BASE_PATH . '/post/' . intval($post['id']) . '/karsilastirma/' . intval($e['id']), ENT_QUOTES, 'UTF-8');
                        ?>
                            <li>
                                <a href="<?= $compare_link ?>"><?= htmlspecialchars(format_time($e['created_at']), ENT_QUOTES, 'UTF-8') ?></a>
                                — <?= $editor_label ?>
                            </li>
                        <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                    <?php $compare_home = htmlspecialchars(BASE_PATH . '/post/' . intval($post['id']) . '/karsilastirma/son-duzenleme', ENT_QUOTES, 'UTF-8'); ?>
                    <p><a href="<?= $compare_home ?>">← Geri</a></p>
                </div>
            </main>
        </div>
        <?php
        require_once __DIR__ . '/includes/footer.php';
        exit;
    }
    // Enforce permission: compare pages (latest/specific/history) are only
    // visible to the post owner or admins. History was already guarded above;
    // enforce here for latest/specific compare as well.
    $current_uid = get_current_user_id();
    if (!($current_uid === (int)$post['user_id'] || (function_exists('is_admin') && is_admin()))) {
        $_SESSION['flash_error'] = 'Karsilastirma yalnızca gönderi sahibi ve yöneticilere açıktır.';
        header('Location: ' . post_url($post['id']));
        exit;
    }
    // Only allow users who can view the post (same checks as below)
    $edit_row = null;
    if ($compare === 'latest' || $compare === 'son-duzenleme') {
        $stmt = db_connect()->prepare("SELECT * FROM post_edits WHERE post_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$post['id']]);
        $edit_row = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (ctype_digit((string)$compare)) {
        $edit_row = get_post_edit((int)$compare);
        if ($edit_row && $edit_row['post_id'] != $post['id']) $edit_row = null;
    }

    if (!$edit_row) {
        $_SESSION['flash_error'] = 'Karsilastirma kaydi bulunamadi.';
        header('Location: ' . post_url($post['id']));
        exit;
    }
    // Normalize legacy edit rows: if `previous_content`/`new_content` are empty but
    // `original_content` exists (legacy schema), use that as the "previous" value
    // and the current post content as the "new" value so diffs display.
    $prev_content = $edit_row['previous_content'] ?? '';
    $new_content = $edit_row['new_content'] ?? '';
    if (trim((string)$prev_content) === '' && trim((string)$new_content) === '' && !empty($edit_row['original_content'])) {
        $prev_content = $edit_row['original_content'];
        $new_content = $post['content'] ?? '';
    }
    // Determine a friendly created/edited timestamp for display
    $display_created_at = $edit_row['created_at'] ?? $edit_row['edited_at'] ?? $post['updated_at'] ?? '';

    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="main-container">
        <main class="content-area narrow">
            <div class="card-box padded">
                <h2>Gönderi farkı — Önce / Sonra</h2>
                <div class="muted small">Gönderi #: <?= (int)$post['id'] ?> · Düzenlendi: <?= htmlspecialchars($display_created_at) ?></div>
                <hr>
                <?php $history_link = htmlspecialchars(BASE_PATH . '/post/' . intval($post['id']) . '/karsilastirma/history', ENT_QUOTES, 'UTF-8'); ?>
                <div class="compare-actions" style="margin-bottom:12px">
                    <a href="<?= $history_link ?>">Geçmiş</a>
                </div>
                <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/diff.css">
                <style>
                .diff-columns{display:flex;gap:18px}
                .diff-col{flex:1}
                .diff-body{white-space:pre-wrap;line-height:1.5}
                .diff-added{background:#fff59d;padding:0 2px;border-radius:2px}
                .diff-removed{background:#fff59d;text-decoration:line-through;color:#7a3b3b;padding:0 2px;border-radius:2px}
                .diff-col h3{margin-top:0;font-size:1rem}
                </style>
                <div class="diff-columns">
                    <div class="diff-col diff-old">
                        <h3>Önce</h3>
                        <div class="diff-body"><?= render_diff_old_html($prev_content, $new_content) ?></div>
                    </div>
                    <div class="diff-col diff-new">
                        <h3>Sonra</h3>
                        <div class="diff-body"><?= render_diff_new_html($prev_content, $new_content) ?></div>
                    </div>
                </div>
                <p><a href="<?= post_url($post['id']) ?>">← Geri</a></p>
            </div>
        </main>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// If clean URLs are enabled, ensure the URL includes the username/slug for canonical SEO.
if (use_clean_urls()) {
    $req_username = isset($_GET['username']) ? rawurldecode($_GET['username']) : null;
    error_log('post.php: req_username="' . ($req_username ?? '') . '" post_username="' . ($post['username'] ?? '') . '"');

    $is_canonical = false;
    if ($req_username) {
        $is_canonical = (mb_strtolower(trim($req_username), 'UTF-8') === mb_strtolower(trim($post['username'] ?? ''), 'UTF-8'));
        if (!$is_canonical) {
            $slug_user = get_user_by_slug($req_username);
            if ($slug_user && isset($post['user_id']) && $slug_user['id'] == $post['user_id']) {
                $is_canonical = true;
            }
        }
    }

    if (!$is_canonical) {
        $loc = get_post_url($post['id'], $post['username'] ?? null);
        header('Location: ' . $loc, true, 301);
        exit;
    }
}

$is_liked = $user_id ? is_liked($user_id, $post_id) : false;
$post['user_has_liked'] = $is_liked;
$post['like_count'] = (int)($post['likes_count'] ?? 0);
// Fetch replies for display
$replies = get_replies($post_id, $user_id);
// Optional highlight id passed via ?highlight=<id> so we can visually emphasize newly-created replies
$highlight_id = isset($_GET['highlight']) ? (int)$_GET['highlight'] : 0;
?>

<div class="main-container groups-layout">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= BASE_PATH ?>/index.php">← Ana Sayfa</a></li>
                <li><a href="<?= profile_url($post['username']) ?>">@<?= htmlspecialchars($post['username']) ?> Profili</a></li>
            </ul>
        </div>
    </aside>

    <main class="content-area form-centered">
        <h1 class="section-title">Gonderi</h1>

        <!-- Main Post -->
        <section class="main-post">
            <a href="<?= BASE_PATH ?>/index.php" class="back-link">< Ana Sayfaya Dön</a>
            
            <?php $hide_comment_form = true; $post_id = $post['id']; $username = $post['username']; require __DIR__ . '/templates/post-card.php'; ?>
        </section>
        
        <?php require __DIR__ . '/templates/post-comment.php'; ?>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">Gönderi Bilgisi</div>
            <div class="sidebar-info">
                <p class="meta-row"><strong>Yazar:</strong> @<?= htmlspecialchars($post['username']) ?></p>
                <p class="meta-row"><strong>Tarih:</strong> <?= format_time($post['created_at']) ?></p>
                <p class="meta-row"><strong>Beğeni:</strong> <?= $post['likes_count'] ?? 0 ?></p>
                <p class="meta-row"><strong>Yanıt:</strong> <?= count($replies) ?></p>
                <?php if (!empty($post['poll'])): ?>
                    <?php $p = $post['poll']; $p_slug = $p['slug'] ?? (function_exists('generate_slug') ? (generate_slug($p['title']) . '-' . $p['id']) : ('anket-' . $p['id'])); ?>
                    <p class="meta-row"><strong>Anket:</strong> <a href="<?= htmlspecialchars(BASE_PATH . '/anket/' . rawurlencode($p_slug) . '/' . (int)$p['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($p['title'] ?: 'Anket #' . $p['id']) ?></a></p>
                <?php endif; ?>
                <?php if (!empty($post['test'])): ?>
                    <?php $t = $post['test']; $t_slug = $t['slug'] ?? (function_exists('generate_slug') ? (generate_slug($t['title']) . '-' . $t['id']) : ('tahlil-' . $t['id'])); ?>
                    <p class="meta-row"><strong>Tahlil:</strong> <a href="<?= htmlspecialchars(BASE_PATH . '/tahlil/' . rawurlencode($t_slug) . '/' . (int)$t['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t['title'] ?: 'Tahlil #' . $t['id']) ?></a></p>
                <?php endif; ?>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

