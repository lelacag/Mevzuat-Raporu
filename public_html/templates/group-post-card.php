<?php
/**
 * Group Post Card Template — matches main timeline post-card.php styling
 * Expects $gp array with group post data
 * Uses $current_user_id and $csrf_token from global scope
 */
?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/post-card.css?v=2">
<article class="post-card post-card-legacy">
    <!-- Header: Username/Timestamp + Group -->
    <div class="post-card-header">
        <div class="post-card-meta">
            <div class="post-card-meta-row">
                <a href="<?= profile_url($gp['username']) ?>" class="post-card-username">
                    <?= htmlspecialchars($gp['username']) ?>
                </a>
                <?php
                    $poster = get_user($gp['user_id']);
                    $_show_rookie = $poster && empty($poster['is_approved']) && (
                        (isset($poster['role']) && $poster['role'] === 'rookie')
                        || (function_exists('get_user_badges') && (function() use ($gp) {
                            foreach (get_user_badges($gp['user_id']) as $_ub) {
                                if (($_ub['slug'] ?? '') === 'yeni-gelen') return true;
                            }
                            return false;
                        })())
                    );
                    if ($_show_rookie): ?>
                    <span class="badge badge-rookie">Yeni Gelen</span>
                <?php endif; ?>
                <?php
                    $_tier = (!empty($poster['is_approved']) && function_exists('get_user_best_badge')) ? get_user_best_badge($gp['user_id']) : null;
                    if ($_tier): ?>
                    <span class="badge badge-tier"><?= htmlspecialchars($_tier['name']) ?></span>
                <?php endif; ?>
                <?php if (!empty($gp['scheduled_at']) && strtotime($gp['scheduled_at']) > time()): ?>
                    <span class="badge badge-info">⏰ Programlı: <?= htmlspecialchars(format_time($gp['scheduled_at'])) ?></span>
                <?php endif; ?>
                <?php
                    $pb = get_user_custom_badge($gp['user_id'] ?? null);
                    if ($pb && !empty($pb['badge_text'])):
                        $cls = badge_color_to_class($pb['badge_color']);
                ?>
                    <span class="custom-badge custom-badge-<?= $cls ?>"><?= htmlspecialchars($pb['badge_text']) ?></span>
                <?php endif; ?>
                <?php if (!empty($gp['is_premium']) || !empty($poster['is_premium']) || (function_exists('is_user_premium') && is_user_premium($gp['user_id'] ?? 0))): ?>
                    <span class="premium-star">⭐</span>
                <?php endif; ?>
                <span class="group-post-meta-sep">·</span>
                <a href="<?= group_url($gp['slug'] ?? ($group['slug'] ?? '')) ?>" class="group-post-meta-link">
                    <?= htmlspecialchars($gp['group_name'] ?? ($group['name'] ?? '')) ?>
                </a>
            </div>
            <div class="post-card-time">
                <?php
                    $created = $gp['created_at'] ?? null;
                    $updated = $gp['updated_at'] ?? null;
                    $created_output = $created
                        ? htmlspecialchars(format_time($created), ENT_QUOTES, 'UTF-8')
                        : ($updated ? htmlspecialchars(format_time($updated), ENT_QUOTES, 'UTF-8') : '—');
                    echo '<span class="post-card-sent">Gönderildi: ' . $created_output . '</span>';

                    $edited = isset($gp['has_edits']) ? (bool)$gp['has_edits'] : (!empty($gp['id']) && group_post_has_edits($gp['id']));
                    if ($edited) {
                        $is_owner = ($current_user_id && $current_user_id == ($gp['user_id'] ?? null));
                        $is_admin = function_exists('is_admin') && is_admin();
                        $label = 'Düzenlendi: ' . htmlspecialchars(format_time($updated), ENT_QUOTES, 'UTF-8');
                        if ($is_owner || $is_admin) {
                            $compare_link = htmlspecialchars(BASE_PATH . '/g/' . urlencode($gp['slug'] ?? ($group['slug'] ?? '')) . '/post/' . (int)($gp['id'] ?? 0), ENT_QUOTES, 'UTF-8');
                            echo ' <a class="post-card-edited" href="' . $compare_link . '">' . $label . '</a>';
                        } else {
                            echo ' <span class="post-card-edited">' . $label . '</span>';
                        }
                    }
                ?>
            </div>
        </div>
    </div>
    
    <!-- Post Content — skip when poll/test present (poll-block renders it) -->
    <?php if (empty($gp['poll']) && empty($gp['test'])): ?>
    <div class="post-card-content">
        <?= function_exists('render_rich_text') ? render_rich_text($gp['content'] ?? '') : nl2br(linkify_mentions($gp['content'] ?? '')) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($gp['poll'])): ?>
        <?php $post = null; $poll = $gp['poll']; require __DIR__ . '/poll-block.php'; ?>
    <?php endif; ?>

    <?php $gp_test = $gp['test'] ?? get_test_for_group_post($gp['id']); ?>
    <?php if ($gp_test === null && array_key_exists('test', $gp)): ?>
        <?php error_log('group-post-card: test key exists for group_post id ' . (int)$gp['id'] . ' but value is null'); ?>
    <?php elseif (!empty($gp_test)): ?>
        <?php $test = $gp_test; require __DIR__ . '/test-block.php'; ?>
    <?php endif; ?>

    <?php if (!empty($gp['source_url'])): ?>
        <div class="post-source">Kaynak: <a href="<?= htmlspecialchars(BASE_PATH . '/outbound.php?u=' . rawurlencode($gp['source_url'])) ?>" class="post-link" target="_blank"><?= htmlspecialchars($gp['source_url']) ?></a></div>
    <?php endif; ?>
    <?php if (!empty($gp['image'])): ?>
        <?php
            $gpc_img  = $gp['image'];
            $gpc_date = $gpc_img['publish_date']
                ? date('d.m.Y', strtotime($gpc_img['publish_date']))
                : null;
        ?>
        <div class="post-card-image-wrap">
            <a href="<?= BASE_PATH ?>/foto/<?= (int)$gpc_img['id'] ?>">
                <img src="<?= BASE_PATH ?>/photo_img.php?id=<?= (int)$gpc_img['id'] ?>"
                     alt="Fotoğraf"
                     class="post-card-photo"
                     loading="lazy">
            </a>
            <div class="post-card-image-meta">

                <?php if ($gpc_date): ?>
                    <span class="post-card-image-date"><?= htmlspecialchars($gpc_date) ?></span>
                <?php endif; ?>
                <a href="<?= BASE_PATH ?>/foto/<?= (int)$gpc_img['id'] ?>" class="post-card-image-link">Fotoğrafı Görüntüle →</a>
            </div>
        </div>
    <?php
        // Increment view count server-side when image is rendered (skip owner & admins)
        $_gpc_viewer = isset($current_user_id) ? (int)$current_user_id : 0;
        $_gpc_owner  = (int)($gpc_img['user_id'] ?? 0);
        if (!($_gpc_viewer && ($_gpc_viewer === $_gpc_owner || (function_exists('is_admin') && is_admin())))) {
            db_connect()->prepare('UPDATE user_images SET view_count = view_count + 1 WHERE id = ?')
                ->execute([(int)$gpc_img['id']]);
        }
        unset($_gpc_viewer, $_gpc_owner);
    ?>
    <?php endif; ?>

    <?php
    preg_match_all('/#([\p{L}\p{N}_-]+)/u', $gp['content'] ?? '', $matches);
    $_gpc_tags = array_unique($matches[1] ?? []);
    if (!empty($gp['image']['tags'])) {
        foreach (array_map('trim', explode(',', $gp['image']['tags'])) as $_t) {
            if ($_t !== '') $_gpc_tags[] = ltrim($_t, '#');
        }
        $_gpc_tags = array_unique(array_filter($_gpc_tags));
    }
    if (!empty($_gpc_tags)):
    ?>
    <div class="post-card-tags">
        <?php foreach ($_gpc_tags as $tag): ?>
            <a href="<?= search_url() ?>?tag=<?= urlencode($tag) ?>" class="post-tag-link">#<?= htmlspecialchars($tag) ?></a>
        <?php endforeach; ?>
    </div>
    <?php unset($_gpc_tags, $_t); endif; ?>
    
    <!-- Action Buttons -->
    <div class="post-card-actions">
        <?php if (!empty($current_user_id)): ?>
            <form method="POST" action="<?= BASE_PATH ?>/api/group_post_like.php" class="action-form">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="post_id" value="<?= (int)$gp['id'] ?>">
                <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                <button type="submit" class="post-action like-btn <?= (!empty($gp['user_has_liked'])) ? 'liked' : '' ?>">
                    <?= (!empty($gp['user_has_liked'])) ? '♥' : '♡' ?> <?= (int)($gp['like_count'] ?? 0) ?> Beğen
                </button>
            </form>

            <a href="<?= group_post_url($gp['slug'] ?? ($group['slug'] ?? ''), $gp['id']) ?>" class="post-action">
                💬 <?= (int)($gp['comment_count'] ?? 0) ?> Yorum
            </a>

            <?php if ($current_user_id == $gp['user_id']): ?>
                <?php $_gp_slug = $gp['slug'] ?? ($group['slug'] ?? ''); ?>
                <a href="<?= group_edit_post_url($_gp_slug, $gp['id']) ?>" class="post-action edit-btn">✏️ Düzenle</a>
                <?php if (empty($gp['poll']) && empty($gp['test'])): ?>
                <?php $del_url = htmlspecialchars(BASE_PATH . '/g/' . urlencode($_gp_slug) . '/post/' . (int)$gp['id'] . '/sil'); ?>
                <a href="<?= $del_url ?>" class="post-action delete-btn">🗑️ Sil</a>
                <?php endif; ?>
            <?php else: ?>
                <form method="POST" action="<?= BASE_PATH ?>/api/report.php" class="action-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="target_type" value="group_post">
                    <input type="hidden" name="target_id" value="<?= (int)$gp['id'] ?>">
                    <input type="hidden" name="reason" value="spam">
                    <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <button type="submit" class="post-action report-btn">
                        ⚠️ Bildir
                    </button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <a href="<?= BASE_PATH ?>/giris" class="post-action">♥ <?= (int)($gp['like_count'] ?? 0) ?> Beğen</a>
            <a href="<?= group_post_url($gp['slug'] ?? ($group['slug'] ?? ''), $gp['id']) ?>" class="post-action">💬 <?= (int)($gp['comment_count'] ?? 0) ?> Yorum</a>
            <a href="<?= BASE_PATH ?>/giris" class="post-action">⚠️ Bildir</a>
        <?php endif; ?>
        <a href="<?= group_post_url($gp['slug'] ?? ($group['slug'] ?? ''), $gp['id']) ?>" class="post-card-icon">#G<?= (int)$gp['id'] ?></a>
    </div>

    <?php if (!empty($current_user_id)): ?>
    <div class="post-card-comment-form">
        <form method="POST" action="<?= group_post_url($gp['slug'] ?? ($group['slug'] ?? ''), $gp['id']) ?>" class="form-no-padding">
            <input type="hidden" name="action" value="create_comment">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <label for="reply-content-g<?= (int)$gp['id'] ?>" class="sr-only">Yorum metni</label>
            <input id="reply-content-g<?= (int)$gp['id'] ?>" type="text" name="content" placeholder="Yorum yaz..." maxlength="500" required class="post-card-comment-input">
            <button type="submit" class="sr-only">Gönder</button>
        </form>
    </div>
    <?php endif; ?>
</article>
