<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Only admins allowed to post event updates
if (!is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

$input = $_POST;
$event_id = isset($input['event_id']) ? (int)$input['event_id'] : 0;
$content = trim($input['content'] ?? '');
$referer = $input['referer'] ?? BASE_PATH . '/event_view.php?id=' . $event_id;

if (empty($event_id) || ($content === '' && empty($_FILES['image']['name'] ?? ''))) {
    $_SESSION['flash_error'] = 'Güncelleme içeriği veya fotoğraf gerekli.';
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
// Ensure table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS event_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    author_id INT NOT NULL,
    content TEXT DEFAULT NULL,
    image_path VARCHAR(1024) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_event (event_id),
    INDEX ix_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$image_path = null;
// Handle image upload if present
if (!empty($_FILES['image']['name'])) {
    $f = $_FILES['image'];
    // Handle common upload errors with clearer messages
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $msg = match ($f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Yüklenen dosya izin verilen boyuttan büyük.',
            UPLOAD_ERR_PARTIAL => 'Dosya kısmen yüklendi. Tekrar deneyin.',
            UPLOAD_ERR_NO_FILE => 'Dosya seçilmedi.',
            UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici dizin yok.',
            UPLOAD_ERR_CANT_WRITE => 'Sunucu diske yazamıyor.',
            UPLOAD_ERR_EXTENSION => 'Dosya yükleme eklentisi engelledi.',
            default => 'Dosya yükleme hatası (' . intval($f['error']) . ').'
        };
        $_SESSION['flash_error'] = $msg;
        error_log('[event_update] upload error for event ' . $event_id . ': ' . $msg . ' (user=' . get_current_user_id() . ')');
        header('Location: ' . $referer);
        exit;
    }

    // Validate type and size (JPEG/PNG/GIF up to 3MB)
    $allowed = ['image/jpeg','image/png','image/gif'];
    $maxBytes = 3 * 1024 * 1024;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed)) {
        $_SESSION['flash_error'] = 'Sadece JPG/PNG/GIF desteklenir.';
        error_log('[event_update] invalid mime ' . $mime . ' for user=' . get_current_user_id());
        header('Location: ' . $referer);
        exit;
    }
    if ($f['size'] > $maxBytes) {
        $_SESSION['flash_error'] = 'Dosya boyutu 3 MB üstünde olamaz.';
        error_log('[event_update] file too large (' . $f['size'] . ') for user=' . get_current_user_id());
        header('Location: ' . $referer);
        exit;
    }

    // Ensure top-level uploads dir exists and is writable
    $uploadsRoot = __DIR__ . '/../assets/uploads';
    if (!is_dir($uploadsRoot)) {
        @mkdir($uploadsRoot, 0755, true);
    }

    $uploadDir = $uploadsRoot . '/event_updates/' . $event_id;
    if (!is_dir($uploadDir)) {
        // Try creating parent then child; if fails, return clear admin message with remediation steps
        if (!@mkdir($uploadDir, 0755, true)) {
            error_log('[event_update] mkdir failed for ' . $uploadDir . ' user=' . get_current_user_id());
            $_SESSION['flash_error'] = 'Sunucuda dizin oluşturulamadı. Lütfen web sunucusunun "assets/uploads" dizinine yazma izni olduğundan emin olun (chown/chmod).';
            header('Location: ' . $referer);
            exit;
        }
    }

    if (!is_writable($uploadDir)) {
        @chmod($uploadDir, 0755);
        if (!is_writable($uploadDir)) {
            error_log('[event_update] uploadDir not writable: ' . $uploadDir);
            $_SESSION['flash_error'] = 'Yükleme dizinine yazılamıyor; sistem yöneticiniz ile iletişime geçin.';
            header('Location: ' . $referer);
            exit;
        }
    }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
    $filename = $safe . '_' . time() . '.' . $ext;
    $dest = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        error_log('[event_update] move_uploaded_file failed tmp=' . ($f['tmp_name'] ?? '') . ' dest=' . $dest . ' user=' . get_current_user_id());
        $_SESSION['flash_error'] = 'Dosya kaydedilemedi.';
        header('Location: ' . $referer);
        exit;
    }

    // Ensure file is readable by webserver
    @chmod($dest, 0644);
    $image_path = '/assets/uploads/event_updates/' . $event_id . '/' . $filename;
} else {
    // FALLBACK: uploads directory not writable — store file in PHP temp and provide a secure temporary URL
    $tmpDir = sys_get_temp_dir();
    $tok = bin2hex(random_bytes(12));
    $tmpName = $tok . '_' . basename($f['name']);
    $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . $tmpName;
    if (!move_uploaded_file($f['tmp_name'], $tmpPath)) {
        error_log('[event_update] fallback move_uploaded_file failed tmp=' . ($f['tmp_name'] ?? '') . ' dest=' . $tmpPath . ' user=' . get_current_user_id());
        $_SESSION['flash_error'] = 'Dosya kaydedilemedi (geçici).';
        header('Location: ' . $referer);
        exit;
    }
    // Register in session for serving via temporary token endpoint (admin-only)
    if (!isset($_SESSION['event_update_temp_files'])) $_SESSION['event_update_temp_files'] = [];
    $_SESSION['event_update_temp_files'][$tok] = ['path' => $tmpPath, 'name' => $f['name'], 'created' => time(), 'user' => get_current_user_id(), 'event_id' => $event_id];
    // Temporary URL served by api/event_update_temp.php
    $image_path = BASE_PATH . '/api/event_update_temp.php?token=' . $tok;
}

try {
    $stmt = $pdo->prepare("INSERT INTO event_updates (event_id, author_id, content, image_path) VALUES (?, ?, ?, ?)");
    $stmt->execute([$event_id, get_current_user_id(), $content ?: null, $image_path]);
    $_SESSION['flash'] = 'Etkinlik güncellemesi eklendi.';
} catch (Exception $e) {
    error_log('event_update insert error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Güncelleme eklenemedi.';
}

header('Location: ' . $referer);
exit;