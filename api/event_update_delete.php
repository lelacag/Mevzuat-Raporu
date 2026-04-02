<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

$id = !empty($_POST['update_id']) ? (int)$_POST['update_id'] : 0;
$referer = $_POST['referer'] ?? BASE_PATH . '/admin/events.php';
if (empty($id)) {
    $_SESSION['flash_error'] = 'Geçersiz güncelleme ID.';
    header('Location: ' . $referer);
    exit;
}

$pdo = db_connect();
try {
    $stmt = $pdo->prepare("SELECT image_path FROM event_updates WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r && !empty($r['image_path'])) {
        // if image_path is a temp token URL, remove temp session file as well
        if (str_contains($r['image_path'], 'event_update_temp.php?token=')) {
            parse_str(parse_url($r['image_path'], PHP_URL_QUERY) ?? '', $qs);
            $t = $qs['token'] ?? null;
            if ($t && !empty($_SESSION['event_update_temp_files'][$t])) {
                $tmpf = $_SESSION['event_update_temp_files'][$t]['path'];
                if (file_exists($tmpf)) @unlink($tmpf);
                unset($_SESSION['event_update_temp_files'][$t]);
            }
        } else {
            $fp = __DIR__ . '/..' . $r['image_path'];
            if (file_exists($fp)) @unlink($fp);
        }
    }
    $del = $pdo->prepare("DELETE FROM event_updates WHERE id = ?");
    $del->execute([$id]);
    $_SESSION['flash'] = 'Güncelleme silindi.';
} catch (Exception $e) {
    error_log('[event_update_delete] ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Güncelleme silinemedi.';
}
header('Location: ' . $referer);
exit;