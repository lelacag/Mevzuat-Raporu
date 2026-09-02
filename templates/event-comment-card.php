<?php
// Template: event comment rendered as a postcard-style card (server-side, no-JS)
// Expects $cm (comment row) and $detail (event) to be in scope when included.
// Soft-deleted comments remain as placeholders when they have nested replies.
if (empty($cm) || !is_array($cm)) return;

$is_deleted = !empty($cm['deleted_at']);
$has_replies = !empty($cm['replies']) && is_array($cm['replies']);

// Defensive: skip rendering entirely empty placeholder rows (prevents blank cards showing)
if (!$is_deleted && empty(trim((string)($cm['content'] ?? ''))) && empty($cm['image_path']) && !$has_replies) return;

$comment_id = (int)$cm['id'];
$author = $cm['username'] ?? 'unknown';
$content = $cm['content'] ?? '';
$created = isset($cm['created_at']) ? format_time($cm['created_at']) : '';
$likes_count = (int)($cm['likes_count'] ?? 0);
$reports_count = (int)($cm['reports_count'] ?? 0);
$current_user_id = get_current_user_id();
$csrf_token = generate_csrf_token();
$is_admin = function_exists('is_admin') && is_admin();
$depth = (int)($cm['depth'] ?? 0);
?>
<article id="comment-<?= $comment_id ?>" class="postcard comment-card<?= $is_deleted ? ' comment-deleted' : '' ?>">
    <?php if (!$is_deleted && !empty($cm['image_path'])): ?>
        <div class="postcard-image"><img src="<?= htmlspecialchars($cm['image_path']) ?>" alt=""></div>
    <?php endif; ?>
    <div class="postcard-body">
        <div class="postcard-header">
            <?php if ($is_deleted): ?>
                <div class="postcard-title"><span class="comment-author-deleted">@silinmiş</span> <span class="deleted-badge">Silindi</span></div>
            <?php else: ?>
                <div class="postcard-title"><a href="<?= profile_url($author) ?>">@<?= htmlspecialchars($author) ?></a></div>
            <?php endif; ?>
            <div class="postcard-meta"><?= $created ?><?php if ($depth > 0): ?> <span class="reply-label">↳ yanıt</span><?php endif; ?></div>
        </div>

        <div class="postcard-content">
            <?php if ($is_deleted): ?>
                <div class="deleted-notice">Bu yanıt silinmiştir.</div>
            <?php elseif (!empty($_GET['edit_comment']) && (int)$_GET['edit_comment'] === $comment_id && ($current_user_id === (int)$cm['user_id'] || $is_admin)): ?>
                <form method="POST" action="<?= BASE_PATH ?>/api/event_comment_edit.php" class="comment-edit-form">
                    <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                    <input type="hidden" name="event_id" value="<?= (int)$detail['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="form-group"><textarea name="content" rows="3" required><?= htmlspecialchars($cm['content']) ?></textarea></div>
                    <div class="form-actions">
                        <button class="btn" type="submit">Kaydet</button>
                        <a class="btn btn-cancel" href="<?= htmlspecialchars(event_view_url((int)$detail['id'], $detail['title'] ?? '')) ?>#comment-<?= $comment_id ?>">İptal</a>
                    </div>
                </form>
            <?php else: ?>
                <?= nl2br(linkify_text(htmlspecialchars($content))) ?>
            <?php endif; ?>
        </div>

        <?php if ($has_replies): ?>
            <div class="reply-status">
                <?php if ($is_deleted): ?>
                    ↳ Bu silinmiş yanıta cevap var
                <?php else: ?>
                    ↳ Bu mesaja cevap var
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="comment-actions">
            <?php if ($current_user_id): ?>
                <?php if (!$is_deleted): ?>
                    <form method="POST" action="<?= BASE_PATH ?>/api/event_comment_like.php" class="action-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                        <input type="hidden" name="event_id" value="<?= (int)$detail['id'] ?>">
                        <input type="hidden" name="referer" value="<?= htmlspecialchars(event_view_url((int)$detail['id'], $detail['title'] ?? '')) ?>#comment-<?= $comment_id ?>">
                        <button class="action-btn like-btn" type="submit">♡ <?= $likes_count ?> Beğen</button>
                    </form>

                    <?php $sid_param = !empty($_REQUEST['sid']) ? '&sid=' . urlencode($_REQUEST['sid']) : ''; ?>
                    <a class="action-btn" href="<?= BASE_PATH ?>/event_comment_reply.php?comment_id=<?= $comment_id ?>&event_id=<?= (int)$detail['id'] ?><?= $sid_param ?>">↩️ Yanıtla</a>

                    <?php if ($current_user_id === (int)$cm['user_id'] || $is_admin): ?>
                        <a class="action-btn edit-btn" href="<?= htmlspecialchars(event_view_url((int)$detail['id'], $detail['title'] ?? '')) ?>?edit_comment=<?= $comment_id ?>#comment-<?= $comment_id ?>">✏️ Düzenle</a>
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
                        <form method="POST" action="<?= BASE_PATH ?>/api/event_comment_report.php" class="report-form">
                            <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                            <input type="hidden" name="event_id" value="<?= (int)$detail['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <div class="form-group"><label class="small">Rapor nedeni (opsiyonel)</label><input type="text" name="reason" maxlength="255"></div>
                            <div class="form-actions"><button class="btn btn-approve" type="submit">Raporu gönder</button> <a class="btn btn-cancel" href="<?= htmlspecialchars(event_view_url((int)$detail['id'], $detail['title'] ?? '')) ?>#comment-<?= $comment_id ?>">İptal</a></div>
                        </form>
                    <?php endif; ?>
                <?php elseif ($is_admin): ?>
                    <a class="action-btn delete-btn" href="<?= BASE_PATH ?>/event_comment_delete_confirm.php?id=<?= $comment_id ?>&event_id=<?= (int)$detail['id'] ?>">🗑️ Sil</a>
                <?php endif; ?>

            <?php else: ?>
                <?php if (!$is_deleted): ?>
                    <a class="action-btn" href="<?= BASE_PATH ?>/giris">♥ <?= $likes_count ?> Beğen</a>
                    <a class="action-btn" href="<?= BASE_PATH ?>/giris">⚠️ Raporla</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <!-- Nested replies -->
        <?php if ($has_replies): ?>
            <div class="comment-replies event-comment-replies">
                <?php foreach ($cm['replies'] as $nested): ?>
                    <?php if ($is_deleted && empty($nested['deleted_at'])): ?>
                        <div class="deleted-parent-indicator">Bu yanıt, silinmiş bir yanıta verilmiştir.</div>
                    <?php endif; ?>
                    <?php
                    $cm_parent = $cm;
                    $__saved_is_deleted = $is_deleted;
                    $cm = $nested;
                    if (!isset($cm['depth'])) {
                        $cm['depth'] = $depth + 1;
                    }
                    require __DIR__ . '/event-comment-card.php';
                    $cm = $cm_parent;
                    $is_deleted = $__saved_is_deleted;
                    unset($cm_parent, $__saved_is_deleted);
                    ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
