<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$comment_id = isset($_GET['comment_id']) ? (int)$_GET['comment_id'] : 0;
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
if (!$comment_id || !$event_id) {
    $_SESSION['flash_error'] = 'Geçersiz istek';
    header('Location: ' . events_url());
    exit;
}

$pdo = db_connect();
$stmt = $pdo->prepare("SELECT c.*, u.username, e.title FROM events_comments c JOIN users u ON c.user_id = u.id JOIN events e ON c.event_id = e.id WHERE c.id = ? AND c.event_id = ? LIMIT 1");
$stmt->execute([$comment_id, $event_id]);
$parent = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$parent) {
    $_SESSION['flash_error'] = 'Yorum bulunamadı';
    header('Location: ' . BASE_PATH . '/event_view.php?id=' . $event_id);
    exit;
}

// Handle reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_reply') {
    if (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_error'] = 'Geçersiz istek';
        header('Location: ' . BASE_PATH . '/event_view.php?id=' . $event_id);
        exit;
    }
    $content = trim($_POST['content'] ?? '');
    if ($content === '') {
        $_SESSION['flash_error'] = 'Yanıt boş olamaz';
        header('Location: ' . BASE_PATH . '/event_comment_reply.php?comment_id=' . $comment_id . '&event_id=' . $event_id);
        exit;
    }
    try {
        $filtered = censor_bad_words($content)['clean'];
        $ins = $pdo->prepare("INSERT INTO events_comments (event_id, user_id, parent_id, content, created_at) VALUES (?, ?, ?, ?, NOW())");
        $ins->execute([$event_id, $user_id, $comment_id, $filtered]);
        $newId = $pdo->lastInsertId();
        // increment replies_count on parent
        $pdo->prepare("UPDATE events_comments SET replies_count = COALESCE(replies_count,0) + 1 WHERE id = ?")->execute([$comment_id]);
        // notify parent comment author (if different)
        if (!empty($parent['user_id']) && $parent['user_id'] != $user_id) {
            try {
                $text = 'Yorumunuza etkinlikte yanıt geldi: ' . ($parent['title'] ?? 'Etkinlik');
                query("INSERT INTO notifications (user_id, type, from_user_id, post_id, created_at) VALUES (?, 'reply', ?, ?, NOW())", [$parent['user_id'], $user_id, $event_id]);
            } catch (Exception $_) { /* ignore */ }
        }
        $_SESSION['flash'] = 'Yanıtınız eklendi.';
        header('Location: ' . BASE_PATH . '/event_view.php?id=' . $event_id . '#comment-' . $newId);
        exit;
    } catch (Exception $e) {
        error_log('event_comment_reply error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Yanıt eklenemedi.';
        header('Location: ' . BASE_PATH . '/event_view.php?id=' . $event_id);
        exit;
    }
}

$csrf_token = generate_csrf_token();
?>

<div class="main-container single-column">
    <main class="content-area form-centered">
        <article class="card-box padded">
            <h1>↩️ Yorum Yanıtla</h1>
            <div class="panel-muted">
                <div class="small muted"><strong>@<?= htmlspecialchars($parent['username']) ?></strong> · <?= date('d.m.Y H:i', strtotime($parent['created_at'])) ?></div>
                <div><?= nl2br(linkify_text(htmlspecialchars($parent['content']))) ?></div>
            </div>

            <form method="POST" class="post-form">
                <input type="hidden" name="action" value="create_reply">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <textarea name="content" rows="4" required placeholder="Yanıtınızı yazın..."></textarea>
                <div class="post-form-actions">
                    <a href="<?= BASE_PATH ?>/event_view.php?id=<?= $event_id ?>" class="btn btn-cancel">İptal</a>
                    <button type="submit" class="btn btn-approve">↩️ Yanıtla</button>
                </div>
            </form>
        </article>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
