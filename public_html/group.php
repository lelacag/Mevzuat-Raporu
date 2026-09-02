<?php /* EN + TR comments used. */
/**
 * Single Group Page - View group details and posts
 */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$slug = $_GET['slug'] ?? '';

if (!$slug) {
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

if (USE_CLEAN_URLS && !empty($slug) && strpos($_SERVER['REQUEST_URI'], '/group.php') !== false) {
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . group_url($slug));
    exit;
}

// Get group details
$stmt = $pdo->prepare("
    SELECT g.*, u.username as creator_name
    FROM groups_table g
    LEFT JOIN users u ON g.created_by = u.id
    WHERE g.slug = ?
");
$stmt->execute([$slug]);
$group = $stmt->fetch();

if (!$group) {
    // Try canonical slug fallback (normalize unicode/diacritics)
    $canon_slug = generate_slug($slug);
    if ($canon_slug !== $slug) {
        $alt_stmt = $pdo->prepare("SELECT g.*, u.username as creator_name FROM groups_table g LEFT JOIN users u ON g.created_by = u.id WHERE g.slug = ?");
        $alt_stmt->execute([$canon_slug]);
        $alt_group = $alt_stmt->fetch();
        if ($alt_group) {
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: ' . group_url($canon_slug));
            exit;
        }
    }

    // Check if this is a renamed slug — redirect to current slug (301)
    try {
        $histStmt = $pdo->prepare(
            "SELECT gt.slug FROM group_slug_history h
             JOIN groups_table gt ON gt.id = h.group_id
             WHERE h.old_slug = ?
             ORDER BY h.changed_at DESC
             LIMIT 1"
        );
        $histStmt->execute([$slug]);
        $current_slug = $histStmt->fetchColumn();
        if ($current_slug) {
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: ' . group_url($current_slug));
            exit;
        }
    } catch (PDOException $e) {
        // table may not exist yet — fall through
    }

    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

// Check if user is member
$is_member = false;
$user_role = null;
if ($user_id) {
    $stmt = $pdo->prepare("
        SELECT role FROM group_members 
        WHERE group_id = ? AND user_id = ?
    ");
    $stmt->execute([$group['id'], $user_id]);
    $member = $stmt->fetch();
    if ($member) {
        $is_member = true;
        $user_role = $member['role'];
        set_group_viewed_at($user_id, $group['id']);
    }
}

// Handle admin privacy toggle
if ($user_id && $user_role === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_privacy') {
    require_csrf();
    $new_priv = isset($_POST['is_private']) ? 1 : 0;
    $upd = $pdo->prepare("UPDATE groups_table SET is_private = ? WHERE id = ?");
    $upd->execute([$new_priv, $group['id']]);
    $_SESSION['flash'] = $new_priv ? 'Grup özel yapıldı.' : 'Grup herkese açık yapıldı.';
    header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
    exit;
}

// Handle admin save entry question
if ($user_id && $user_role === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_entry_question') {
    require_csrf();
    $q = trim($_POST['entry_question'] ?? '');
    $upd = $pdo->prepare("UPDATE groups_table SET entry_question = ? WHERE id = ?");
    $upd->execute([$q !== '' ? $q : null, $group['id']]);
    $_SESSION['flash'] = 'Giriş sorusu güncellendi.';
    header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
    exit;
}


// Handle tag insert / preview / and post creation for the group (no-JS friendly)
$skip_create = false;
$show_schedule = false;
if ($user_id && $is_member && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    // Schedule mode toggles
    if (isset($_POST['schedule_cancel'])) {
        $show_schedule = false;
        $_POST['scheduled_at'] = '';
    } elseif (isset($_POST['schedule_mode'])) {
        $show_schedule = true;
    }

    // Insert tag button
    if (isset($_POST['insert_tag'])) {
        $draft = $_POST['content'] ?? get_draft($user_id);
        $term = trim($_POST['insert_tag'] ?? 'etiket');
        $tagtext = '#' . ltrim($term, '#');
        $draft = insert_tag_into_text($draft, $tagtext);
        save_draft($user_id, $draft);
        header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
        exit;
    }

    // Insert type helpers (Link/Ekstra/Kod)
    if (isset($_POST['insert_type'])) {
        insert_type_or_append_to_draft($user_id, $_POST['insert_type'], $_POST);
        header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
        exit;
    }

    // Preview request
    if (isset($_POST['preview'])) {
        $preview_content = $_POST['content'] ?? get_draft($user_id);
        if (trim($preview_content) === '') {
            $preview_error = 'Önizlemek için içerik gerekli.';
        } else {
            $group_preview_html = render_rich_text($preview_content);
        }
        save_draft($user_id, $preview_content);
        $skip_create = true;
    }

    if (!$skip_create && isset($_POST['action']) && $_POST['action'] === 'create_post') {
        $content = trim($_POST['content'] ?? '');
        if (trim($content) === '') {
            $content = get_draft($user_id);
        }

        $scheduled_at = trim($_POST['scheduled_at'] ?? '');
        $scheduled_at_sql = null;

        if ($scheduled_at !== '') {
            if (function_exists('schedule_post_validate')) {
                $schedule_validate = schedule_post_validate($user_id, $scheduled_at);
                if (!$schedule_validate['success']) {
                    $skip_create = true;
                    $show_schedule = true;
                    switch ($schedule_validate['error']) {
                        case 'scheduled_at_invalid':
                            $preview_error = 'Geçersiz zamanlanmış tarih/saat.';
                            break;
                        case 'scheduled_at_past':
                            $preview_error = 'Zamanlanmış zaman geçmiş olamaz.';
                            break;
                        case 'scheduled_at_too_far':
                            $preview_error = 'Zamanlanmış zaman çok uzakta (en fazla 1 yıl).';
                            break;
                        default:
                            $preview_error = 'Zamanlama bilgisi geçersiz.';
                    }
                } else {
                    $scheduled_at_sql = $schedule_validate['scheduled_at_sql'];
                }
            }
        }

        if (!$skip_create && !empty($content)) {
            // Enforce same post-length policy as main timeline (premium = unlimited)
            $user_limit = function_exists('get_user_post_limit') ? get_user_post_limit($user_id) : MAX_POST_LENGTH;
            $visible_content = trim(strip_tags(function_exists('render_rich_text') ? render_rich_text($content) : $content));
            if ($user_limit > 0 && mb_strlen($visible_content, 'UTF-8') > $user_limit) {
                $premium_url = BASE_PATH . '/premium.php';
                $preview_error = function_exists('t')
                    ? sprintf(t('post_length_error_premium'), $user_limit, $user_limit, $premium_url)
                    : ('Gönderi ' . $user_limit . ' karakterden uzun olamaz.');
                save_draft($user_id, $content);
                $skip_create = true;
            } else {
                // Censor bad words
                $censored = censor_bad_words($content);
                $filtered_content = $censored['clean'];

                $stmt = $pdo->prepare("
                    INSERT INTO group_posts (group_id, user_id, content, scheduled_at)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$group['id'], $user_id, $filtered_content, $scheduled_at_sql]);
                save_draft($user_id, '');
                $_SESSION['flash'] = (!empty($scheduled_at_sql) ? 'Gönderi zamanlandı' : 'Gönderi paylaşıldı');
                header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
                exit;
            }
        }
    }
}

// Get group posts with like counts and comment counts
// Access control: private groups visible only to members or admins
$can_view = empty($group['is_private']) || $is_member || is_admin();

$posts = [];
if ($can_view) {
    $ps = $pdo->prepare("
        SELECT
    gp.*,
    u.username,
    u.is_premium,
    ui.id as image_id,
    ui.filename as image_filename,
    ui.publish_date as image_publish_date,
    ui.tags as image_tags,
    ui.user_id as image_user_id,
    EXISTS(SELECT 1 FROM group_post_edits WHERE post_id = gp.id) AS has_edits,
    (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id) as like_count,
    (SELECT COUNT(*) FROM group_post_likes WHERE post_id = gp.id AND user_id = ?) as user_liked,
    (SELECT COUNT(*) FROM group_post_comments WHERE post_id = gp.id) as comment_count
FROM group_posts gp
JOIN users u ON gp.user_id = u.id
LEFT JOIN user_images ui ON gp.image_id = ui.id
        WHERE gp.group_id = ?
          AND (gp.scheduled_at IS NULL OR gp.scheduled_at <= NOW() OR gp.user_id = ? OR ? = 1)
        ORDER BY gp.created_at DESC
        LIMIT 50
    ");
    $ps->execute([$user_id ?: 0, $group['id'], $user_id ?: 0, is_admin() ? 1 : 0]);
    $posts = $ps->fetchAll();
   foreach ($posts as &$post) {
    $post['poll'] = get_poll_for_group_post($post['id']);
    $post['slug'] = $group['slug'];
    if (!empty($post['image_id'])) {
        $post['image'] = [
            'id' => $post['image_id'],
            'filename' => $post['filename'] ?? null,
            'publish_date' => $post['publish_date'] ?? null,
            'tags' => $post['tags'] ?? null,
            'user_id' => $post['user_id'] ?? null
        ];
    }
}
unset($post);  // Fixed variable name
    }
 

// Get members
$members = [];
if ($can_view) {
    $ms = $pdo->prepare("
        SELECT 
            gm.*,
            u.username
        FROM group_members gm
        JOIN users u ON gm.user_id = u.id
        WHERE gm.group_id = ?
        ORDER BY gm.joined_at DESC
        LIMIT 20
    ");
    $ms->execute([$group['id']]);
    $members = $ms->fetchAll();
}

// Fetch pending application count for admin badge
$pending_app_count = 0;
if ($user_role === 'admin') {
    try {
        $pac = $pdo->prepare("SELECT COUNT(*) FROM group_join_requests WHERE group_id = ? AND status = 'pending'");
        $pac->execute([$group['id']]);
        $pending_app_count = (int)$pac->fetchColumn();
    } catch (PDOException $e) { /* table may not exist */ }
}
?>

<div class="main-container">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">Üyeler</div>
            <ul class="sidebar-list">
                <?php foreach (array_slice($members, 0, 10) as $member): ?>
                <li>
                    <div class="user-item">
                        <a href="<?= profile_url($member['username']) ?>">
                            <?= htmlspecialchars($member['username']) ?>
                            <?php if ($member['role'] === 'admin'): ?>
                            <span class="badge">yönetici</span>
                            <?php endif; ?>
                        </a>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($members) > 10): ?>
            <a href="<?= group_members_url($slug) ?>" class="view-all-link">tüm üyeleri gör »</a>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="content-area form-centered">
        <!-- Group Header -->
        <div class="group-header">
            <div class="group-header-inner">
                <div class="group-header-main">
                    <h1 class="group-title">
                        <?= htmlspecialchars($group['name']) ?>
                        <?php if (!empty($group['is_private'])): ?>
                            <span class="lock-badge">🔒 Özel</span>
                        <?php endif; ?>
                        <a class="group-rss-link btn-compact btn-outline" href="<?= BASE_PATH ?>/g/<?= rawurlencode($slug) ?>/rss.xml" rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($group['name'] . ' — RSS', ENT_QUOTES, 'UTF-8') ?>">RSS</a>
                    </h1>
                    <p class="group-description">
                        <?= htmlspecialchars($group['description']) ?>
                    </p>
                    <p class="group-meta">
                        <?= count($members) ?> üye · <?= count($posts) ?> gönderi
                    </p>
                </div>
                <?php if ($user_id): ?>
                    <?php if ($is_member): ?>
                        <div class="group-actions">
                            <?php if ($user_role !== 'admin'): ?>
                            <form method="POST" action="<?= BASE_PATH ?>/groups_leave.php">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                <input type="hidden" name="redirect" value="group.php?slug=<?= urlencode($slug) ?>">
                                <button type="submit" class="btn-join-group btn-leave-group">Ayrıl</button>
                            </form>
                            <?php endif; ?>
                            <a href="<?= group_invite_url($slug) ?>" class="btn-join-group edit">Davet Et</a>
                            <?php if ($user_role === 'admin'): ?>
                            <a href="<?= group_edit_url($slug) ?>?tab=applications" class="btn-join-group edit">Başvurular<?php if ($pending_app_count > 0): ?> <span class="badge red"><?= $pending_app_count ?></span><?php endif; ?></a>
                            <a href="<?= group_edit_url($slug) ?>" class="btn-join-group edit">Grubu Düzenle</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php
                        $pending_status = null;
                        if (!empty($group['is_private'])) {
                            try {
                                $rq = $pdo->prepare("SELECT status FROM group_join_requests WHERE group_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT 1");
                                $rq->execute([$group['id'], $user_id]);
                                $row = $rq->fetch();
                                $pending_status = $row ? $row['status'] : null;
                            } catch (PDOException $e) {
                                // ignore if table missing
                            }
                        }
                        ?>
                        <?php if (!empty($group['is_private']) && $pending_status === 'pending'): ?>
                            <div class="group-pending">Başvurunuz beklemede. Yönetici onayladığında bildirim alacaksınız.</div>
                        <?php else: ?>
                            <form method="POST" action="<?= BASE_PATH ?>/groups_join.php">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                <input type="hidden" name="redirect" value="group.php?slug=<?= urlencode($slug) ?>">
                                <?php if (!empty($group['is_private'])): ?>
                                    <?php if (!empty($group['entry_question'])): ?>
                                        <div class="group-entry-question">Soru: <?= htmlspecialchars($group['entry_question']) ?></div>
                                    <?php endif; ?>
                                    <textarea name="entry_answer" placeholder="Yanıtınız" class="group-entry-textarea"></textarea>
                                <?php endif; ?>
                                <button type="submit" class="btn-join-group">Katıl</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_SESSION['flash'])): ?>
        <div class="group-info">
            <?= $_SESSION['flash'] ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <?php if (!$can_view): ?>
        <div class="group-callout">
            <p class="group-callout-text">Bu grup özeldir. İçeriği görmek için katılmanız gerekir.</p>
        </div>
        <?php endif; ?>

        <?php if ($is_member && $can_view): ?>
        <!-- Post Form -->
        <div class="post-form-container">
            <?php $group_trending = get_trending_tags_for_group($group['id'], 8);
            // Fall back to global trending tags if this group doesn't have enough activity
            if (empty($group_trending)) {
                $group_trending = get_trending_tags(8);
            }
            ?>
            <?php if (!empty($group_trending)): ?>
                <div class="tag-navigation">
                    <span class="tag-label">Revaçtaki Mevzuatlar:</span>
                    <?php foreach ($group_trending as $tag):
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
            <?php endif; ?>

            <!-- insert-helpers removed: helper mini-forms were intentionally removed to simplify composer UI -->

            <form method="POST" class="post-form" id="composer-form">
                <input type="hidden" name="action" value="create_post">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="post-toolbar">
                    <div class="post-toolbar-group">
                        <button type="submit" name="insert_type" value="tag" class="btn-small">#</button>
                        <button type="submit" name="insert_type" value="link" class="btn-small">Link</button>
                        <button type="submit" name="insert_type" value="spoiler" class="btn-small">Ekstra</button>
                        <button type="submit" name="insert_type" value="kod" class="btn-small">Kod</button>
                        <?php if (!is_user_creation_restricted($user_id)): ?>
                            <a href="<?= BASE_PATH ?>/anket/olustur?slug=<?= urlencode($slug) ?>" class="btn-small">Anket</a>
                            <a href="<?= BASE_PATH ?>/tahlil/olustur?slug=<?= urlencode($slug) ?>" class="btn-small">Tahlil</a>
                            <?php if (!is_user_creation_restricted($user_id)): ?>
                             <a href="<?= BASE_PATH ?>/fotograf-yukle?context=group&group_id=<?= (int)$group['id'] ?>" class="btn-small">📷 Fotoğraf</a>
                            <?php else: ?>
                         <span class="btn-small muted" title="Yeni hesaplar için devre dışı">Fotoğraf</span>
                        <?php endif; ?>
                        <?php else: ?>
                            <span class="btn-small muted" title="Yeni hesaplar için devre dışı">Anket</span>
                            <span class="btn-small muted" title="Yeni hesaplar için devre dışı">Tahlil</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                    $group_post_limit = function_exists('get_user_post_limit') ? get_user_post_limit($user_id) : MAX_POST_LENGTH;
                    $group_textarea_attrs = $group_post_limit > 0 ? ' maxlength="' . (int)$group_post_limit . '"' : '';
                ?>
                <textarea name="content" placeholder="Grupta bir şeyler paylaş..."<?= $group_textarea_attrs ?>><?= sanitize_input($_POST['content'] ?? get_draft($user_id)) ?></textarea>

                <?php if ((is_user_premium($user_id) || is_admin()) && ($show_schedule || !empty($_POST['scheduled_at']))): ?>
                    <div class="post-schedule-row">
                        <label for="scheduled_at">⏰ Zamanla:</label>
                        <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="<?= htmlspecialchars($_POST['scheduled_at'] ?? '') ?>" />
                        <button type="submit" name="schedule_submit" value="1" class="btn-post">Kaydet</button>
                        <button type="submit" name="schedule_cancel" value="1" class="btn-outline">İptal</button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($preview_error)): ?>
                    <div class="preview-error"><?= $preview_error ?></div>
                <?php endif; ?>

                <?php if (!empty($group_preview_html)): ?>
                    <div class="post-preview"><?= $group_preview_html ?></div>
                <?php endif; ?>

                <div class="post-form-actions">
                    <?php if ($group_post_limit == 0): ?>
                        <span class="char-count">Sınırsız (Premium)</span>
                    <?php else: ?>
                        <span class="char-count">En fazla <?= (int)$group_post_limit ?> karakter</span>
                    <?php endif; ?>
                    <div class="post-actions-buttons">
                        <button type="submit" name="preview" value="1" class="btn-outline">Önizleme</button>
                        <?php if (is_user_premium($user_id) || is_admin()): ?>
                            <button type="submit" name="schedule_mode" value="1" class="btn-outline">Zamanla</button>
                        <?php endif; ?>
                        <button type="submit" class="btn-post">Paylaş</button>
                    </div>
                </div>
            </form>
        </div>
        <?php elseif ($user_id): ?>
        <div class="group-callout">
            <p class="group-callout-text">Gönderi paylaşmak için gruba katılın</p>
        </div>
        <?php endif; ?>

        <?php $current_user_id = $user_id; ?>
        <!-- Posts Feed -->
        <div class="posts-feed">
            <?php if (empty($posts)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <p>Henüz gönderi yok. İlk gönderen sen ol!</p>
                </div>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <?php $gp = $post; require __DIR__ . '/templates/group-post-card.php'; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-section">
            <div class="sidebar-title">Topluluk Bilgisi</div>
            <div class="sidebar-meta">
                <p class="meta-row"><strong>Oluşturan:</strong><br>
                <a href="<?= profile_url($group['creator_name']) ?>"><?= htmlspecialchars($group['creator_name']) ?></a></p>
                <p class="meta-row"><strong>Oluşturulma:</strong><br>
                <?= date('d.m.Y', strtotime($group['created_at'])) ?></p>
            </div>
        <div class="sidebar-section">
            <div class="sidebar-title">Diğer Topluluklar</div>
            <ul class="sidebar-list">
                <li><a href="<?= BASE_PATH ?>/topluluklar">Tüm topluluklar »</a></li>
            </ul>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
