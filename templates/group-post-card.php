<?php
// Group Post Card Template (aligned with timeline post-card style)
// Expects $gp array with keys: id, content, created_at, updated_at?, username, group_name, slug, like_count, comment_count, user_has_liked/user_liked, user_id
// Uses $current_user_id and $csrf_token from global scope (header)
?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/post-card.css?v=2">
<article class="post-card post-card-legacy">
    <div class="post-card-header">
        <div class="post-card-meta">
            <div class="post-card-meta-row">
                <a href="<?= profile_url($gp['username']) ?>" class="post-card-username"><?= htmlspecialchars($gp['username']) ?></a>
                <?php
                    $poster = get_user($gp['user_id'] ?? null);
                    if ($poster && isset($poster['role']) && $poster['role'] === 'rookie' && empty($poster['is_approved'])): ?>
                    <span class="badge badge-rookie">Yeni Gelen</span>
                <?php endif; ?>
                <?php
                    $pb = get_user_custom_badge($gp['user_id'] ?? null);
                    if ($pb && !empty($pb['badge_text'])):
                        $cls = badge_color_to_class($pb['badge_color']);
                ?>
                    <span class="custom-badge custom-badge-<?= $cls ?>"><?= htmlspecialchars($pb['badge_text']) ?></span>
                <?php endif; ?>
                <?php if (!empty($gp['is_premium'])): ?>
                    <span class="premium-star">⭐</span>
                <?php endif; ?>
                <span class="muted small">·</span>
                <a href="<?= group_url($gp['slug'] ?? '') ?>" class="post-card-group-link muted small"><?= htmlspecialchars($gp['group_name'] ?? '') ?></a>
            </div>
            <div class="post-card-time">
                <?php
                    $created = $gp['created_at'] ?? null;
                    $updated = $gp['updated_at'] ?? null;
                    $created_output = $created
                        ? htmlspecialchars(format_time($created), ENT_QUOTES, 'UTF-8')
                        : ($updated ? htmlspecialchars(format_time($updated), ENT_QUOTES, 'UTF-8') : '—');
                    echo '<span class="post-card-sent">Gönderildi: ' . $created_output . '</span>';

                    $created_ts = $created ? @strtotime($created) : 0;
                    $updated_ts = $updated ? @strtotime($updated) : 0;
                    if ($updated_ts && $created_ts && ($updated_ts - $created_ts) > 2) {
                        $is_owner = ($current_user_id && $current_user_id == ($gp['user_id'] ?? null));
                        $is_admin = function_exists('is_admin') && is_admin();
                        $label = 'Düzenlendi: ' . htmlspecialchars(format_time($updated), ENT_QUOTES, 'UTF-8');
                        if ($is_owner || $is_admin) {
                            $compare_link = htmlspecialchars(BASE_PATH . '/group_post.php?id=' . (int)($gp['id'] ?? 0) . '&compare=edit', ENT_QUOTES, 'UTF-8');
                            echo ' <a class="post-card-edited" href="' . $compare_link . '">' . $label . '</a>';
                        } else {
                            echo ' <span class="post-card-edited">' . $label . '</span>';
                        }
                    }
                ?>
            </div>
        </div>
    </div>

    <?php if (empty($gp['poll']) && empty($gp['test'])): ?>
    <div class="post-card-content">
        <?= render_rich_text($gp['content'] ?? '') ?>
    </div>
    <?php endif; ?>

    <?php if (array_key_exists('poll', $gp) && $gp['poll'] === null): ?>
        <!-- poll key exists but null -->
    <?php elseif (!empty($gp['poll'])): ?>
        <?php $poll = $gp['poll']; require __DIR__ . '/poll-block.php'; ?>
    <?php endif; ?>

    <?php $gp_test = $gp['test'] ?? get_test_for_group_post($gp['id']); ?>
    <?php if ($gp_test === null && array_key_exists('test', $gp)): ?>
        <?php error_log('group-post-card: test key exists for group_post id ' . (int)$gp['id'] . ' but value is null'); ?>
    <?php elseif (!empty($gp_test)): ?>
        <?php $test = $gp_test; require __DIR__ . '/test-block.php'; ?>
    <?php endif; ?>

    <?php
        preg_match_all('/#([\p{L}\p{N}_-]+)/u', $gp['content'] ?? '', $matches);
        if (!empty($matches[1])):
    ?>
    <div class="post-card-tags">
        <?php foreach (array_unique($matches[1]) as $tag): ?>
            <a href="<?= search_url() ?>?tag=<?= urlencode($tag) ?>" class="post-tag-link">#<?= htmlspecialchars($tag) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="post-card-actions">
        <?php if ($current_user_id): ?>
            <form method="POST" action="<?= BASE_PATH ?>/api/group_post_like.php" class="action-form">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="post_id" value="<?= (int)$gp['id'] ?>">
                <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                <button type="submit" class="post-action like-btn <?= (!empty($gp['user_has_liked']) || !empty($gp['user_liked'])) ? 'liked' : '' ?>">
                    <?= (!empty($gp['user_has_liked']) || !empty($gp['user_liked'])) ? '♥' : '♡' ?> <?= (int)($gp['like_count'] ?? 0) ?> Beğen
                </button>
            </form>
            <a href="<?= group_post_url($gp['slug'] ?? '', $gp['id']) ?>" class="post-action">💬 <?= (int)($gp['comment_count'] ?? 0) ?> Yorum</a>
            <?php if ($current_user_id == ($gp['user_id'] ?? null)): ?>
                <a href="<?= group_edit_post_url($gp['slug'] ?? '', $gp['id']) ?>" class="post-action edit-btn">✏️ Düzenle</a>
                <a href="<?= BASE_PATH ?>/group_post_delete_confirm.php?id=<?= $gp['id'] ?>&slug=<?= urlencode($gp['slug'] ?? '') ?>" class="post-action delete-btn">🗑️ Sil</a>
            <?php else: ?>
                <form method="POST" action="<?= BASE_PATH ?>/api/report.php" class="action-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="target_type" value="group_post">
                    <input type="hidden" name="target_id" value="<?= (int)$gp['id'] ?>">
                    <input type="hidden" name="reason" value="spam">
                    <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <button type="submit" class="post-action report-btn">⚠️ Bildir</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <a href="<?= BASE_PATH ?>/login.php" class="post-action">♡ <?= (int)($gp['like_count'] ?? 0) ?> Beğen</a>
            <a href="<?= group_post_url($gp['slug'] ?? '', $gp['id']) ?>" class="post-action">💬 <?= (int)($gp['comment_count'] ?? 0) ?> Yorum</a>
            <a href="<?= BASE_PATH ?>/login.php" class="post-action">⚠️ Bildir</a>
        <?php endif; ?>

        <?php
            $direct_link = htmlspecialchars(group_post_url($gp['slug'] ?? '', $gp['id']), ENT_QUOTES, 'UTF-8');
            if (!empty($gp['test'])) {
                $t = $gp['test'];
                $t_slug = $t['slug'] ?? (function_exists('generate_slug') ? (generate_slug($t['title']) . '-' . $t['id']) : ('tahlil-' . $t['id']));
                $direct_link = htmlspecialchars(BASE_PATH . '/tahlil/' . rawurlencode($t_slug) . '/' . (int)$t['id'], ENT_QUOTES, 'UTF-8');
            } elseif (!empty($gp['poll'])) {
                $p = $gp['poll'];
                $p_slug = $p['slug'] ?? (function_exists('generate_slug') ? (generate_slug($p['title']) . '-' . $p['id']) : ('anket-' . $p['id']));
                $direct_link = htmlspecialchars(BASE_PATH . '/anket/' . rawurlencode($p_slug) . '/' . (int)$p['id'], ENT_QUOTES, 'UTF-8');
            }
        ?>
        <a href="<?= $direct_link ?>" class="post-card-icon">#G<?= (int)$gp['id'] ?></a>
    </div>

    <?php
        $show_comment_form = !isset($show_group_post_comment_form) || $show_group_post_comment_form;
        if (!empty($current_user_id) && $show_comment_form):
    ?>
        <div class="post-card-comment-form">
            <form method="POST" action="<?= BASE_PATH ?>/group_post.php?id=<?= (int)$gp['id'] ?>" class="form-no-padding">
                <input type="hidden" name="action" value="create_comment">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                <input type="text" name="content" placeholder="Yorum yaz..." maxlength="500" required class="post-card-comment-input">
                <button type="submit" class="sr-only">Gönder</button>
            </form>
        </div>
    <?php endif; ?>
</article>
