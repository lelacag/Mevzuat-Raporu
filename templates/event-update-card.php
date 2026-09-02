<article class="post-card event-update-card">
    <div class="post-card-header">
        <div class="post-card-meta">
            <div class="post-card-meta-row">
                <a href="<?= htmlspecialchars(event_view_url((int)$eu['event_id'], $eu['event_title'] ?? '')) ?>" class="post-card-username">📅 <?= htmlspecialchars($eu['event_title'] ?? 'Etkinlik') ?></a>
                <div class="post-card-time">· <?= date('d.m.Y H:i', strtotime($eu['created_at'])) ?></div>
            </div>
        </div>
    </div>

    <div class="post-card-content">
        <?php if (!empty($eu['image_path'])): ?>
            <div class="eu-image-wrap"><a href="<?= htmlspecialchars(event_view_url((int)$eu['event_id'], $eu['event_title'] ?? '')) ?>"><img src="<?= htmlspecialchars($eu['image_path']) ?>" alt="" /></a></div>
        <?php endif; ?>
        <?php if (!empty($eu['content'])): ?>
            <div><?= nl2br(htmlspecialchars($eu['content'])) ?></div>
        <?php endif; ?>
        <div class="post-card-actions mt-8">
            <a href="<?= htmlspecialchars(event_view_url((int)$eu['event_id'], $eu['event_title'] ?? '')) ?>">Etkinlik sayfasına git</a>
        </div>
    </div>
</article>