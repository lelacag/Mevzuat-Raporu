<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$admin_id = get_current_user_id();
if (!$admin_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$admin = get_user($admin_id);
if (!$admin || !admin_has_perm($admin_id, 'manage_bad_words')) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

// CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF)';
    header('Location: ' . BASE_PATH . '/admin/badwords.php');
    exit;
}

$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/admin/badwords.php');
$referer = validate_referer($referer, BASE_PATH . '/admin/badwords.php', true);

// Check if file was uploaded
if (!isset($_FILES['file']) || !isset($_FILES['file']['error']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['file']['error'] ?? null;
    $msg = 'Dosya yükleme hatası.';
    switch ($err) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $msg = 'Dosya çok büyük.';
            break;
        case UPLOAD_ERR_NO_TMP_DIR:
            $msg = 'Sunucuda geçici klasör bulunamadı.';
            break;
        case UPLOAD_ERR_CANT_WRITE:
            $msg = 'Geçici dosyaya yazılamıyor.';
            break;
        case UPLOAD_ERR_EXTENSION:
            $msg = 'PHP uzantısı yüklemeyi engelledi.';
            break;
    }
    $_SESSION['flash_error'] = $msg;
    header('Location: ' . $referer);
    exit;
}

$file = $_FILES['file'];
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// Only accept txt and csv
if (!in_array($extension, ['txt', 'csv'])) {
    header('Location: ' . $referer);
    exit;
}

// Limit file size to 150 KB
if ($file['size'] > 150 * 1024) {
    $_SESSION['flash_error'] = 'Dosya çok büyük.';
    header('Location: ' . $referer);
    exit;
}

// Read file contents
$content = file_get_contents($file['tmp_name']);

// Parse based on file type
$words = [];
if ($extension === 'txt') {
    // Split by newlines
    $lines = preg_split('/\r?\n/', $content);
    $words = $lines;
} elseif ($extension === 'csv') {
    // Split by newline then parse CSV rows
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        $parts = str_getcsv($line);
        $words = array_merge($words, $parts);
    }
}

// Clean and add each word (sanitise and length-check)
$added_count = 0;
foreach ($words as $word) {
    $word = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', $word));
    if ($word === '') continue;
    if (mb_strlen($word) > 80) continue;
    // whitelist characters (letters, numbers, space, hyphen, underscore)
    if (!preg_match('/^[\p{L}0-9\-\s_]+$/u', $word)) continue;
    if (add_bad_word($word, $admin_id)) {
        $added_count++;
    }
}

log_admin_action('upload_bad_words', 'uploaded ' . $added_count . ' words', $admin_id);

$_SESSION['flash'] = $added_count . ' kelime eklendi.';
header('Location: ' . $referer);
exit;
?>
