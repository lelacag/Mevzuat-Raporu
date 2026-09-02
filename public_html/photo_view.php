<?php
/**
 * photo_view.php — Single photo view with EXIF-free download using ExifTool.
 *
 * SECURITY: Uses 'exiftool' CLI to strip ALL metadata on download.
 * Works for JPEG, PNG, TIFF, and more. No PHP extension dependencies.
 */

// ─── DOWNLOAD HANDLER (Runs BEFORE any HTML output) ──────────────────────────
if (isset($_GET['download'])) {
    require_once __DIR__ . '/includes/db.php';

    $image_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($image_id <= 0) {
        http_response_code(404);
        exit('Not Found');
    }

    $pdo = db_connect();
    $stmt = $pdo->prepare("
        SELECT ui.filename, ui.user_id, ui.original_filename
        FROM user_images ui
        WHERE ui.id = ? AND ui.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$image_id]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$image) {
        http_response_code(404);
        exit('Not Found');
    }

    $user_hash = hash('sha256', 'mevzuat_photo_' . $image['user_id']);
    $fullPath = realpath(__DIR__ . '/users/' . $user_hash . '/photos/' . basename($image['filename']));

    if (!$fullPath || !is_file($fullPath)) {
        http_response_code(404);
        exit('Not Found');
    }

    // Check if exiftool is available
    $exiftoolPath = '/usr/bin/exiftool';
    if (!is_executable($exiftoolPath)) {
        // Fallback check for common locations
        if (is_executable('/usr/local/bin/exiftool')) {
            $exiftoolPath = '/usr/local/bin/exiftool';
        } else {
            error_log("ExifTool not found for download ID=$image_id");
            http_response_code(500);
            exit('Server Error: Metadata stripping tool missing.');
        }
    }

    // Create a temporary clean file
    $tempCleanPath = sys_get_temp_dir() . '/lumo_clean_' . uniqid() . '_' . basename($image['filename']);
    
    // Copy original to temp first (so we don't modify the original)
    if (!copy($fullPath, $tempCleanPath)) {
        error_log("Failed to copy file for cleaning ID=$image_id");
        http_response_code(500);
        exit('Server Error: Could not process image.');
    }

    // STRIP ALL METADATA using exiftool
    // -all= removes all metadata
    // -overwrite_original replaces the temp file with the clean version
    $cmd = sprintf('%s -all= -overwrite_original %s', escapeshellcmd($exiftoolPath), escapeshellarg($tempCleanPath));
    
    exec($cmd, $output, $returnVar);

    if ($returnVar !== 0) {
        @unlink($tempCleanPath);
        error_log("ExifTool failed for ID=$image_id. Return: $returnVar. Output: " . implode("\n", $output));
        http_response_code(500);
        exit('Server Error: Could not strip metadata.');
    }

    // Detect MIME for headers (exiftool doesn't change the file type)
    $mime = null;
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = finfo_file($fi, $tempCleanPath);
            finfo_close($fi);
        }
    }
    if (!$mime) {
        $ext = strtolower(pathinfo($tempCleanPath, PATHINFO_EXTENSION));
        $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'tif' => 'image/tiff', 'tiff' => 'image/tiff'];
        $mime = $map[$ext] ?? 'application/octet-stream';
    }

    // Prepare headers
    $safe_name = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($image['original_filename'] ?? $image['filename']));
    $size = filesize($tempCleanPath);

    // Send Headers
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="' . $safe_name . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');

    // Stream the clean file
    readfile($tempCleanPath);

    // Cleanup temp file immediately
    @unlink($tempCleanPath);
    exit; // STOP execution. No HTML is rendered.
}

// ─── NORMAL VIEW FLOW (HTML Page) ───────────────────────────────────────────
require_once __DIR__ . '/includes/header.php';

$image_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($image_id <= 0) {
    http_response_code(404);
    exit('Not Found');
}

$pdo = db_connect();

// Fetch image with owner username
$stmt = $pdo->prepare("
    SELECT ui.*, u.username
    FROM user_images ui
    JOIN users u ON u.id = ui.user_id
    WHERE ui.id = ? AND ui.deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([$image_id]);
$image = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$image) {
    http_response_code(404);
    exit('Not Found');
}

$is_owner = $current_user_id && (int)$current_user_id === (int)$image['user_id'];
$is_admin_usr = function_exists('is_admin') ? is_admin() : false;

// ─── Increment view count (skip for owner and admins) ─────────────────────────
if (!$is_owner && !$is_admin_usr) {
    $pdo->prepare("UPDATE user_images SET view_count = view_count + 1 WHERE id = ?")
        ->execute([$image_id]);
    $image['view_count'] = ($image['view_count'] ?? 0) + 1;
}

// ─── Handle tag update (owner only) ───────────────────────────────────────────
$tag_errors  = [];
$tag_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_tags') {
    if (!($is_owner || $is_admin_usr)) {
        http_response_code(403);
        exit('Forbidden');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $tag_errors[] = 'Geçersiz istek.';
    } else {
        $new_tags = mb_substr(trim($_POST['tags'] ?? ''), 0, 255);
        $upd = $pdo->prepare("UPDATE user_images SET tags = ? WHERE id = ?");
        $upd->execute([$new_tags ?: null, $image_id]);
        $image['tags'] = $new_tags ?: null;
        $tag_success = true;
    }
}

// ─── Handle delete (owner or admin) — server-side confirm, no JS ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_photo') {
    if (!($is_owner || $is_admin_usr)) {
        http_response_code(403);
        exit('Forbidden');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Forbidden');
    }
    if (empty($_POST['confirm_delete'])) {
        $_SESSION['flash_error'] = 'Silme işlemini onaylamak için onay kutusunu işaretleyin.';
        header('Location: ' . BASE_PATH . '/photo_view.php?id=' . $image_id);
        exit;
    }
    try {
        $pdo->beginTransaction();

        // Soft-delete image
        $pdo->prepare("UPDATE user_images SET deleted_at = NOW() WHERE id = ?")
            ->execute([$image_id]);

        // Soft-delete the associated post if exists
        if (!empty($image['post_id'])) {
            $pdo->prepare("UPDATE posts SET deleted_at = NOW() WHERE id = ?")
                ->execute([$image['post_id']]);
        }
        if (!empty($image['group_post_id'])) {
            $pdo->prepare("UPDATE group_posts SET deleted_at = NOW() WHERE id = ?")
                ->execute([$image['group_post_id']]);
        }

        $pdo->commit();

        // Redirect to gallery
        header('Location: ' . BASE_PATH . '/fotograf/' . rawurlencode($image['username']));
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('photo_view delete error: ' . $e->getMessage());
    }
}

// ─── Build back-link ──────────────────────────────────────────────────────────
$back_href = BASE_PATH . '/fotograf/' . rawurlencode($image['username']);
$back_label = '← Galeriye Dön';

if (!empty($image['post_id'])) {
    $back_href  = function_exists('get_post_url')
        ? get_post_url((int)$image['post_id'], $image['username'])
        : BASE_PATH . '/gonderi/' . (int)$image['post_id'];
    $back_label = '← Gönderiye Dön';
} elseif (!empty($image['group_post_id'])) {
    // Fetch group slug for back link
    $gs = $pdo->prepare(
        "SELECT gt.slug FROM groups_table gt
         JOIN group_posts gp ON gp.group_id = gt.id
         WHERE gp.id = ? LIMIT 1"
    );
    $gs->execute([$image['group_post_id']]);
    $gslug = $gs->fetchColumn();
    if ($gslug) {
        $back_href  = BASE_PATH . '/g/' . rawurlencode($gslug);
        $back_label = '← Gruba Dön';
    }
}

// ─── Display helpers ──────────────────────────────────────────────────────────
$pub_date = $image['publish_date']
    ? date('d.m.Y', strtotime($image['publish_date']))
    : '—';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fotoğraf — <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/photos.css">
</head>
<body>

<div class="page-container">
    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-error" style="margin:12px 0;"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>
    <div class="photo-view-container">

        <div class="photo-view-topbar">
            <a href="<?= htmlspecialchars($back_href) ?>" class="back-link"><?= htmlspecialchars($back_label) ?></a>
            <?php if ($is_owner || $is_admin_usr): ?>
                <form method="POST" class="photo-delete-form" action="<?= BASE_PATH ?>/photo_view.php?id=<?= (int)$image_id ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="delete_photo">
                    <label class="photo-delete-confirm">
                        <input type="checkbox" name="confirm_delete" value="1" required>
                        Fotoğrafı ve ilişkili gönderiyi silmeyi onaylıyorum
                    </label>
                    <button type="submit" class="btn-danger-small">🗑 Sil</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="photo-view-image-wrap">
            <img src="<?= BASE_PATH ?>/photo_img.php?id=<?= $image_id ?>"
                 alt="<?= htmlspecialchars($image['original_filename']) ?>"
                 class="photo-view-img">
        </div>

        <div class="photo-view-actions">
            <!-- Download triggers the exiftool handler -->
            <a href="<?= BASE_PATH ?>/photo_view.php?id=<?= $image_id ?>&amp;download=1" class="btn-download">⬇ Fotoğrafı İndir</a>
            <span class="photo-view-count">👁 <?= number_format((int)($image['view_count'] ?? 0)) ?> görüntülenme</span>
        </div>

        <div class="photo-meta-card">
            <h2 class="photo-meta-title">Görsel</h2>
            <table class="photo-meta-table">
                <tr>
                    <th>Yayın Tarihi</th>
                    <td><?= htmlspecialchars($pub_date) ?></td>
                </tr>

                <tr>
                    <th>Lisans</th>
                    <td><?= htmlspecialchars($image['licence']) ?></td>
                </tr>
                <tr>
                    <th>Görüntülenme</th>
                    <td><?= number_format((int)($image['view_count'] ?? 0)) ?></td>
                </tr>
                <tr>
                    <th>Sahibi</th>
                    <td>
                        <a href="<?= function_exists('profile_url') ? profile_url($image['username']) : BASE_PATH . '/profil/' . rawurlencode($image['username']) ?>">
                            @<?= htmlspecialchars($image['username']) ?>
                        </a>
                    </td>
                </tr>
            </table>

            <!-- Tags -->
            <div class="photo-meta-tags-section">
                <span class="photo-meta-label">Etiketler:</span>
                <?php if ($image['tags']): ?>
                    <div class="photo-tag-list">
                        <?php foreach (array_filter(array_map('trim', explode(',', $image['tags']))) as $tag): ?>
                            <span class="photo-tag"><?= htmlspecialchars($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <span class="photo-no-tags">—</span>
                <?php endif; ?>

                <?php if ($is_owner || $is_admin_usr): ?>
                    <?php if (!empty($tag_errors)): ?>
                        <p class="photo-error-msg"><?= htmlspecialchars(implode(' ', $tag_errors)) ?></p>
                    <?php endif; ?>
                    <?php if ($tag_success): ?>
                        <p class="photo-success-msg">Etiketler güncellendi.</p>
                    <?php endif; ?>
                    <form method="POST" class="photo-tag-edit-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="update_tags">
                        <input type="text" name="tags" maxlength="255"
                               value="<?= htmlspecialchars($image['tags'] ?? '') ?>"
                               placeholder="hukuk, mevzuat, dava"
                               class="photo-tag-edit-input">
                        <button type="submit" class="btn-small">Kaydet</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>