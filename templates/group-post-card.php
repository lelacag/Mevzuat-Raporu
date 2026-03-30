<?php
// Expects $gp array with keys: id, content, created_at, username, group_name, slug, like_count, comment_count, user_liked
// Uses $current_user_id and $csrf_token from global scope (header)
?>
<article class="post-card">
    <!-- Header: Icon + Username/Timestamp + Group -->
    <div class="post-card-header">
        <!-- Username & Timestamp & Group -->
        <div class="post-card-meta">
            <div class="post-card-meta-row">
                <a href="<?= user_url($gp['username']) ?>" class="post-card-username">
                    <?= htmlspecialchars($gp['username']) ?>
                </a>
                <?php
                    $pb = get_user_custom_badge($gp['user_id'] ?? null);
                    if ($pb && !empty($pb['badge_text'])):
                        $cls = badge_color_to_class($pb['badge_color']);
                ?>
                    <span class="custom-badge custom-badge-<?= $cls ?>"><?= htmlspecialchars($pb['badge_text']) ?></span>
                <?php endif; ?>
                <span style="color: #666; font-size: 11px;">·</span>
                <a href="<?= group_url($gp['slug'] ?? ($group['slug'] ?? '')) ?>" style="color: #666; text-decoration: none; font-size: 11px; display: inline-block;">
                    <?= htmlspecialchars($gp['group_name'] ?? ($group['name'] ?? '')) ?>
                </a>
            </div>
            <div class="post-card-time">
                <?= format_time($gp['created_at']) ?>
            </div>
        </div>
    </div>
    
    <!-- Post Content (aligned under username) -->
    <div class="post-card-content">
                <?= nl2br(linkify_mentions($gp['content'] ?? '')) ?>
    </div>

    <?php if (!empty($gp['poll'])): ?>
        <?php $poll = $gp['poll']; require __DIR__ . '/poll-block.php'; ?>
    <?php endif; ?>

    <?php $gp_test = $gp['test'] ?? get_test_for_group_post($gp['id']); ?>
    <?php if ($gp_test === null && array_key_exists('test', $gp)): ?>
        <?php error_log('group-post-card: test key exists for group_post id ' . (int)$gp['id'] . ' but value is null'); ?>
    <?php elseif (!empty($gp_test)): ?>
        <?php $test = $gp_test; require __DIR__ . '/test-block.php'; ?>
    <?php endif; ?>
    
    <!-- Action Buttons (aligned under content) -->
    <div class="post-card-actions">
        <?php if (!empty($current_user_id)): ?>
            <!-- Group Like Button (heart icon to match normal posts) -->
            <form method="POST" action="<?= BASE_PATH ?>/api/group_post_like.php" class="action-form">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="post_id" value="<?= (int)$gp['id'] ?>">
                <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                <button type="submit" class="action-btn like-btn <?= (!empty($gp['user_has_liked'])) ? 'liked' : '' ?>">
                    <?= (!empty($gp['user_has_liked'])) ? '♥' : '♡' ?> <?= (int)($gp['like_count'] ?? 0) ?> Beğen
                </button>
            </form>

            <!-- Comment Button -->
            <a href="<?= group_post_url($gp['slug'] ?? ($group['slug'] ?? ''), $gp['id']) ?>" class="action-btn reply-btn">
                💬 <?= (int)($gp['comment_count'] ?? 0) ?> Yorum
            </a>

            <?php if ($current_user_id == $gp['user_id']): ?>
                <!-- Edit Button -->
                <?php $edit_url = htmlspecialchars(BASE_PATH . '/group_post_edit.php?' . http_build_query(['id' => (int)$gp['id'], 'slug' => $gp['slug'] ?? ''])); ?>
                <a href="<?= $edit_url ?>" class="action-btn edit-btn">✏️ Düzenle</a>

                <!-- Delete Button -->
                <?php $del_url = htmlspecialchars(BASE_PATH . '/group_post_delete_confirm.php?' . http_build_query(['id' => (int)$gp['id'], 'slug' => $gp['slug'] ?? ''])); ?>
                <a href="<?= $del_url ?>" class="action-btn delete-btn">🗑️ Sil</a>
            <?php else: ?>
                <!-- Report Button -->
                <form method="POST" action="<?= BASE_PATH ?>/api/report.php" class="action-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="target_type" value="group_post">
                    <input type="hidden" name="target_id" value="<?= (int)$gp['id'] ?>">
                    <input type="hidden" name="reason" value="spam">
                    <input type="hidden" name="referer" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <button type="submit" class="action-btn report-btn">
                        ⚠️ Bildir
                    </button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <a href="<?= BASE_PATH ?>/login.php" class="action-btn">♡ <?= (int)$gp['like_count'] ?> Beğen</a>
            <a href="<?= group_post_url($gp['slug'], $gp['id']) ?>" class="action-btn">💬 <?= (int)$gp['comment_count'] ?> Yorum</a>
            <a href="<?= BASE_PATH ?>/login.php" class="action-btn">⚠️ Bildir</a>
        <?php endif; ?>
        <div class="post-card-icon">#<?= (int)$gp['id'] ?></div>
    </div>

    <?php if (!empty($current_user_id)): ?>
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
