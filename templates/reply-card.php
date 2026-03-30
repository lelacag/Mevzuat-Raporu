<?php
// Support being included with $reply or $post. Minimal Web 1.0 presentation.
$post = isset($reply) ? $reply : ($post ?? null);
if (!$post) {
    return;
}
$post_id = $post['id'];
$root_post_id = isset($_GET['id']) ? (int)$_GET['id'] : $post_id;
$author_username = $post['username'];
$content = $post['content'];
$created_at = format_time($post['created_at']);
$likes_count = $post['likes_count'] ?? 0;
$user_id = get_current_user_id();
$is_liked = $user_id && $post_id ? is_liked($user_id, $post_id) : false;
$depth = $post['depth'] ?? 0;
$replies = $post['replies'] ?? [];
$csrf_token = generate_csrf_token();
$max_depth = 5;
?>

<div class="comment-thread" style="margin-left: <?= $depth * 30 ?>px; border-left: <?= $depth > 0 ? '2px solid #e0e0e0' : 'none' ?>; padding-left: <?= $depth > 0 ? '15px' : '0' ?>;">
    <div class="comment-item<?= (isset($highlight_id) && $highlight_id === $post_id) ? ' newly-created' : '' ?>" id="comment-<?= $post_id ?>">
        <div class="comment-header">
            <a href="<?= profile_url($author_username) ?>" class="comment-author">@<?= htmlspecialchars($author_username) ?></a>
            <span class="comment-time"><?= $created_at ?></span>
            <?php if (isset($highlight_id) && $highlight_id === $post_id): ?>
                <span class="highlight-label">Yanıtınız eklendi</span>
            <?php endif; ?>
            <?php if ($depth > 0): ?>
                <span style="color:#999;font-size:10px;background:#f0f0f0;padding:2px 6px;border-radius:3px;margin-left:5px;">↳ yanıt</span>
            <?php endif; ?>
        </div>

        <div class="comment-content">
            <?= nl2br(linkify_mentions($content)) ?>
        </div>

        <div class="comment-actions">
            <?php if ($user_id): ?>
                <form method="POST" action="<?= BASE_PATH ?>/api/like.php" class="action-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="post_id" value="<?= $post_id ?>">
                    <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <button type="submit" class="action-btn like-btn <?= $is_liked ? 'liked' : '' ?>">
                        <?= $is_liked ? '♥' : '♡' ?> <?= $likes_count ?> Beğen
                    </button>
                </form>

                <?php if ($depth < $max_depth): ?>
                    <?php $sid_param = !empty($_REQUEST['sid']) ? '?sid=' . urlencode($_REQUEST['sid']) : ''; ?>
                    <a href="<?= reply_url($root_post_id, $post_id) . $sid_param ?>" class="action-btn reply-btn">
                        ↩️ Yanıtla
                    </a>
                <?php endif; ?>

                <?php if ($user_id == $post['user_id']): ?>
                    <a href="<?= BASE_PATH ?>/edit_post.php?id=<?= $post_id ?>&return_id=<?= $root_post_id ?>" class="action-btn edit-btn">
                        ✏️ Düzenle
                    </a>
                    <a href="<?= BASE_PATH ?>/delete_post_confirm.php?id=<?= $post_id ?>&return_id=<?= $root_post_id ?>" class="action-btn delete-btn">
                        🗑️ Sil
                    </a>
                <?php else: ?>
                    <form method="POST" action="<?= BASE_PATH ?>/api/report.php" class="action-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="target_type" value="reply">
                        <input type="hidden" name="target_id" value="<?= $post_id ?>">
                        <input type="hidden" name="reason" value="spam">
                        <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                        <button type="submit" class="action-btn report-btn">
                            ⚠️ Bildir
                        </button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <a href="<?= BASE_PATH ?>/login.php" class="action-btn">♡ <?= $likes_count ?> Beğen</a>
                <a href="<?= BASE_PATH ?>/login.php" class="action-btn">↩️ Yanıtla</a>
                <a href="<?= BASE_PATH ?>/login.php" class="action-btn">⚠️ Bildir</a>
            <?php endif; ?>
        </div>

    </div>

    <!-- Nested Replies -->
    <?php if (!empty($replies)): ?>
        <div class="comment-replies">
            <?php foreach ($replies as $nested_reply): ?>
                <?php $reply = $nested_reply; require __DIR__ . '/reply-card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
