<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!get_current_user_id()) {
    $_SESSION['flash_error'] = 'Lütfen giriş yapın.';
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

require_csrf();

$user_id = get_current_user_id();
$input = $_POST;

$event_id = isset($input['event_id']) ? (int)$input['event_id'] : 0;
if (empty($event_id)) {
    $_SESSION['flash_error'] = 'Geçersiz etkinlik.';
    header('Location: ' . BASE_PATH . '/');
    exit;
}

$pdo = db_connect();

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

$_SESSION['flash'] = ($action === 'added') ? 'Katılımınız kaydedildi.' : 'Katılımınız kaldırıldı.';
header('Location: ' . ($_POST['referer'] ?? event_view_url($event_id, '')));
exit;
