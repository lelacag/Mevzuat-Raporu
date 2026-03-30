<?php /* EN + TR comments used. */

require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$errors = [];

// Handle new post creation
if ($user_id && isset($_GET['action']) && $_GET['action'] === 'new') {
    // Redirect to index to create post or show dedicated form
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_post') {
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
    ?>
    <div class="main-container">
        <div class="content-wrapper">
            <h1 class="section-title">Gonderi Bulunamadi</h1>
            <div class="empty-state">
                <p>Aradiginiz gonderi mevcut degil veya silinmis.</p>
            </div>
        </div>
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

