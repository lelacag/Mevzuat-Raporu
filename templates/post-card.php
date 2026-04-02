<?php
/**
 * Post Card Template - Sosyomat Style
 * Displays a single post in the feed
 */
?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/post-card.css?v=1">
<article class="post-card post-card-legacy">
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
                    if ($poster && isset($poster['role']) && $poster['role'] === 'rookie' && empty($poster['is_approved'])): ?>
                    <span class="badge badge-rookie">Yeni Gelen</span>
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
                <?php if (isset($post['is_premium']) && $post['is_premium']): ?>
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
                    $created_ts = $created ? @strtotime($created) : 0;
                    $updated_ts = $updated ? @strtotime($updated) : 0;
                    if ($updated_ts && $created_ts && ($updated_ts - $created_ts) > 2) {
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
        <?= render_rich_text($post['content']) ?>
    </div>    
    <?php endif; ?>

    <?php if (array_key_exists('poll', $post) && $post['poll'] === null): ?>
        <!-- poll key exists but null - nothing to render -->
    <?php elseif (!empty($post['poll'])): ?>
        <?php $poll = $post['poll']; require __DIR__ . '/poll-block.php'; ?>
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
        <?php if ($current_user_id): ?>
            <form method="POST" action="<?= BASE_PATH ?>/api/like.php" class="action-form">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                <button type="submit" class="post-action like-btn <?= (isset($post['user_has_liked']) && $post['user_has_liked']) ? 'liked' : '' ?>">
                    <?= (isset($post['user_has_liked']) && $post['user_has_liked']) ? '♥' : '♡' ?> <?= $post['like_count'] ?? 0 ?> Beğen
                </button>
            </form>
            <a href="<?= get_post_url($post['id'], $post['username']) ?>" class="post-action">
                💬 <?= $post['comment_count'] ?? 0 ?> Yorum
            </a>
            <?php if ($current_user_id == $post['user_id'] && empty($post['poll']) && empty($post['test'])): ?>
                <a href="<?= BASE_PATH ?>/edit_post.php?id=<?= $post['id'] ?>" class="post-action edit-btn">
                    ✏️ Düzenle
                </a>
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
            <a href="<?= BASE_PATH ?>/login.php" class="post-action">♡ <?= $post['like_count'] ?? 0 ?> Beğen</a>
            <a href="<?= get_post_url($post['id'], $post['username']) ?>" class="post-action">💬 <?= $post['comment_count'] ?? 0 ?> Yorum</a>
            <a href="<?= BASE_PATH ?>/login.php" class="post-action">⚠️ Bildir</a>
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
            <button type="submit" class="post-comment-submit">Gönder</button>
            <input id="reply-content-<?= (int)$post['id'] ?>" type="text" name="content" placeholder="Yorum yaz..." maxlength="500" required class="post-card-comment-input">
        </form>
    </div>
    <?php endif; ?>
</article>
