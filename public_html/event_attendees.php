<?php
require_once __DIR__ . '/includes/header.php';
$current_user_id = get_current_user_id();

if (empty($_GET['id'])) {
    header('Location: ' . events_url());
    exit;
}
$eid = (int)$_GET['id'];

try {
    $db = db_connect();
    $stmt = $db->prepare("SELECT e.id, e.title, e.event_date, u.username AS creator_username FROM events e LEFT JOIN users u ON e.created_by = u.id WHERE e.id = ? LIMIT 1");
    $stmt->execute([$eid]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $event = null;
}

if (!$event) {
    http_response_code(404);
    echo '<div class="main-container single-column"><main class="content-area"><div class="card-box padded"><h1>Etkinlik bulunamadı</h1><p>Belirttiğiniz etkinlik mevcut değil.</p><p><a href="' . events_url() . '">Etkinlik listesine dön</a></p></div></main></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$attendees = [];
$attendees_count = 0;
try {
    $c = $db->prepare("SELECT COUNT(*) FROM events_attendees WHERE event_id = ?");
    $c->execute([$eid]);
    $attendees_count = (int)$c->fetchColumn();

    $q = $db->prepare("SELECT u.username FROM events_attendees ea JOIN users u ON ea.user_id = u.id WHERE ea.event_id = ? ORDER BY ea.created_at ASC LIMIT 1000");
    $q->execute([$eid]);
    $attendees = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $attendees = [];
    $attendees_count = 0;
}

// page CSS already scoped in event-view.css
$view_css_path = __DIR__ . '/assets/css/event-view.css';
$view_ver = file_exists($view_css_path) ? filemtime($view_css_path) : time();
?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/event-view.css?v=<?= $view_ver ?>">
<div class="main-container single-column page-event-attendees">
    <main class="content-area form-centered">
        <article class="card-box padded">
            <h1>Katılanlar (<?= $attendees_count ?>)</h1>
            <div class="event-meta">Etkinlik: <strong><?= htmlspecialchars($event['title']) ?></strong> · Oluşturan: @<?= htmlspecialchars($event['creator_username']) ?></div>

            <?php if ($attendees_count === 0): ?>
                <div class="empty-state" style="margin-top:18px;">Henüz katılımcı yok.</div>
            <?php else: ?>
                <ul class="attendee-list" style="margin-top:12px;">
                    <?php foreach ($attendees as $a): ?>
                        <li class="attendee-row"><a href="<?= BASE_PATH ?>/profile.php?u=<?= rawurlencode($a['username']) ?>">@<?= htmlspecialchars($a['username']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="back-link" style="margin-top:16px;"><a href="<?= htmlspecialchars(event_view_url($event['id'], $event['title'] ?? '')) ?>">← Etkinlik detayına dön</a></div>
        </article>
    </main>
</div>
<?php require_once __DIR__ . '/includes/footer.php';
