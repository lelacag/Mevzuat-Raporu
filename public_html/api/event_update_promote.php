<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

// CSRF protection for state-changing admin action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}

$promoted = 0;
$errors = [];
$uploadsRoot = __DIR__ . '/../assets/uploads';
if (!is_dir($uploadsRoot) || !is_writable($uploadsRoot)) {
    $_SESSION['flash_error'] = 'Uploads directory not writable; cannot promote temp files.';
    header('Location: ' . ($_POST['referer'] ?? BASE_PATH . '/events.php'));
    exit;
}

if (!empty($_SESSION['event_update_temp_files']) && is_array($_SESSION['event_update_temp_files'])) {
    foreach ($_SESSION['event_update_temp_files'] as $token => $info) {
        $tmpPath = $info['path'];
        $eventId = (int)($info['event_id'] ?? 0);
        if (!file_exists($tmpPath)) { $errors[] = "tmp missing: $token"; unset($_SESSION['event_update_temp_files'][$token]); continue; }
        $destDir = $uploadsRoot . '/event_updates/' . $eventId;
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        if (!is_writable($destDir)) { $errors[] = "dest not writable for event $eventId"; continue; }
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($info['name'], PATHINFO_FILENAME));
        $ext = pathinfo($info['name'], PATHINFO_EXTENSION);
        $filename = $safe . '_' . time() . '.' . $ext;
        $dest = $destDir . '/' . $filename;
        if (@rename($tmpPath, $dest)) {
            // Insert event_update record referencing the permanent path if not already present
            try {
                $pdo = db_connect();
                $stmt = $pdo->prepare("INSERT INTO event_updates (event_id, author_id, content, image_path) VALUES (?, ?, ?, ?)");
                $stmt->execute([$eventId, get_current_user_id(), null, '/assets/uploads/event_updates/' . $eventId . '/' . $filename]);
                $promoted++;
                unset($_SESSION['event_update_temp_files'][$token]);
            } catch (Exception $e) {
                $errors[] = 'db insert failed for token ' . $token . ' : ' . $e->getMessage();
            }
        } else {
            $errors[] = 'rename failed for token ' . $token;
        }
    }
}

if ($promoted > 0) $_SESSION['flash'] = "$promoted geçici dosya kalıcı olarak taşındı.";
if (!empty($errors)) $_SESSION['flash_error'] = implode('; ', $errors);
header('Location: ' . ($_POST['referer'] ?? BASE_PATH . '/events.php'));
exit;