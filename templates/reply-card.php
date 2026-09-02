<?php
/**
 * Reply / comment card template (supports nested replies).
 *
 * Callers may pass either:
 * - $reply (top-level loops), or
 * - $post already set to the reply row (nested re-entry).
 *
 * Nested includes must unset $reply before requiring this file so the outer
 * loop variable does not override the nested $post.
 */
$post = $post ?? null;
if ($post === null && isset($reply)) {
    $post = $reply;
}
if (!$post || !is_array($post)) {
    return;
}

$post_id = (int)($post['id'] ?? 0);
$root_post_id = isset($_GET['id']) ? (int)$_GET['id'] : $post_id;
$author_username = $post['username'] ?? '';
$content = $post['content'] ?? '';
$created_at = format_time($post['created_at'] ?? null);
$likes_count = (int)($post['likes_count'] ?? $post['like_count'] ?? 0);
$user_id = get_current_user_id();
$is_liked = $user_id && $post_id ? is_liked($user_id, $post_id) : false;
$depth = (int)($post['depth'] ?? 0);
$replies = $post['replies'] ?? [];
$csrf_token = generate_csrf_token();
$max_depth = 5;

// Soft-deleted reply: keep the node, show deleted indicator
$is_deleted = !empty($post['deleted_at']);
$is_admin = function_exists('is_admin') && is_admin();
$has_child_replies = !empty($replies) && is_array($replies);
?>

<div class="comment-thread comment-thread--indented">
    <div class="comment-item<?= $is_deleted ? ' comment-deleted' : '' ?><?= (isset($highlight_id) && (int)$highlight_id === $post_id) ? ' newly-created' : '' ?>" id="comment-<?= $post_id ?>">
        <div class="comment-header">
            <?php if ($is_deleted): ?>
                <span class="comment-author comment-author-deleted">@silinmiş</span>
            <?php else: ?>
                <a href="<?= profile_url($author_username) ?>" class="comment-author">@<?= htmlspecialchars($author_username) ?></a>
            <?php endif; ?>
            <span class="comment-time"><?= $created_at ?></span>
            <?php if ($is_deleted): ?>
                <span class="deleted-badge">Silindi</span>
            <?php endif; ?>
            <?php if (isset($highlight_id) && (int)$highlight_id === $post_id): ?>
                <span class="highlight-label">Yanıtınız eklendi</span>
            <?php endif; ?>
            <?php if ($depth > 0): ?>
                <span class="reply-label">↳ yanıt</span>
            <?php endif; ?>
        </div>

        <div class="comment-content">
            <?php if ($is_deleted): ?>
                <div class="deleted-notice">Bu yanıt silinmiştir.</div>
            <?php else: ?>
                <?= nl2br(linkify_mentions($content)) ?>
            <?php endif; ?>
        </div>

        <?php if ($has_child_replies): ?>
            <div class="reply-status">
                <?php if ($is_deleted): ?>
                    ↳ Bu silinmiş yanıta cevap var
                <?php else: ?>
                    ↳ Bu mesaja cevap var
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="comment-actions">
            <?php if ($user_id): ?>
                <?php if (!$is_deleted): ?>
                    <form method="POST" action="<?= BASE_PATH ?>/api/like.php" class="action-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="post_id" value="<?= $post_id ?>">
                        <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
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

                    <?php if ($user_id == ($post['user_id'] ?? 0) || $is_admin): ?>
                        <a href="<?= edit_post_url($post_id) . (isset($root_post_id) ? '?return_id=' . intval($root_post_id) : '') ?>" class="action-btn edit-btn">
                            ✏️ Düzenle
                        </a>
                        <a href="<?= BASE_PATH ?>/delete_post_confirm.php?id=<?= $post_id ?>&return_id=<?= (int)$root_post_id ?>" class="action-btn delete-btn">
                            🗑️ Sil
                        </a>
                    <?php else: ?>
                        <form method="POST" action="<?= BASE_PATH ?>/api/report.php" class="action-form">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="target_type" value="reply">
                            <input type="hidden" name="target_id" value="<?= $post_id ?>">
                            <input type="hidden" name="reason" value="spam">
                            <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
                            <button type="submit" class="action-btn report-btn">
                                ⚠️ Bildir
                            </button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($is_admin): ?>
                    <a href="<?= BASE_PATH ?>/delete_post_confirm.php?id=<?= $post_id ?>&return_id=<?= (int)$root_post_id ?>" class="action-btn delete-btn">
                        🗑️ Sil
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!$is_deleted): ?>
                    <a href="<?= BASE_PATH ?>/giris" class="action-btn">♥ <?= $likes_count ?> Beğen</a>
                    <a href="<?= BASE_PATH ?>/giris" class="action-btn">↩️ Yanıtla</a>
                    <a href="<?= BASE_PATH ?>/giris" class="action-btn">⚠️ Bildir</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($has_child_replies): ?>
        <div class="comment-replies">
            <?php foreach ($replies as $nested_reply): ?>
                <?php if ($is_deleted && empty($nested_reply['deleted_at'])): ?>
                    <div class="deleted-parent-indicator">Bu yanıt, silinmiş bir yanıta verilmiştir.</div>
                <?php endif; ?>
                <?php
                // Preserve parent context, then render nested card with $post only.
                $__saved_post = $post;
                $__saved_reply = $reply ?? null;
                $__saved_is_deleted = $is_deleted;
                $post = $nested_reply;
                unset($reply);
                require __DIR__ . '/reply-card.php';
                $post = $__saved_post;
                $is_deleted = $__saved_is_deleted;
                if ($__saved_reply !== null) {
                    $reply = $__saved_reply;
                }
                unset($__saved_post, $__saved_reply, $__saved_is_deleted);
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
