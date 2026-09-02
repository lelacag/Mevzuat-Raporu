<?php
require_once __DIR__ . '/../includes/auth.php';

$user_id = get_current_user_id();
if (!$user_id) { $_SESSION['flash_error'] = 'Giriş yapmalısınız'; header('Location: ' . BASE_PATH . '/giris'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_PATH . '/events.php'); exit; }
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $_SESSION['flash_error'] = 'Geçersiz istek'; header('Location: ' . BASE_PATH . '/events.php'); exit; }

$comment_id = (int)($_POST['comment_id'] ?? 0);
$event_id = (int)($_POST['event_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
if (!$comment_id || !$event_id) { $_SESSION['flash_error'] = 'Geçersiz istek'; header('Location: ' . event_view_url($event_id, '')); exit; }

$pdo = db_connect();

$stmt = $pdo->prepare("SELECT id FROM events_comments WHERE id = ? LIMIT 1");
$stmt->execute([$comment_id]);
if (!$stmt->fetch()) { $_SESSION['flash_error'] = 'Yorum bulunamadı'; header('Location: ' . event_view_url($event_id, '')); exit; }

$ins = $pdo->prepare("INSERT INTO event_comment_reports (comment_id, user_id, reason, created_at) VALUES (?, ?, ?, NOW())");
$ins->execute([$comment_id, $user_id, $reason]);
$pdo->prepare("UPDATE events_comments SET reports_count = reports_count + 1 WHERE id = ?")->execute([$comment_id]);

$_SESSION['flash'] = 'Rapor gönderildi.';
header('Location: ' . event_view_url($event_id, ''));
exit;