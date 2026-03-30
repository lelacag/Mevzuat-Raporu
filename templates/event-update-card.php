<article class="post-card event-update-card">
    <div class="post-card-header">
        <div class="post-card-meta">
            <div class="post-card-meta-row">
                <a href="<?= BASE_PATH ?>/event_view.php?id=<?= (int)$eu['event_id'] ?>" class="post-card-username">📅 <?= htmlspecialchars($eu['event_title'] ?? 'Etkinlik') ?></a>
                <div class="post-card-time">· <?= date('d.m.Y H:i', strtotime($eu['created_at'])) ?></div>
            </div>
        </div>
    </div>

    <div class="post-card-content">
        <?php if (!empty($eu['image_path'])): ?>
            <div style="margin-bottom:10px;"><a href="<?= BASE_PATH ?>/event_view.php?id=<?= (int)$eu['event_id'] ?>"><img src="<?= htmlspecialchars($eu['image_path']) ?>" alt="" style="max-width:100%;border-radius:6px;" /></a></div>
        <?php endif; ?>
        <?php if (!empty($eu['content'])): ?>
            <div><?= nl2br(htmlspecialchars($eu['content'])) ?></div>
        <?php endif; ?>
        <div class="post-card-actions" style="margin-top:8px;">
            <a href="<?= BASE_PATH ?>/event_view.php?id=<?= (int)$eu['event_id'] ?>">Etkinlik sayfasına git</a>
        </div>
    </div>
</article>