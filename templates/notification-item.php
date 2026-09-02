<?php
// Expects $notification to be in-scope
$icon_map = [
    'like' => '★',
    'reply' => '↩',
    'follow' => '+',
    'account_approved' => '✓',
    'report' => '!',
    'suspended' => '⛔',
    'unsuspended' => '✔',
    'mention' => '@',
    'system' => '🔔'
];
$type = $notification['type'];
$icon = $icon_map[$type] ?? '#';
$text_key = 'notification_' . $type;
$text = !empty($notification['text']) ? html_entity_decode($notification['text'], ENT_QUOTES, 'UTF-8') : t($text_key);
?>
<article class="notification <?= $notification['read_at'] ? '' : 'unread' ?>" id="notification-<?= $notification['id'] ?>">
    <div class="notification-icon"><?= $icon ?></div>
    <div class="notification-content">
        <p class="notification-text">
            <a href="<?= profile_url($notification['from_username']) ?>" class="notification-user">
                @<?= htmlspecialchars($notification['from_username']) ?>
            </a>
            <?= htmlspecialchars($text) ?>

            <?php if (!empty($notification['post_content'])):
                // Normalize whitespace and truncate to a safe preview length
                $raw_preview = preg_replace('/\s+/u', ' ', trim($notification['post_content']));
                $max_len = 80;
                if (mb_strlen($raw_preview) > $max_len) {
                    $short_preview = mb_substr($raw_preview, 0, $max_len - 3) . '...';
                } else {
                    $short_preview = $raw_preview;
                }
            ?>
                <span class="notification-preview">"<?= htmlspecialchars($short_preview, ENT_QUOTES, 'UTF-8') ?>"</span>
            <?php endif; ?>
        </p>

        <p class="notification-time">
            <?= format_time($notification['created_at']) ?>
            <?php if (!$notification['read_at']): ?>
                <span class="unread-badge"><?= t('notification_new') ?></span>
            <?php endif; ?>
        </p>
    </div>

    <div class="notification-actions">
        <?php if (!empty($notification['post_id'])): ?>
            <?php if (!empty($notification['post_content'])): ?>
                <a href="<?= post_url($notification['post_id']) ?>" class="notification-link"><?= t('notification_view') ?></a>
            <?php else: ?>
                <a href="<?= BASE_PATH ?>/group_post.php?id=<?= $notification['post_id'] ?>" class="notification-link"><?= t('notification_view') ?></a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$notification['read_at']): ?>
            <form method="POST" class="mark-read-form">
                <input type="hidden" name="action" value="mark_read">
                <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                <button type="submit" class="mark-read-btn"><?= t('notification_mark_read') ?></button>
            </form>
        <?php endif; ?>
    </div>
</article>