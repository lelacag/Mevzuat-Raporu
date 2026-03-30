<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!get_current_user_id()) {
    http_response_code(403);
    exit('Unauthorized');
}

// Accept form POST only (no JSON for now)
$input = $_POST;
$event_id = isset($input['event_id']) ? (int)$input['event_id'] : 0;
$parent_id = isset($input['parent_id']) && (int)$input['parent_id'] > 0 ? (int)$input['parent_id'] : null;
$content = trim($input['content'] ?? '');
$referer = $input['referer'] ?? BASE_PATH . '/event_view.php?id=' . $event_id;

if (empty($event_id) || $content === '') {
    $_SESSION['flash_error'] = 'Etkinlik veya yorum içeriği eksik.';
    header('Location: ' . $referer);
    exit;
}

// CSRF
if (empty($input['csrf_token']) || !verify_csrf_token($input['csrf_token'])) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF).';
    header('Location: ' . $referer);
    exit;
}

$pdo = db_connect();
error_log('api/event_comment.php: POST from user=' . get_current_user_id() . ' event_id=' . intval($event_id));
// Create table if missing
$pdo->exec("CREATE TABLE IF NOT EXISTS events_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    image_path VARCHAR(1024) DEFAULT NULL,
    likes_count INT NOT NULL DEFAULT 0,
    reports_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    INDEX idx_event (event_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Optional image upload (admin only)
$image_path = null;
if (!empty($_FILES['image']) && is_admin()) {
    $f = $_FILES['image'];
    if ($f['error'] === UPLOAD_ERR_OK && $f['size'] > 0) {
        $uploadsRoot = __DIR__ . '/../assets/uploads';
        $commentDir = $uploadsRoot . '/event_comments/' . $event_id;
        if (!is_dir($commentDir)) @mkdir($commentDir, 0755, true);
        $basename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($f['name']));
        $filename = time() . '_' . random_bytes(4) . '_' . $basename;
        $dest = $commentDir . '/' . $filename;
        if (@move_uploaded_file($f['tmp_name'], $dest)) {
            $image_path = '/assets/uploads/event_comments/' . $event_id . '/' . $filename;
        } else {
            // fallback: store as session-temp and expose via existing temp handler
            $tmpPath = sys_get_temp_dir() . '/' . uniqid('evtcm_') . '_' . $basename;
            if (@move_uploaded_file($f['tmp_name'], $tmpPath)) {
                if (!isset($_SESSION['event_update_temp_files'])) $_SESSION['event_update_temp_files'] = [];
                $tok = bin2hex(random_bytes(12));
                $_SESSION['event_update_temp_files'][$tok] = ['path' => $tmpPath, 'name' => $f['name'], 'created' => time(), 'user' => get_current_user_id(), 'event_id' => $event_id];
                $image_path = '/api/event_update_temp.php?tok=' . $tok;
            }
        }
    }
}

try {
    $stmt = $pdo->prepare("INSERT INTO events_comments (event_id, user_id, parent_id, content, image_path) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$event_id, get_current_user_id(), $parent_id, $content, $image_path]);
    $inserted_id = $pdo->lastInsertId();

    // If this was a reply, increment replies_count on parent and notify the parent author
    if (!empty($parent_id)) {
        try {
            $pdo->prepare("UPDATE events_comments SET replies_count = COALESCE(replies_count,0) + 1 WHERE id = ?")->execute([$parent_id]);
        } catch (Exception $_) { /* ignore */ }

        // Create a notification for the parent comment author (if different)
        try {
            $pStmt = $pdo->prepare("SELECT user_id FROM events_comments WHERE id = ? LIMIT 1");
            $pStmt->execute([$parent_id]);
            $prow = $pStmt->fetch(PDO::FETCH_ASSOC);
            $parent_user_id = $prow ? (int)$prow['user_id'] : 0;
            $actor = get_current_user_id();
            if ($parent_user_id && $parent_user_id !== $actor) {
                $ins = $pdo->prepare("INSERT INTO notifications (user_id, type, from_user_id, post_id, created_at) VALUES (?, 'reply', ?, ?, NOW())");
                $ins->execute([$parent_user_id, $actor, $event_id]);
            }
        } catch (Exception $_) {
            // ignore notification failures
        }

        // For replies, override referer so the user is taken to the event page and anchored to the new reply
        $referer = BASE_PATH . '/event_view.php?id=' . intval($event_id) . '#comment-' . intval($inserted_id);
    }

    $_SESSION['flash'] = 'Yorum eklendi.';
} catch (Exception $e) {
    error_log('event_comment insert error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Yorum eklenemedi.';
}

header('Location: ' . $referer);
exit;