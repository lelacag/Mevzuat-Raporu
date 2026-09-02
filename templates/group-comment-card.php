<?php
/**
 * Group Comment Card Template - Displays a single comment with threaded replies
 * Soft-deleted comments remain as placeholders when they have nested replies.
 */
$comment = isset($comment) ? $comment : null;
if (!$comment || !is_array($comment)) {
    return;
}

$comment_id = (int)($comment['id'] ?? 0);
$group_post_id = (int)($comment['post_id'] ?? ($_GET['id'] ?? 0));
$author_username = $comment['username'] ?? '';
$content = $comment['content'] ?? '';
$created_at = format_time($comment['created_at'] ?? null);
$likes_count = (int)($comment['likes_count'] ?? 0);
$user_id = get_current_user_id();
$is_liked = isset($comment['user_liked']) ? (bool)$comment['user_liked'] : false;
$depth = (int)($comment['depth'] ?? 0);
$nested_comments = $comment['replies'] ?? [];
$csrf_token = generate_csrf_token();
$max_depth = 5;
$is_deleted = !empty($comment['deleted_at']);
$is_admin = function_exists('is_admin') && is_admin();
$has_child_replies = !empty($nested_comments) && is_array($nested_comments);
?>

<div class="comment-thread">
    <div class="comment-item<?= $is_deleted ? ' comment-deleted' : '' ?>" id="group-comment-<?= $comment_id ?>">
        <div class="comment-header">
            <?php if ($is_deleted): ?>
                <span class="comment-author comment-author-deleted gc-author">@silinmiş</span>
            <?php else: ?>
                <a href="<?= profile_url($author_username) ?>" class="comment-author gc-author">@<?= htmlspecialchars($author_username) ?></a>
            <?php endif; ?>
            <span class="comment-time"><?= $created_at ?></span>
            <?php if ($is_deleted): ?>
                <span class="deleted-badge">Silindi</span>
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
                    <form method="POST" action="<?= BASE_PATH ?>/api/group_comment_like.php" class="action-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                        <input type="hidden" name="post_id" value="<?= $group_post_id ?>">
                        <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
                        <button type="submit" class="action-btn like-btn <?= $is_liked ? 'liked' : '' ?>">
                            <?= $is_liked ? '♥' : '♡' ?> <?= $likes_count ?> Beğen
                        </button>
                    </form>

                    <?php if ($depth < $max_depth): ?>
                        <a href="<?= BASE_PATH ?>/group_comment_reply.php?comment_id=<?= $comment_id ?>&post_id=<?= $group_post_id ?>" class="action-btn reply-btn">
                            ↩️ Yanıtla
                        </a>
                    <?php endif; ?>

                    <?php if ($user_id == ($comment['user_id'] ?? 0) || $is_admin): ?>
                        <a href="<?= BASE_PATH ?>/group_comment_edit.php?id=<?= $comment_id ?>&post_id=<?= $group_post_id ?>" class="action-btn edit-btn">
                            ✏️ Düzenle
                        </a>
                        <a href="<?= BASE_PATH ?>/group_comment_delete_confirm.php?id=<?= $comment_id ?>&post_id=<?= $group_post_id ?>" class="action-btn delete-btn">
                            🗑️ Sil
                        </a>
                    <?php else: ?>
                        <form method="POST" action="<?= BASE_PATH ?>/api/report.php" class="action-form">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="target_type" value="group_comment">
                            <input type="hidden" name="target_id" value="<?= $comment_id ?>">
                            <input type="hidden" name="reason" value="spam">
                            <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
                            <button type="submit" class="action-btn report-btn">
                                ⚠️ Bildir
                            </button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($is_admin): ?>
                    <a href="<?= BASE_PATH ?>/group_comment_delete_confirm.php?id=<?= $comment_id ?>&post_id=<?= $group_post_id ?>" class="action-btn delete-btn">
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
            <?php foreach ($nested_comments as $nested_comment): ?>
                <?php if ($is_deleted && empty($nested_comment['deleted_at'])): ?>
                    <div class="deleted-parent-indicator">Bu yanıt, silinmiş bir yanıta verilmiştir.</div>
                <?php endif; ?>
                <?php
                $__saved_comment = $comment;
                $__saved_is_deleted = $is_deleted;
                $comment = $nested_comment;
                require __DIR__ . '/group-comment-card.php';
                $comment = $__saved_comment;
                $is_deleted = $__saved_is_deleted;
                unset($__saved_comment, $__saved_is_deleted);
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
