<?php /* EN + TR comments used. */
// Event detail page (server-rendered, no-JS required)
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once __DIR__ . '/includes/header.php';

$current_user_id = get_current_user_id();
$csrf_token = generate_csrf_token();

// id required
if (empty($_GET['id'])) {
    header('Location: ' . events_url());
    exit;
}
$eid = intval($_GET['id']);

try {
    $db = db_connect();
    $stmt = $db->prepare("SELECT e.*, u.username AS creator_username, COALESCE(u.event_code, '') AS creator_event_code FROM events e LEFT JOIN users u ON e.created_by = u.id WHERE e.id = ? LIMIT 1");
    $stmt->execute([$eid]);
    $detail = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $detail = null;
}

if (!$detail) {
    http_response_code(404);
    echo '<div class="main-container single-column"><main class="content-area">';
    echo '<div class="card-box padded"><h1>Etkinlik bulunamadı</h1><p>Aradığınız etkinlik mevcut değil veya silinmiş.</p><p><a href="' . events_url() . '">Etkinlik listesine dön</a></p></div>';
    echo '</main></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// attendees
$attendees = [];
$attendees_count = 0;
$user_attending = false;
try {
    $aStmt = $db->prepare("SELECT COUNT(*) AS c FROM events_attendees WHERE event_id = ?");
    $aStmt->execute([$eid]);
    $attendees_count = (int)$aStmt->fetchColumn();

    $uStmt = $db->prepare("SELECT u.username FROM events_attendees ea JOIN users u ON ea.user_id = u.id WHERE ea.event_id = ? ORDER BY ea.created_at DESC LIMIT 50");
    $uStmt->execute([$eid]);
    $attendees = $uStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($current_user_id) {
        $check = $db->prepare("SELECT 1 FROM events_attendees WHERE event_id = ? AND user_id = ? LIMIT 1");
        $check->execute([$eid, $current_user_id]);
        $user_attending = (bool)$check->fetchColumn();
    }
} catch (Exception $e) {
    // ignore
}

// comments (simple, first page)
$comments = [];
$comments_count = 0;
try {
    $cStmt = $db->prepare("SELECT c.*, u.username FROM events_comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.event_id = ? ORDER BY c.created_at DESC LIMIT 250");
    $cStmt->execute([$eid]);
    $flat = $cStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build nested comment tree (one level deep; supports multiple levels)
    $byId = [];
    foreach ($flat as $r) { $r['replies'] = []; $byId[$r['id']] = $r; }
    $comments = [];
    foreach ($byId as $id => $row) {
        if (!empty($row['parent_id']) && isset($byId[$row['parent_id']])) {
            $byId[$row['parent_id']]['replies'][] = &$byId[$id];
        } else {
            $comments[] = &$byId[$id];
        }
    }

    // Now $comments contains root comments with nested 'replies' arrays

    // Diagnostic logging: number of flat rows vs root comments (helps find duplication)
    try {
        $flat_ids = array_map(function($r){ return $r['id']; }, $flat);
        error_log('event_view.php: comments flat_rows=' . count($flat) . ' root_comments=' . count($comments) . ' flat_ids_sample=' . implode(',', array_slice($flat_ids,0,8)) );
    } catch (Exception $_) {}

    $cCount = $db->prepare("SELECT COUNT(*) FROM events_comments WHERE event_id = ?");
    $cCount->execute([$eid]);
    $comments_count = (int)$cCount->fetchColumn();
} catch (Exception $e) {
    // ignore
}

// updates
$updates = [];
try {
    $up = $db->prepare("SELECT eu.*, u.username AS author_name FROM event_updates eu LEFT JOIN users u ON eu.author_id = u.id WHERE eu.event_id = ? ORDER BY eu.created_at DESC LIMIT 50");
    $up->execute([$eid]);
    $updates = $up->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

// include page-specific css
$view_css_path = __DIR__ . '/assets/css/event-view.css';
$view_ver = file_exists($view_css_path) ? filemtime($view_css_path) : time();
?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/event-view.css?v=<?= $view_ver ?>">

<div class="main-container single-column page-event-view">
    <main class="content-area form-centered">
        <article class="event-card detail">
            <div class="event-header">
                <h1 class="event-title"><?= htmlspecialchars($detail['title']) ?></h1>
                <div class="event-date">📅 <?= date('d.m.Y H:i', strtotime($detail['event_date'])) ?></div>
            </div>

            <div class="event-meta">Oluşturan: @<?= htmlspecialchars($detail['creator_username']) ?>
                <?php if (!empty($detail['creator_event_code']) && ($current_user_id === (int)$detail['created_by'] || is_admin())): ?> · <strong>Kod:</strong> <code><?= htmlspecialchars($detail['creator_event_code']) ?></code><?php endif; ?></div>

            <div class="event-description"><?= nl2br(linkify_text($detail['description'])) ?></div>

            <div class="event-actions">
                <?php if ($current_user_id): ?>
                    <form method="POST" action="<?= BASE_PATH ?>/api/event_attend.php" class="inline-form">
                        <input type="hidden" name="event_id" value="<?= $detail['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="referer" value="<?= BASE_PATH ?>/event_view.php?id=<?= $detail['id'] ?>">
                        <button type="submit" class="btn <?= $user_attending ? 'btn-cancel' : 'btn-approve' ?>"><?= $user_attending ? 'Katılmaktan Vazgeç' : 'Katılacağım' ?></button>
                    </form>
                    <a class="btn-attendees" href="<?= BASE_PATH ?>/event_attendees.php?id=<?= $detail['id'] ?>">Katılanlar (<?= $attendees_count ?>)</a>
                <?php else: ?>
                    <a href="<?= BASE_PATH ?>/login.php" class="btn btn-approve">Giriş yap ve katıl</a>
                <?php endif; ?>

                <!-- attendee-count removed (buttonized CTA moved next to attendance) -->
            </div>

            <?php if (!empty($updates)): ?>
                <section class="event-updates">
                    <?php foreach ($updates as $upd): ?>
                        <div class="postcard update-card">
                            <?php if (!empty($upd['image_path'])): ?>
                                <div class="postcard-image"><a href="#update-<?= (int)$upd['id'] ?>"><img src="<?= htmlspecialchars($upd['image_path']) ?>" alt=""></a></div>
                            <?php endif; ?>
                            <div class="postcard-body">
                                <div class="postcard-header">
                                    <div class="postcard-title"><?= !empty($upd['content']) ? htmlspecialchars(mb_strimwidth($upd['content'],0,80,'…')) : 'Etkinlik güncellemesi' ?></div>
                                    <div class="postcard-meta">@<?= htmlspecialchars($upd['author_name'] ?? 'admin') ?> · <?= date('d.m.Y H:i', strtotime($upd['created_at'])) ?></div>
                                </div>
                                <?php if (!empty($upd['content'])): ?>
                                    <div class="postcard-content"><?= nl2br(htmlspecialchars($upd['content'])) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <section class="comments-section">
                <h3>Yorumlar (<?= $comments_count ?>)</h3>
                <?php if ($current_user_id): ?>
                    <form method="POST" action="<?= BASE_PATH ?>/api/event_comment.php" class="comment-form" enctype="multipart/form-data">
                        <input type="hidden" name="event_id" value="<?= $detail['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="referer" value="<?= BASE_PATH ?>/event_view.php?id=<?= $detail['id'] ?>#comments">
                        <div class="form-group"><textarea name="content" rows="3" required placeholder="Etkinlik hakkında bir yorum yazın..."></textarea></div>
                        <?php if (is_admin()): ?>
                            <div class="form-group"><label class="small">Fotoğraf (admin için)</label><input type="file" name="image" accept="image/*"></div>
                        <?php endif; ?>
                        <div class="form-actions"><button class="btn btn-approve" type="submit">Yorumu gönder</button></div>
                    </form>



                <?php else: ?>
                    <div class="form-actions"><a class="btn btn-approve" href="<?= BASE_PATH ?>/login.php">Giriş yap ve yorum yap</a></div>
                <?php endif; ?>

                <div id="comments" class="comments-list">
                    <?php if (!empty($comments)): foreach ($comments as $cm): $cm = (array)$cm; require __DIR__ . '/templates/event-comment-card.php'; endforeach; else: ?>
                        <div class="empty-state">Henüz yorum yok.</div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- attendees-cta removed — CTA moved into actions area -->

            <div class="back-link"><a href="<?= events_url() ?>">← Tüm etkinliklere dön</a></div>
        </article>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
