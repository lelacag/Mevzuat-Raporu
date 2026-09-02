<?php
/**
 * Post Card Template - Sosyomat Style
 * Displays a single post in the feed
 */
// Check if this post is deleted
$is_deleted = !empty($post['deleted_at']);
$is_admin = function_exists('is_admin') && is_admin();
$current_user_id = get_current_user_id();

// Add deleted class to the post card container
$post_card_classes = 'post-card post-card-legacy';
if ($is_deleted) {
    $post_card_classes .= ' post-deleted';
}
?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/post-card.css?v=2">
<article class="<?= $post_card_classes ?>">
    <!-- Header: Icon + Username/Timestamp -->
    <div class="post-card-header">
        <!-- Username & Timestamp -->
        <div class="post-card-meta">
            <div class="post-card-meta-row">
                <a href="<?= profile_url($post['username']) ?>" class="post-card-username">
                    <?= htmlspecialchars($post['username']) ?>
                </a>
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
                <?php if (!empty($post['scheduled_at']) && strtotime($post['scheduled_at']) > time()): ?>
                    <span class="badge badge-info">⏰ Programlı: <?= htmlspecialchars(format_time($post['scheduled_at'])) ?></span>
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
                <?php
                    $created = $post['created_at'] ?? null;
                    $updated = $post['updated_at'] ?? null;
                    $created_output = $created
                        ? htmlspecialchars(format_time($created), ENT_QUOTES, 'UTF-8')
                        : ($updated ? htmlspecialchars(format_time($updated), ENT_QUOTES, 'UTF-8') : '—');
                    echo '<span class="post-card-sent">Gönderildi: ' . $created_output . '</span>';
                    $edited = isset($post['has_edits']) ? (bool)$post['has_edits'] : (!empty($post['id']) && post_has_edits($post['id']));
                    if ($edited) {
                        // Only show a clickable compare link to the post owner or admins.
                        $is_owner = ($current_user_id && $current_user_id == $post['user_id']);
                        $is_admin = function_exists('is_admin') && is_admin();
                        $label = 'Düzenlendi: ' . htmlspecialchars(format_time($updated), ENT_QUOTES, 'UTF-8');
                        if ($is_owner || $is_admin) {
                            $compare_link = htmlspecialchars(BASE_PATH . '/post/' . intval($post['id']) . '/karsilastirma/son-duzenleme', ENT_QUOTES, 'UTF-8');
                            echo ' <a class="post-card-edited" href="' . $compare_link . '">' . $label . '</a>';
                        } else {
                            echo ' <span class="post-card-edited">' . $label . '</span>';
                        }
                        // history link removed from timeline; shown on compare page instead
                    }
                ?>
            </div>
        </div>
    </div>
    
    <!-- Post Content (aligned under username) -->
    <?php if (empty($post['poll']) && empty($post['test'])): ?>
    <div class="post-card-content">
        <?php if ($is_deleted): ?>
            <div class="deleted-notice">Bu gönderi silinmiştir.</div>
        <?php else: ?>
            <?= render_rich_text($post['content']) ?>
        <?php endif; ?>
    </div>    
    <?php endif; ?>

    <?php if (array_key_exists('poll', $post) && $post['poll'] === null): ?>
        <!-- poll key exists but null - nothing to render -->
    <?php elseif (!empty($post['poll'])): ?>
        <?php $gp = null; $poll = $post['poll']; require __DIR__ . '/poll-block.php'; ?>
    <?php endif; ?>
    <?php $post_test = $post['test'] ?? get_test_for_post($post['id']); ?>
    <?php if ($post_test === null && array_key_exists('test', $post)): ?>
        <?php error_log('post-card: test key exists for post id ' . (int)$post['id'] . ' but value is null'); ?>
    <?php elseif (!empty($post_test)): ?>
        <?php $test = $post_test; require __DIR__ . '/test-block.php'; ?>
    <?php endif; ?>
    <?php if (!empty($post['source_url'])): ?>
        <div class="post-source">Kaynak: <a href="<?= htmlspecialchars(BASE_PATH . '/outbound.php?u=' . rawurlencode($post['source_url'])) ?>" class="post-link" target="_blank"><?= htmlspecialchars($post['source_url']) ?></a></div>
    <?php endif; ?>
    <?php if (!empty($post['image'])): ?>
        <?php
            $pc_img  = $post['image'];
            $pc_date = $pc_img['publish_date']
                ? date('d.m.Y', strtotime($pc_img['publish_date']))
                : null;
        ?>
        <div class="post-card-image-wrap">
            <a href="<?= function_exists('get_post_url') ? htmlspecialchars(get_post_url((int)$post['id'], $post['username'] ?? null)) : BASE_PATH . '/foto/' . (int)$pc_img['id'] ?>">
                <img src="<?= BASE_PATH ?>/photo_img.php?id=<?= (int)$pc_img['id'] ?>"
                     alt="Fotoğraf"
                     class="post-card-photo"
                     loading="lazy">
            </a>
            <div class="post-card-image-meta">

                <?php if ($pc_date): ?>
                    <span class="post-card-image-date"><?= htmlspecialchars($pc_date) ?></span>
                <?php endif; ?>
                <a href="<?= BASE_PATH ?>/foto/<?= (int)$pc_img['id'] ?>" class="post-card-image-link">Fotoğrafı Görüntüle →</a>
            </div>
        </div>
    <?php
        // Increment view count server-side when image is rendered (skip owner & admins)
        $_pc_viewer = isset($current_user_id) ? (int)$current_user_id : 0;
        $_pc_owner  = (int)($pc_img['user_id'] ?? 0);
        if (!($_pc_viewer && ($_pc_viewer === $_pc_owner || (function_exists('is_admin') && is_admin())))) {
            db_connect()->prepare('UPDATE user_images SET view_count = view_count + 1 WHERE id = ?')
                ->execute([(int)$pc_img['id']]);
        }
        unset($_pc_viewer, $_pc_owner);
    ?>
    <?php endif; ?>    
    <?php
    preg_match_all('/#([\p{L}\p{N}_-]+)/u', $post['content'], $matches);
    $_pc_tags = array_unique($matches[1] ?? []);
    if (!empty($post['image']['tags'])) {
        foreach (array_map('trim', explode(',', $post['image']['tags'])) as $_t) {
            if ($_t !== '') $_pc_tags[] = ltrim($_t, '#');
        }
        $_pc_tags = array_unique(array_filter($_pc_tags));
    }
    if (!empty($_pc_tags)):
    ?>
    <div class="post-card-tags">
        <?php foreach ($_pc_tags as $tag): ?>
            <a href="<?= search_url() ?>?tag=<?= urlencode($tag) ?>" class="post-tag-link">
                #<?= htmlspecialchars($tag) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php unset($_pc_tags, $_t); endif; ?>
    
    <div class="post-card-actions">
        <?php if ($current_user_id): ?>
            <form method="POST" action="<?= BASE_PATH ?>/api/like.php" class="action-form">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                <button type="submit" class="post-action like-btn <?= (isset($post['user_has_liked']) && $post['user_has_liked']) ? 'liked' : '' ?>">
                    <?= (isset($post['user_has_liked']) && $post['user_has_liked']) ? '♥' : '♡' ?> <?= $post['like_count'] ?? 0 ?> Beğen
                </button>
            </form>
            <?php if (function_exists('favorites_table_exists') && favorites_table_exists()): ?>
                <form method="POST" action="<?= BASE_PATH ?>/api/like.php" class="action-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="favorite">
                    <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                    <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <?php $favorited = isset($post['user_has_favorited']) ? (bool)$post['user_has_favorited'] : user_has_favorited_post($current_user_id, $post['id']); ?>
                    <button type="submit" class="post-action favorite-btn <?= $favorited ? 'liked' : '' ?>">
                        <?= $favorited ? '⭐ Favorilerden Kaldır' : '⭐ Favorilere Ekle' ?>
                    </button>
                </form>
            <?php endif; ?>
            <a href="<?= get_post_url($post['id'], $post['username']) ?>" class="post-action">
                💬 <?= $post['comment_count'] ?? 0 ?> Yorum
            </a>
            <?php if ($current_user_id == $post['user_id']): ?>
                <?php if (empty($post['poll']) && empty($post['test'])): ?>
                    <a href="<?= BASE_PATH ?>/edit_post.php?id=<?= $post['id'] ?>" class="post-action edit-btn">
                        ✏️ Düzenle
                    </a>
                <?php endif; ?>
                <a href="<?= BASE_PATH ?>/delete_post_confirm.php?id=<?= $post['id'] ?>" class="post-action delete-btn">
                    🗑️ Sil
                </a>
            <?php else: ?>
                <form method="POST" action="<?= BASE_PATH ?>/api/report.php" class="action-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="target_type" value="post">
                    <input type="hidden" name="target_id" value="<?= $post['id'] ?>">
                    <input type="hidden" name="reason" value="spam">
                    <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <button type="submit" class="post-action report-btn">
                        ⚠️ Bildir
                    </button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <a href="<?= BASE_PATH ?>/giris" class="post-action">♥ <?= $post['like_count'] ?? 0 ?> Beğen</a>
            <a href="<?= get_post_url($post['id'], $post['username']) ?>" class="post-action">💬 <?= $post['comment_count'] ?? 0 ?> Yorum</a>
            <a href="<?= BASE_PATH ?>/giris" class="post-action">⚠️ Bildir</a>
        <?php endif; ?>
        <?php
            $direct_link = htmlspecialchars(get_post_url($post['id'], $post['username']), ENT_QUOTES, 'UTF-8');
            if (!empty($post['test'])) {
                $t = $post['test'];
                $t_slug = $t['slug'] ?? (function_exists('generate_slug') ? (generate_slug($t['title']) . '-' . $t['id']) : ('tahlil-' . $t['id']));
                $direct_link = htmlspecialchars(BASE_PATH . '/tahlil/' . rawurlencode($t_slug) . '/' . (int)$t['id'], ENT_QUOTES, 'UTF-8');
            } elseif (!empty($post['poll'])) {
                $p = $post['poll'];
                $p_slug = $p['slug'] ?? (function_exists('generate_slug') ? (generate_slug($p['title']) . '-' . $p['id']) : ('anket-' . $p['id']));
                $direct_link = htmlspecialchars(BASE_PATH . '/anket/' . rawurlencode($p_slug) . '/' . (int)$p['id'], ENT_QUOTES, 'UTF-8');
            }
        ?>
        <a href="<?= $direct_link ?>" class="post-card-icon">#<?= $post['id'] ?></a>
    </div>
    
    <?php if (isset($post['comments']) && !empty($post['comments'])): ?>
    <div class="post-card-comments">
        <?php foreach (array_slice($post['comments'], 0, 3) as $comment): ?>
        <div class="post-comment-block">
            <a href="<?= profile_url($comment['username']) ?>" class="comment-user">
                <?= htmlspecialchars($comment['username']) ?>
            </a>
            <div class="comment-text"><?= render_rich_text($comment['content']) ?></div>
        </div>
        <?php endforeach; ?>
        
        <?php if (count($post['comments']) > 3): ?>
        <a href="<?= post_url($post['id']) ?>" class="comment-more-link">
            <?= count($post['comments']) - 3 ?> yorum daha gör...
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($current_user_id && !isset($hide_comment_form)): ?>
    <div class="post-card-comment-form">
        <form method="POST" action="<?= BASE_PATH ?>/api/reply.php" class="form-no-padding">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="parent_id" value="<?= $post['id'] ?>">
            <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <?php if (!empty($_REQUEST['sid'])): ?>
                <input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid']) ?>">
            <?php endif; ?>
            <label for="reply-content-<?= (int)$post['id'] ?>" class="sr-only">Yorum metni</label>
            <label for="reply-content-<?= (int)$post['id'] ?>" class="sr-only">Yorum metni</label>
            <input id="reply-content-<?= (int)$post['id'] ?>" type="text" name="content" placeholder="Yorum yaz..." maxlength="500" required class="post-card-comment-input">
            <button type="submit" class="sr-only">Gönder</button>
        </form>
    </div>
    <?php endif; ?>
</article>
