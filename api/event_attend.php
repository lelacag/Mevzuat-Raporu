<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!get_current_user_id()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = get_current_user_id();
$raw = file_get_contents('php://input');
$input = @json_decode($raw, true);
if (!is_array($input)) $input = $_POST;

$event_id = isset($input['event_id']) ? (int)$input['event_id'] : 0;
if (empty($event_id)) {
    echo json_encode(['success' => false, 'error' => 'Missing event id']);
    exit;
}

// CSRF for form posts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($input['csrf_token']) && !verify_csrf_token($input['csrf_token'])) {
    if (!empty($input['referer'])) {
        $_SESSION['flash_error'] = 'Geçersiz istek (CSRF).';
        header('Location: ' . $input['referer']);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF']);
    exit;
}

$pdo = db_connect();
// Ensure attendees table exists (safe to run repeatedly)
$pdo->exec("CREATE TABLE IF NOT EXISTS events_attendees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ux_event_user (event_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Toggle attendance: if exists -> remove, else insert
$stmt = $pdo->prepare("SELECT id FROM events_attendees WHERE event_id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$event_id, $user_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $del = $pdo->prepare("DELETE FROM events_attendees WHERE id = ?");
    $del->execute([(int)$row['id']]);
    $action = 'removed';
} else {
    $ins = $pdo->prepare("INSERT IGNORE INTO events_attendees (event_id, user_id) VALUES (?, ?)");
    $ins->execute([$event_id, $user_id]);
    $action = 'added';
}

// Return updated count
$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM events_attendees WHERE event_id = ?");
$stmt->execute([$event_id]);
$count = (int)$stmt->fetchColumn();

// If this was a form POST (non-AJAX) redirect back
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $_SESSION['flash'] = ($action === 'added') ? 'Katılımınız kaydedildi.' : 'Katılımınız kaldırıldı.';
    header('Location: ' . ($_POST['referer'] ?? BASE_PATH . '/event_view.php?id=' . $event_id));
    exit;
}

echo json_encode(['success' => true, 'action' => $action, 'count' => $count]);
