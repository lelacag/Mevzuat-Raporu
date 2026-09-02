<?php
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$comment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}
if (!$comment_id || !$event_id) {
    $_SESSION['flash_error'] = 'Geçersiz istek';
    header('Location: ' . events_url());
    exit;
}

$pdo = db_connect();
$stmt = $pdo->prepare("SELECT c.*, u.username, e.title FROM events_comments c JOIN users u ON c.user_id = u.id JOIN events e ON c.event_id = e.id WHERE c.id = ? AND c.event_id = ? AND c.deleted_at IS NULL LIMIT 1");
$stmt->execute([$comment_id, $event_id]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comment) {
    $_SESSION['flash_error'] = 'Yorum bulunamadı';
    header('Location: ' . event_view_url($event_id, ''));
    exit;
}

// ownership or admin
if ($comment['user_id'] != $user_id && !is_admin()) {
    $_SESSION['flash_error'] = 'Bu yorumu silme yetkiniz yok';
    header('Location: ' . event_view_url($event_id, $comment['title'] ?? ''));
    exit;
}

$has_child_replies = false;
try {
    $childStmt = $pdo->prepare("SELECT COUNT(*) FROM events_comments WHERE parent_id = ? AND deleted_at IS NULL");
    $childStmt->execute([$comment_id]);
    $has_child_replies = ((int)$childStmt->fetchColumn()) > 0;
} catch (Exception $e) {
    $has_child_replies = false;
}

?>
<div class="main-container single-column">
    <main class="content-area form-centered">
        <article class="card-box padded">
            <h1>Yorumu Sil</h1>
            <?php if ($has_child_replies): ?>
                <p>Bu yanıtın altında başka yanıtlar var. İçerik silinmiş olarak işaretlenecek; alt yanıtlar görünmeye devam edecek.</p>
            <?php else: ?>
                <p>Bu yorumu silmek istediğinize emin misiniz? Yorum silinmiş olarak işaretlenecektir.</p>
            <?php endif; ?>

            <div style="background:#fafafa;border:1px solid #eee;padding:12px;border-radius:6px;margin:12px 0;">
                <div style="font-size:13px;color:#666;margin-bottom:8px;"><strong>#<?= $comment_id ?></strong> — @<?= htmlspecialchars($comment['username']) ?> · <?= date('d.m.Y H:i', strtotime($comment['created_at'])) ?></div>
                <div style="font-size:14px;color:#222;line-height:1.5;"><?= nl2br(htmlspecialchars($comment['content'])) ?></div>
                <?php if (!empty($comment['image_path'])): ?>
                    <div style="margin-top:10px;"><img src="<?= htmlspecialchars($comment['image_path']) ?>" alt="" style="max-width:100%;border-radius:6px;"></div>
                <?php endif; ?>
            </div>

            <div style="display:flex;gap:10px;">
                <a href="<?= htmlspecialchars(event_view_url($event_id, $comment['title'] ?? '')) ?>#comment-<?= $comment_id ?>" class="btn btn-cancel">İptal</a>
                <form method="POST" action="<?= BASE_PATH ?>/api/event_comment_delete.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="comment_id" value="<?= $comment_id ?>">
                    <input type="hidden" name="event_id" value="<?= $event_id ?>">
                    <button type="submit" class="btn btn-danger">Evet, Sil</button>
                </form>
            </div>
        </article>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
