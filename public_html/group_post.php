<?php
/**
 * Group Post Detail Page - View single group post with comments
 */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$post_id = $_GET['id'] ?? 0;

if (!$post_id) {
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

// Get group post with details
$stmt = query("SELECT gp.*, u.username, u.is_premium, g.slug, g.name as group_name, g.id as group_id, COALESCE(g.is_private, 0) as is_private
               FROM group_posts gp 
               JOIN users u ON gp.user_id = u.id 
               JOIN groups_table g ON gp.group_id = g.id 
               WHERE gp.id = ?", [$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    $_SESSION['flash_error'] = 'Gönderi bulunamadı';
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

$requestedSlug = rawurldecode($_GET['slug'] ?? '');
if ($requestedSlug !== '' && $requestedSlug !== $post['slug']) {
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . BASE_PATH . '/g/' . urlencode($post['slug']) . '/post/' . (int)$post_id);
    exit;
}

$slug = $post['slug'];

// Check if user is member
$is_member = false;
if ($user_id) {
    $stmt = query("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?", [$post['group_id'], $user_id]);
    $is_member = (bool)$stmt->fetch();
}

// Access control: private group posts visible only to members or admins
$can_view = empty($post['is_private']) || $is_member || is_admin();
if (!$can_view) {
    $_SESSION['flash'] = 'Bu gönderi özel bir gruba ait. İçeriği görmek için gruba katılın.';
    header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
    exit;
}

// Handle comment creation
if ($user_id && $is_member && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_comment') {
    require_csrf();
    $content = trim($_POST['content'] ?? '');
    $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    
    if (!empty($content)) {
        // Censor bad words
        $censored = censor_bad_words($content);
        $filtered_content = $censored['clean'];
        
        $stmt = query("INSERT INTO group_post_comments (post_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)", 
                      [$post_id, $user_id, $parent_id, $filtered_content]);

        // Fetch the newly-inserted comment id and use it for redirect/anchor
        try {
            $newCommentId = (int)db_connect()->lastInsertId();
        } catch (Exception $e) {
            $newCommentId = 0;
        }

        // Notifications: comment on group post, and reply to comment
        try {
            // Notify post author (if not self)
            if (!empty($post['user_id']) && $post['user_id'] != $user_id) {
                $text = "Grup gönderinize yorum yapıldı: " . $post['group_name'];
                query("INSERT INTO notifications (user_id, type, text, from_user_id, post_id, created_at) VALUES (?, 'system', ?, ?, ?, NOW())",
                    [$post['user_id'], $text, $user_id, $post_id]);
            }
            // If this is a reply to a comment, notify parent comment author (if not self)
            if (!empty($parent_id)) {
                $stmtPC = query("SELECT user_id FROM group_post_comments WHERE id = ?", [$parent_id]);
                $pc = $stmtPC->fetch(PDO::FETCH_ASSOC);
                if ($pc && (int)$pc['user_id'] !== (int)$user_id) {
                    $text2 = "Yorumunuza cevap geldi: " . $post['group_name'];
                    query("INSERT INTO notifications (user_id, type, text, from_user_id, post_id, created_at) VALUES (?, 'system', ?, ?, ?, NOW())",
                        [$pc['user_id'], $text2, $user_id, $post_id]);
                }
            }
        } catch (Exception $ex) {
            error_log('group comment notification error: ' . $ex->getMessage());
        }
        $_SESSION['flash'] = 'Yorumunuz eklendi';
        // Redirect to the comment anchor if we have the id, otherwise fallback to the post page
        if ($newCommentId) {
            $redirectTo = BASE_PATH . '/group_post.php?id=' . $post_id . '#comment-' . $newCommentId;
            error_log('group_post.php: redirecting to ' . $redirectTo);
            header('Location: ' . $redirectTo);
        } else {
            $fallback = BASE_PATH . '/group_post.php?id=' . $post_id;
            error_log('group_post.php: redirecting to ' . $fallback);
            header('Location: ' . $fallback);
        }
        exit;
    }
}

// ── Comparison view: detect /karsilastirma/ path segments ──
$gp_compare = null;
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (preg_match('#/karsilastirma(?:/(.*))?$#', $reqPath, $cm)) {
    $gp_compare = $cm[1] ?? 'latest';
    if ($gp_compare === '' || $gp_compare === 'son-duzenleme') $gp_compare = 'latest';
}

if ($gp_compare !== null) {
    // Permission: only post owner or admins
    if (!($user_id && ($user_id == $post['user_id'] || is_admin()))) {
        $_SESSION['flash_error'] = 'Karşılaştırma yalnızca gönderi sahibi ve yöneticilere açıktır.';
        header('Location: ' . BASE_PATH . '/g/' . urlencode($slug) . '/post/' . (int)$post_id);
        exit;
    }

    $gp_base_url = BASE_PATH . '/g/' . urlencode($slug) . '/post/' . (int)$post_id;

    if ($gp_compare === 'history') {
        $edits_stmt = query("SELECT id, post_id, editor_id, created_at FROM group_post_edits WHERE post_id = ? ORDER BY created_at DESC LIMIT 100", [$post_id]);
        $gp_edits = $edits_stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="main-container single-column">
            <main class="content-area narrow">
                <div class="card-box padded">
                    <h2>Düzenleme Geçmişi</h2>
                    <div class="muted small">Grup gönderisi #: <?= (int)$post_id ?> — <?= htmlspecialchars($post['username'] ?? '') ?></div>
                    <hr>
                    <?php if (empty($gp_edits)): ?>
                        <div class="muted">Bu gönderi için düzenleme kaydı yok.</div>
                    <?php else: ?>
                        <ol>
                        <?php foreach ($gp_edits as $ge):
                            $ge_editor = !empty($ge['editor_id']) ? get_user((int)$ge['editor_id']) : null;
                            $ge_label = $ge_editor ? ('@' . htmlspecialchars($ge_editor['username'])) : 'Sistem';
                            $ge_link = htmlspecialchars($gp_base_url . '/karsilastirma/' . intval($ge['id']), ENT_QUOTES, 'UTF-8');
                        ?>
                            <li>
                                <a href="<?= $ge_link ?>"><?= htmlspecialchars(format_time($ge['created_at']), ENT_QUOTES, 'UTF-8') ?></a>
                                — <?= $ge_label ?>
                            </li>
                        <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                    <p><a href="<?= htmlspecialchars($gp_base_url) ?>">← Geri</a></p>
                </div>
            </main>
        </div>
        <?php
        require_once __DIR__ . '/includes/footer.php';
        exit;
    }

    // Fetch the edit record
    $gp_edit_row = null;
    if ($gp_compare === 'latest') {
        $stmt = query("SELECT * FROM group_post_edits WHERE post_id = ? ORDER BY created_at DESC LIMIT 1", [$post_id]);
        $gp_edit_row = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (ctype_digit((string)$gp_compare)) {
        $stmt = query("SELECT * FROM group_post_edits WHERE id = ? LIMIT 1", [(int)$gp_compare]);
        $gp_edit_row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($gp_edit_row && $gp_edit_row['post_id'] != $post_id) $gp_edit_row = null;
    }

    if (!$gp_edit_row) {
        $_SESSION['flash_error'] = 'Karşılaştırma kaydı bulunamadı.';
        header('Location: ' . $gp_base_url);
        exit;
    }

    $gp_diff_old = $gp_edit_row['previous_content'] ?? '';
    $gp_diff_new = $gp_edit_row['new_content'] ?? '';
    ?>
    <div class="main-container single-column">
        <main class="content-area narrow">
            <div class="card-box padded">
                <h2>Gönderi farkı — Önce / Sonra</h2>
                <div class="muted small">Grup gönderisi #: <?= (int)$post_id ?> · Düzenlendi: <?= htmlspecialchars($gp_edit_row['created_at'] ?? '') ?></div>
                <hr>
                <div class="compare-actions" style="margin-bottom:12px">
                    <a href="<?= htmlspecialchars($gp_base_url . '/karsilastirma/history') ?>">Geçmiş</a>
                </div>
                <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/diff.css">
                <style nonce="<?= $csp_nonce ?>">
                .diff-columns{display:flex;gap:18px}
                .diff-col{flex:1}
                .diff-body{white-space:pre-wrap;line-height:1.5}
                .diff-added{background:#c8f7c5;padding:0 2px;border-radius:2px}
                .diff-removed{background:#ffd7d7;text-decoration:line-through;color:#7a3b3b;padding:0 2px;border-radius:2px}
                .diff-col h3{margin-top:0;font-size:1rem}
                </style>
                <div class="diff-columns">
                    <div class="diff-col diff-old">
                        <h3>Önce</h3>
                        <div class="diff-body"><?= render_diff_old_html($gp_diff_old, $gp_diff_new) ?></div>
                    </div>
                    <div class="diff-col diff-new">
                        <h3>Sonra</h3>
                        <div class="diff-body"><?= render_diff_new_html($gp_diff_old, $gp_diff_new) ?></div>
                    </div>
                </div>
                <p><a href="<?= htmlspecialchars($gp_base_url) ?>">← Geri</a></p>
            </div>
        </main>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Get like status
$user_liked = false;
$like_count = 0;
$stmt = query("SELECT COUNT(*) as cnt FROM group_post_likes WHERE post_id = ?", [$post_id]);
$like_count = $stmt->fetch()['cnt'];
if ($user_id) {
    $stmt = query("SELECT id FROM group_post_likes WHERE post_id = ? AND user_id = ?", [$post_id, $user_id]);
    $user_liked = (bool)$stmt->fetch();
}

// Get threaded comments using the new function
$comments = get_group_comments($post_id, $user_id);
$total_comments = count_group_comments($post_id);
?>

<div class="main-container groups-layout">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Navigasyon</div>
            <ul class="sidebar-list">
                <li><a href="<?= BASE_PATH ?>/g/<?= urlencode($slug) ?>">← <?= htmlspecialchars($post['group_name']) ?></a></li>
                <li><a href="<?= BASE_PATH ?>/topluluklar">Tüm Topluluklar</a></li>
            </ul>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="content-area form-centered">
        <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-success">
            <?= $_SESSION['flash'] ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <!-- Main Post -->
        <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/post-card.css?v=1">
        <article class="post-card post-card-legacy">
            <div class="post-card-header">
                <div class="post-card-meta">
                    <div class="post-card-meta-row">
                        <a href="<?= profile_url($post['username']) ?>" class="post-card-username">
                            <?= htmlspecialchars($post['username']) ?>
                        </a>
                        <span class="muted small">· <a href="<?= BASE_PATH ?>/g/<?= urlencode($slug) ?>"><?= htmlspecialchars($post['group_name']) ?></a></span>
                        <?php
                            $poster = get_user($post['user_id']);
                            $_show_rookie = $poster && empty($poster['is_approved']) && (
                                (isset($poster['role']) && $poster['role'] === 'rookie')
                                || (function_exists('get_user_badges') && (function() use ($post) {
                                    foreach (get_user_badges($post['user_id']) as $_ub) {
                                        if (($_ub['slug'] ?? '') === 'yeni-gelen') return true;
                                    }
                                    return false;
                                })())
                            );
                            if ($_show_rookie): ?>
                            <span class="badge badge-rookie">Yeni Gelen</span>
                        <?php endif; ?>
                        <?php
                            $_tier = (!empty($poster['is_approved']) && function_exists('get_user_best_badge')) ? get_user_best_badge($post['user_id']) : null;
                            if ($_tier): ?>
                            <span class="badge badge-tier"><?= htmlspecialchars($_tier['name']) ?></span>
                        <?php endif; ?>
                        <?php
                            $pb = get_user_custom_badge($post['user_id'] ?? null);
                            if ($pb && !empty($pb['badge_text'])):
                                $cls = badge_color_to_class($pb['badge_color']);
                        ?>
                            <span class="custom-badge custom-badge-<?= $cls ?>"><?= htmlspecialchars($pb['badge_text']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($post['is_premium']) || !empty($poster['is_premium']) || (function_exists('is_user_premium') && is_user_premium($post['user_id'] ?? 0))): ?>
                            <span class="premium-star">⭐</span>
                        <?php endif; ?>
                    </div>
                    <div class="post-card-time">
                        <span class="post-card-sent">Gönderildi: <?= htmlspecialchars(format_time($post['created_at'])) ?></span>
                        <?php
                            $gp_updated_ts = !empty($post['updated_at']) ? strtotime($post['updated_at']) : 0;
                            $gp_created_ts = !empty($post['created_at']) ? strtotime($post['created_at']) : 0;
                            if ($gp_updated_ts && $gp_created_ts && ($gp_updated_ts - $gp_created_ts) > 2):
                                $gp_edit_label = 'Düzenlendi: ' . htmlspecialchars(format_time($post['updated_at']), ENT_QUOTES, 'UTF-8');
                                $gp_is_owner = ($user_id && $user_id == $post['user_id']);
                                $gp_is_admin = function_exists('is_admin') && is_admin();
                                if ($gp_is_owner || $gp_is_admin):
                                    $gp_compare_link = htmlspecialchars(BASE_PATH . '/g/' . urlencode($slug) . '/post/' . (int)$post['id'] . '/karsilastirma/son-duzenleme', ENT_QUOTES, 'UTF-8');
                        ?>
                                <a class="post-card-edited" href="<?= $gp_compare_link ?>"><?= $gp_edit_label ?></a>
                        <?php   else: ?>
                                <span class="post-card-edited"><?= $gp_edit_label ?></span>
                        <?php   endif;
                            endif;
                        ?>
                    </div>
                </div>
            </div>

            <div class="post-card-content">
                <?= render_rich_text($post['content']) ?>
            </div>

            <?php $post_poll = get_poll_for_group_post($post['id']); if (!empty($post_poll)): ?>
                <?php $poll = $post_poll; require __DIR__ . '/templates/poll-block.php'; ?>
            <?php endif; ?>

            <?php $post_test = get_test_for_group_post($post['id']); if (!empty($post_test)): ?>
                <?php $test = $post_test; require __DIR__ . '/templates/test-block.php'; ?>
            <?php endif; ?>

            <?php
            preg_match_all('/#([\p{L}\p{N}_-]+)/u', $post['content'], $matches);
            if (!empty($matches[1])):
            ?>
            <div class="post-card-tags">
                <?php foreach (array_unique($matches[1]) as $tag): ?>
                    <a href="<?= search_url() ?>?tag=<?= urlencode($tag) ?>" class="post-tag-link">
                        #<?= htmlspecialchars($tag) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="post-card-actions">
                <?php if ($user_id): ?>
                    <form method="POST" action="<?= BASE_PATH ?>/api/group_post_like.php" class="action-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                        <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                        <button type="submit" class="post-action like-btn <?= $user_liked ? 'liked' : '' ?>">
                            <?= $user_liked ? '♥' : '♡' ?> <?= $like_count ?> Beğen
                        </button>
                    </form>
                    <span class="post-action">💬 <?= count($comments) ?> Yorum</span>
                    <?php if ($user_id == $post['user_id']): ?>
                        <a href="<?= htmlspecialchars(BASE_PATH . '/g/' . urlencode($slug) . '/post/' . (int)$post['id'] . '/edit') ?>" class="post-action edit-btn">
                            ✏️ Düzenle
                        </a>
                        <a href="<?= htmlspecialchars(BASE_PATH . '/g/' . urlencode($slug) . '/post/' . (int)$post['id'] . '/sil') ?>" class="post-action delete-btn">
                            🗑️ Sil
                        </a>
                    <?php else: ?>
                        <form method="POST" action="<?= BASE_PATH ?>/api/report.php" class="action-form">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="target_type" value="group_post">
                            <input type="hidden" name="target_id" value="<?= $post['id'] ?>">
                            <input type="hidden" name="reason" value="spam">
                            <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                            <button type="submit" class="post-action report-btn">
                                ⚠️ Bildir
                            </button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="<?= BASE_PATH ?>/giris" class="post-action">♡ <?= $like_count ?> Beğen</a>
                    <span class="post-action">💬 <?= count($comments) ?> Yorum</span>
                    <a href="<?= BASE_PATH ?>/giris" class="post-action">⚠️ Bildir</a>
                <?php endif; ?>
                <span class="post-card-icon">#<?= $post['id'] ?></span>
            </div>
        </article>

        <?php require __DIR__ . '/templates/group-comment.php'; ?>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">Gönderi Bilgisi</div>
            <div class="sidebar-note padded">
                <p class="muted mb-8"><strong>Grup:</strong> <a href="<?= BASE_PATH ?>/g/<?= urlencode($slug) ?>"><?= htmlspecialchars($post['group_name']) ?></a></p>
                <p class="muted mb-8"><strong>Yazar:</strong> @<?= htmlspecialchars($post['username']) ?></p>
                <p class="muted mb-8"><strong>Tarih:</strong> <?= format_time($post['created_at']) ?></p>
                <p class="muted mb-8"><strong>Beğeni:</strong> <?= $like_count ?></p>
                <p class="muted"><strong>Yorum:</strong> <?= $total_comments ?></p>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>