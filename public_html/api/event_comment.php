<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!get_current_user_id()) {
    http_response_code(403);
    exit('Unauthorized');
}

require_csrf();

// Accept form POST only (no JSON for now)
$input = $_POST;
$event_id = isset($input['event_id']) ? (int)$input['event_id'] : 0;
$parent_id = isset($input['parent_id']) && (int)$input['parent_id'] > 0 ? (int)$input['parent_id'] : null;
$content = trim($input['content'] ?? '');
$referer = $input['referer'] ?? event_view_url($event_id, '');

if (empty($event_id) || $content === '') {
    $_SESSION['flash_error'] = 'Etkinlik veya yorum içeriği eksik.';
    header('Location: ' . $referer);
    exit;
}

$pdo = db_connect();
error_log('api/event_comment.php: POST from user=' . get_current_user_id() . ' event_id=' . intval($event_id));

// Optional image upload — open to all logged-in users; photo also added to event gallery
$image_path = null;
if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['image'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_error'] = 'Yükleme hatası (kod: ' . $f['error'] . ').';
        header('Location: ' . $referer); exit;
    }
    if ($f['size'] > 5 * 1024 * 1024) {
        $_SESSION['flash_error'] = 'Dosya çok büyük (maks 5MB).';
        header('Location: ' . $referer); exit;
    }
    // MIME detection with extension fallback (finfo not always available)
    $allowed_mimes = ['image/jpeg','image/png','image/gif','image/webp'];
    $mime = null;
    if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); if ($fi) { $mime = finfo_file($fi, $f['tmp_name']); finfo_close($fi); } }
    if (!$mime && function_exists('mime_content_type')) { $mime = mime_content_type($f['tmp_name']); }
    if (!$mime) {
        $extMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
        $mime = $extMap[strtolower(pathinfo($f['name'], PATHINFO_EXTENSION))] ?? null;
    }
    if (!$mime || !in_array($mime, $allowed_mimes, true)) {
        $_SESSION['flash_error'] = 'Geçersiz dosya türü. Yalnızca JPEG, PNG, GIF, WebP kabul edilir.';
        header('Location: ' . $referer); exit;
    }
    $ext2     = ($mime === 'image/jpeg') ? 'jpg' : (($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'gif'));
    $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext2;
    $uploadDir = realpath(__DIR__ . '/../tmp/events') . '/event_' . $event_id . '/images';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
    $dest = $uploadDir . '/' . $filename;
    if (!@move_uploaded_file($f['tmp_name'], $dest)) {
        $_SESSION['flash_error'] = 'Dosya kaydedilemedi.';
        header('Location: ' . $referer); exit;
    }
    // Insert into event_images so it appears in the gallery
    try {
        $pdo->prepare("INSERT INTO event_images (event_id, filename, sort_order) VALUES (?, ?, 0)")
            ->execute([$event_id, $filename]);
    } catch (Exception $e) { error_log('event_comment: event_images insert failed: ' . $e->getMessage()); }
    $image_path = BASE_PATH . '/event_img.php?event=' . $event_id . '&type=images&file=' . urlencode($filename);
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
        $referer = event_view_url(intval($event_id), '') . '#comment-' . intval($inserted_id);
    }

    $_SESSION['flash'] = 'Yorum eklendi.';
} catch (Exception $e) {
    error_log('event_comment insert error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Yorum eklenemedi.';
}

header('Location: ' . $referer);
exit;