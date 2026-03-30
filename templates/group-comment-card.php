<?php
/**
 * Group Comment Card Template - Displays a single comment with threaded replies
 * Similar to reply-card.php but for group comments
 */
$comment = isset($comment) ? $comment : null;
if (!$comment) {
    return;
}

$comment_id = $comment['id'];
$group_post_id = $comment['post_id'] ?? ($_GET['id'] ?? 0);
$author_username = $comment['username'];
$content = $comment['content'];
$created_at = format_time($comment['created_at']);
$likes_count = $comment['likes_count'] ?? 0;
$user_id = get_current_user_id();
$is_liked = isset($comment['user_liked']) ? (bool)$comment['user_liked'] : false;
$depth = $comment['depth'] ?? 0;
$nested_comments = $comment['replies'] ?? [];
$csrf_token = generate_csrf_token();
$max_depth = 5;
?>

<div class="comment-thread" style="margin-left: <?= $depth * 30 ?>px; border-left: <?= $depth > 0 ? '2px solid #e0e0e0' : 'none' ?>; padding-left: <?= $depth > 0 ? '15px' : '0' ?>;">
    <div class="comment-item" id="group-comment-<?= $comment_id ?>" style="padding:15px 0;border-bottom:<?= $depth == 0 ? '1px solid #eee' : 'none' ?>;">
        <div class="comment-header" style="display:flex;align-items:baseline;gap:8px;margin-bottom:8px;">
            <a href="<?= profile_url($author_username) ?>" class="comment-author" style="color:#2e7d32;font-weight:600;">@<?= htmlspecialchars($author_username) ?></a>
            <span class="comment-time" style="color:#999;font-size:11px;"><?= $created_at ?></span>
            <?php if ($depth > 0): ?>
                <span style="color:#999;font-size:10px;background:#f0f0f0;padding:2px 6px;border-radius:3px;">↳ yanıt</span>
            <?php endif; ?>
        </div>

        <div class="comment-content" style="font-size:13px;color:#333;line-height:1.6;margin-bottom:10px;">
            <?= nl2br(linkify_mentions($content)) ?>
        </div>

        <div class="comment-actions">
            <?php if ($user_id): ?>
                <!-- Like Button -->
                <form method="POST" action="<?= BASE_PATH ?>/api/group_comment_like.php" class="action-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                    <input type="hidden" name="post_id" value="<?= $group_post_id ?>">
                    <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <button type="submit" class="action-btn like-btn <?= $is_liked ? 'liked' : '' ?>">
                        <?= $is_liked ? '♥' : '♡' ?> <?= $likes_count ?> Beğen
                    </button>
                </form>

                <?php if ($depth < $max_depth): ?>
                    <!-- Reply Button -->
                    <a href="<?= BASE_PATH ?>/group_comment_reply.php?comment_id=<?= $comment_id ?>&post_id=<?= $group_post_id ?>" class="action-btn reply-btn">
                        ↩️ Yanıtla
                    </a>
                <?php endif; ?>

                <?php if ($user_id == $comment['user_id']): ?>
                    <!-- Edit Button -->
                    <a href="<?= BASE_PATH ?>/group_comment_edit.php?id=<?= $comment_id ?>&post_id=<?= $group_post_id ?>" class="action-btn edit-btn">
                        ✏️ Düzenle
                    </a>
                    <!-- Delete Button -->
                    <a href="<?= BASE_PATH ?>/group_comment_delete_confirm.php?id=<?= $comment_id ?>&post_id=<?= $group_post_id ?>" class="action-btn delete-btn">
                        🗑️ Sil
                    </a>
                <?php else: ?>
                    <!-- Report Button -->
                    <form method="POST" action="<?= BASE_PATH ?>/api/report.php" class="action-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="target_type" value="group_comment">
                        <input type="hidden" name="target_id" value="<?= $comment_id ?>">
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
    <?php if (!empty($nested_comments)): ?>
        <div class="comment-replies" style="margin-top:5px;">
            <?php foreach ($nested_comments as $nested_comment): ?>
                <?php $comment = $nested_comment; require __DIR__ . '/group-comment-card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
