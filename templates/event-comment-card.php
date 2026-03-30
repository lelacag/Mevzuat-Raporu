<?php
// Template: event comment rendered as a postcard-style card (server-side, no-JS)
// Expects $cm (comment row) and $detail (event) to be in scope when included.
if (empty($cm) || !is_array($cm)) return;
// Defensive: skip rendering entirely empty placeholder rows (prevents blank cards showing)
if (empty(trim((string)($cm['content'] ?? ''))) && empty($cm['image_path']) && empty($cm['replies']) ) return;
$comment_id = (int)$cm['id'];
$author = $cm['username'] ?? 'unknown';
$content = $cm['content'] ?? '';
$created = isset($cm['created_at']) ? format_time($cm['created_at']) : '';
$likes_count = (int)($cm['likes_count'] ?? 0);
$reports_count = (int)($cm['reports_count'] ?? 0);
$current_user_id = get_current_user_id();
$csrf_token = generate_csrf_token();
?>
<article id="comment-<?= $comment_id ?>" class="postcard comment-card">
    <?php if (!empty($cm['image_path'])): ?>
        <div class="postcard-image"><img src="<?= htmlspecialchars($cm['image_path']) ?>" alt=""></div>
    <?php endif; ?>
    <div class="postcard-body">
        <div class="postcard-header">
            <div class="postcard-title"><a href="<?= profile_url($author) ?>">@<?= htmlspecialchars($author) ?></a></div>
            <div class="postcard-meta"><?= $created ?></div>
        </div>

        <div class="postcard-content">
            <?php if (!empty($_GET['edit_comment']) && (int)$_GET['edit_comment'] === $comment_id && ($current_user_id === (int)$cm['user_id'] || is_admin())): ?>
                <form method="POST" action="<?= BASE_PATH ?>/api/event_comment_edit.php" class="comment-edit-form">
                    <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                    <input type="hidden" name="event_id" value="<?= (int)$detail['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="form-group"><textarea name="content" rows="3" required><?= htmlspecialchars($cm['content']) ?></textarea></div>
                    <div class="form-actions">
                        <button class="btn" type="submit">Kaydet</button>
                        <a class="btn btn-cancel" href="<?= BASE_PATH ?>/event_view.php?id=<?= (int)$detail['id'] ?>#comment-<?= $comment_id ?>">İptal</a>
                    </div>
                </form>
            <?php else: ?>
                <?= nl2br(linkify_text(htmlspecialchars($content))) ?>
            <?php endif; ?>
        </div>

        <div class="comment-actions">
            <?php if ($current_user_id): ?>
                <form method="POST" action="<?= BASE_PATH ?>/api/event_comment_like.php" class="action-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                    <input type="hidden" name="event_id" value="<?= (int)$detail['id'] ?>">
                    <input type="hidden" name="referer" value="<?= BASE_PATH ?>/event_view.php?id=<?= (int)$detail['id'] ?>#comment-<?= $comment_id ?>">
                    <button class="action-btn like-btn" type="submit">♡ <?= $likes_count ?> Beğen</button>
                </form>

                <?php $sid_param = !empty($_REQUEST['sid']) ? '&sid=' . urlencode($_REQUEST['sid']) : ''; ?>
                <a class="action-btn" href="<?= BASE_PATH ?>/event_comment_reply.php?comment_id=<?= $comment_id ?>&event_id=<?= (int)$detail['id'] ?><?= $sid_param ?>">↩️ Yanıtla</a>

                <?php if ($current_user_id === (int)$cm['user_id'] || is_admin()): ?>
                    <a class="action-btn edit-btn" href="<?= BASE_PATH ?>/event_view.php?id=<?= (int)$detail['id'] ?>&edit_comment=<?= $comment_id ?>#comment-<?= $comment_id ?>">✏️ Düzenle</a>
                    <a class="action-btn delete-btn" href="<?= BASE_PATH ?>/event_comment_delete_confirm.php?id=<?= $comment_id ?>&event_id=<?= (int)$detail['id'] ?>">🗑️ Sil</a>
                <?php else: ?>
                    <?php if (empty($_GET['report_comment']) || (int)$_GET['report_comment'] !== $comment_id): ?>
                        <form method="POST" action="<?= BASE_PATH ?>/api/event_comment_report.php" class="action-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                            <input type="hidden" name="event_id" value="<?= (int)$detail['id'] ?>">
                            <input type="hidden" name="reason" value="spam">
                            <button class="action-btn report-btn" type="submit">⚠️ Raporla</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($reports_count > 0): ?><span class="small muted">Raporlar: <?= $reports_count ?></span><?php endif; ?>

                <?php if (!empty($_GET['report_comment']) && (int)$_GET['report_comment'] === $comment_id): ?>
                    <form method="POST" action="<?= BASE_PATH ?>/api/event_comment_report.php" class="report-form" style="margin-top:8px;">
                        <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                        <input type="hidden" name="event_id" value="<?= (int)$detail['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="form-group"><label class="small">Rapor nedeni (opsiyonel)</label><input type="text" name="reason" maxlength="255"></div>
                        <div class="form-actions"><button class="btn btn-approve" type="submit">Raporu gönder</button> <a class="btn btn-cancel" href="<?= BASE_PATH ?>/event_view.php?id=<?= (int)$detail['id'] ?>#comment-<?= $comment_id ?>">İptal</a></div>
                    </form>
                <?php endif; ?>

            <?php else: ?>
                <a class="action-btn" href="<?= BASE_PATH ?>/login.php">♡ <?= $likes_count ?> Beğen</a>
                <a class="action-btn" href="<?= BASE_PATH ?>/login.php">⚠️ Raporla</a>
            <?php endif; ?>
        </div>
        <!-- Nested replies -->
        <?php if (!empty($cm['replies'])): ?>
            <div class="comment-replies" style="margin-left:18px;margin-top:8px;">
                <?php foreach ($cm['replies'] as $nested): $cm_parent = $cm; $cm = $nested; require __DIR__ . '/event-comment-card.php'; $cm = $cm_parent; endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
