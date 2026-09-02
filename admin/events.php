<?php /* EN + TR comments used. */
// Ensure session/auth/db are available before handling redirects (so headers can be sent)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$db = db_connect();
$current_user_id = get_current_user_id();
$current_user = $current_user_id ? get_user($current_user_id) : null;
$form_errors = [];
$form_success = '';

// Require RBAC permission to manage events
require_admin_perm('manage_events');


// Handle create/edit POST (server-side)
// Normalise delete_gallery_image:{id} button value → standard action + image_id
if (!empty($_POST['action']) && preg_match('/^delete_gallery_image:(\d+)$/', $_POST['action'], $_dgm)) {
    $_POST['image_id'] = $_dgm[1];
    $_POST['action']   = 'delete_gallery_image';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !empty($_POST['action']) &&
    in_array($_POST['action'], ['save_event', 'upload_event_image', 'upload_event_gallery', 'delete_gallery_image'])
) {
    // Diagnostic logging for POST submissions (do not log cookie names/values)
    error_log('[ADMIN_EVENTS] POST received by ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' action=' . ($_POST['action'] ?? '<none>') . ' cookie_present=' . (empty($_COOKIE) ? 0 : 1));

    if (!is_admin() && !admin_has_perm(null, 'manage_events')) {
        http_response_code(403);
        error_log('[ADMIN_EVENTS] POST rejected - missing manage_events permission for user=' . intval($current_user_id));
        exit('Forbidden');
    }

    // CSRF check: require valid token for event creation/update
    if (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_error'] = 'Geçersiz istek (CSRF).';
        error_log('[ADMIN_EVENTS] CSRF verification failed for user=' . intval($current_user_id));
        header('Location: ' . BASE_PATH . '/admin/events.php');
        exit;
    }

    // ---- Shared image upload helper ----
    function _event_save_image(array $file, string $uploadDir): array {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'Yükleme hatası (kod: ' . $file['error'] . ').'];
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['error' => 'Dosya çok büyük. Maks. 5MB.'];
        }
        $mime = null;
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $mime = finfo_file($fi, $file['tmp_name']); finfo_close($fi); }
        }
        if (!$mime && function_exists('mime_content_type')) {
            $mime = mime_content_type($file['tmp_name']);
        }
        if (!$mime) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
            $mime = $map[$ext] ?? '';
        }
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($mime, $allowed, true)) {
            return ['error' => 'Geçersiz dosya türü. Yalnızca JPEG, PNG, GIF, WebP kabul edilir.'];
        }
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }
        $ext2     = ($mime === 'image/jpeg') ? 'jpg' : (($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'gif'));
        $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext2;
        $dest     = $uploadDir . '/' . $filename;
        $ok = @move_uploaded_file($file['tmp_name'], $dest);
        if (!$ok) {
            $ok = (@rename($file['tmp_name'], $dest) || (@copy($file['tmp_name'], $dest) && @unlink($file['tmp_name'])));
        }
        if (!$ok) {
            return ['error' => 'Dosya kaydedilemedi. Dizin izinlerini kontrol edin.'];
        }
        return ['filename' => $filename];
    }

    // ---- Resolve event folder in tmp/events/ ----
    function _event_tmp_folder(int $event_id, string $tmp_token): string {
        return $event_id ? 'event_' . $event_id : 'tmp_' . $tmp_token;
    }

    // ---- Collect re-render modal_data from POST ----
    function _modal_data_from_post(int $event_id, string $tmp_token, string $cover_image): array {
        return [
            'id'             => $event_id ?: null,
            'title'          => trim($_POST['title'] ?? ''),
            'description'    => trim($_POST['description'] ?? ''),
            'event_date'     => trim($_POST['event_date'] ?? ''),
            'location'       => trim($_POST['location'] ?? ''),
            'max_attendees'  => trim($_POST['max_attendees'] ?? ''),
            'cover_image'    => $cover_image,
            'tmp_token'      => $tmp_token,
            'is_active'      => !empty($_POST['is_active']) ? 1 : 0,
        ];
    }

    $projectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);

    // --- Image upload sub-action ---
    if ($_POST['action'] === 'upload_event_image') {
        $event_id_up  = !empty($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        $tmp_token_up = trim($_POST['tmp_token'] ?? '');
        if (!$event_id_up && !preg_match('/^[a-f0-9]{32}$/', $tmp_token_up)) {
            $tmp_token_up = bin2hex(random_bytes(16));
        }
        $upload_result = null;
        if (!empty($_FILES['cover_image_file']) && $_FILES['cover_image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $folderName = _event_tmp_folder($event_id_up, $tmp_token_up);
            $uploadDir  = $projectRoot . '/tmp/events/' . $folderName . '/header';
            // Remove any existing header file (one header at a time)
            foreach (glob($uploadDir . '/*') ?: [] as $oldFile) {
                if (is_file($oldFile)) { @unlink($oldFile); }
            }
            $upload_result = _event_save_image($_FILES['cover_image_file'], $uploadDir);
            if (!isset($upload_result['error'])) {
                error_log('[ADMIN_EVENTS] Header image uploaded: ' . $upload_result['filename'] . ' folder=' . $folderName . ' by user=' . intval($current_user_id));
            }
        } else {
            $upload_result = ['error' => 'Dosya seçilmedi.'];
        }
        $new_cover = isset($upload_result['filename']) ? $upload_result['filename'] : trim($_POST['cover_image'] ?? '');
        $show_modal = true;
        $modal_data = _modal_data_from_post($event_id_up, $tmp_token_up, $new_cover);
        if (!empty($upload_result['error'])) {
            $form_errors[] = $upload_result['error'];
        } else {
            $form_success = 'Kapak görseli başarıyla yüklendi.';
        }

    // --- Gallery image upload ---
    } elseif ($_POST['action'] === 'upload_event_gallery') {
        $event_id_up  = !empty($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        $tmp_token_up = trim($_POST['tmp_token'] ?? '');
        if (!$event_id_up && !preg_match('/^[a-f0-9]{32}$/', $tmp_token_up)) {
            $tmp_token_up = bin2hex(random_bytes(16));
        }
        $upload_result = null;
        if (!empty($_FILES['gallery_image_file']) && $_FILES['gallery_image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $folderName = _event_tmp_folder($event_id_up, $tmp_token_up);
            $uploadDir  = $projectRoot . '/tmp/events/' . $folderName . '/images';
            $upload_result = _event_save_image($_FILES['gallery_image_file'], $uploadDir);
            if (!isset($upload_result['error'])) {
                // Insert into event_images
                if ($event_id_up) {
                    $stmtGi = $db->prepare("INSERT INTO event_images (event_id, filename, sort_order) VALUES (?, ?, 0)");
                    $stmtGi->execute([$event_id_up, $upload_result['filename']]);
                } else {
                    $stmtGi = $db->prepare("INSERT INTO event_images (tmp_token, filename, sort_order) VALUES (?, ?, 0)");
                    $stmtGi->execute([$tmp_token_up, $upload_result['filename']]);
                }
                error_log('[ADMIN_EVENTS] Gallery image uploaded: ' . $upload_result['filename'] . ' folder=' . _event_tmp_folder($event_id_up, $tmp_token_up) . ' by user=' . intval($current_user_id));
            }
        } else {
            $upload_result = ['error' => 'Dosya seçilmedi.'];
        }
        $show_modal = true;
        $modal_data = _modal_data_from_post($event_id_up, $tmp_token_up, trim($_POST['cover_image'] ?? ''));
        if (!empty($upload_result['error'])) {
            $form_errors[] = $upload_result['error'];
        } else {
            $form_success = 'Galeri görseli başarıyla yüklendi.';
        }

    // --- Delete gallery image ---
    } elseif ($_POST['action'] === 'delete_gallery_image') {
        $gid_confirm = intval($_POST['image_id'] ?? 0);
        $confirm_key = 'confirm_delete_gallery_' . $gid_confirm;
        if (empty($_POST[$confirm_key])) {
            $_SESSION['flash_error'] = 'Galeri görselini silmek için onay kutusunu işaretleyin.';
            $eid = intval($_POST['id'] ?? 0);
            header('Location: ' . BASE_PATH . '/admin/events.php?action=edit&id=' . $eid);
            exit;
        }
        $event_id_up  = !empty($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        $tmp_token_up = trim($_POST['tmp_token'] ?? '');
        $image_id_del = !empty($_POST['image_id']) ? intval($_POST['image_id']) : 0;
        if ($image_id_del) {
            // Fetch the row, verify it belongs to this event/token
            $stmtDel = $db->prepare("SELECT * FROM event_images WHERE id = ? LIMIT 1");
            $stmtDel->execute([$image_id_del]);
            $imgRow = $stmtDel->fetch(PDO::FETCH_ASSOC);
            $canDelete = false;
            if ($imgRow) {
                if ($event_id_up && (int)$imgRow['event_id'] === $event_id_up) $canDelete = true;
                if ($tmp_token_up && $imgRow['tmp_token'] === $tmp_token_up)   $canDelete = true;
            }
            if ($canDelete) {
                // Delete from disk
                $folderName = _event_tmp_folder((int)($imgRow['event_id'] ?? 0), $imgRow['tmp_token'] ?? '');
                $filePath   = $projectRoot . '/tmp/events/' . $folderName . '/images/' . $imgRow['filename'];
                if (is_file($filePath)) { @unlink($filePath); }
                $db->prepare("DELETE FROM event_images WHERE id = ?")->execute([$image_id_del]);
                $form_success = 'Galeri görseli silindi.';
                error_log('[ADMIN_EVENTS] Gallery image deleted id=' . $image_id_del . ' by user=' . intval($current_user_id));
            } else {
                $form_errors[] = 'Görsel silinemedi: erişim reddedildi.';
            }
        } else {
            $form_errors[] = 'Geçersiz görsel ID.';
        }
        $show_modal = true;
        $modal_data = _modal_data_from_post($event_id_up, $tmp_token_up, trim($_POST['cover_image'] ?? ''));

    } else {
        // --- save_event ---
        $title         = trim($_POST['title'] ?? '');
        $description   = trim($_POST['description'] ?? '');
        $event_date    = trim($_POST['event_date'] ?? '');
        $location      = trim($_POST['location'] ?? '') ?: null;
        $max_attendees = (isset($_POST['max_attendees']) && $_POST['max_attendees'] !== '') ? max(1, intval($_POST['max_attendees'])) : null;
        $cover_image   = trim($_POST['cover_image'] ?? '') ?: null;
        $is_active     = !empty($_POST['is_active']) ? 1 : 0;
        $event_id      = !empty($_POST['event_id']) ? intval($_POST['event_id']) : null;

        $errors = [];
        if ($title === '')      $errors[] = 'Başlık gerekli.';
        if ($description === '') $errors[] = 'Açıklama gerekli.';
        if ($event_date === '') $errors[] = 'Etkinlik tarihi gerekli.';

        if (empty($errors)) {
            if ($event_id) {
                $stmt = $db->prepare("UPDATE events SET title = ?, description = ?, event_date = ?, location = ?, max_attendees = ?, cover_image = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$title, $description, $event_date, $location, $max_attendees, $cover_image, $is_active, $event_id]);
                $_SESSION['flash'] = 'Etkinlik güncellendi.';
                error_log('[ADMIN_EVENTS] Event updated id=' . intval($event_id) . ' by user=' . intval($current_user_id));
            } else {
                $stmt = $db->prepare("INSERT INTO events (title, description, event_date, location, max_attendees, cover_image, created_by, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$title, $description, $event_date, $location, $max_attendees, $cover_image, $current_user_id, $is_active]);
                $newId = $db->lastInsertId();
                // Rename staging folder and link gallery images if tmp_token was used
                $tmp_token_save = trim($_POST['tmp_token'] ?? '');
                if ($tmp_token_save && preg_match('/^[a-f0-9]{32}$/', $tmp_token_save)) {
                    $srcDir = $projectRoot . '/tmp/events/tmp_' . $tmp_token_save;
                    $dstDir = $projectRoot . '/tmp/events/event_' . $newId;
                    if (is_dir($srcDir) && !is_dir($dstDir)) {
                        @rename($srcDir, $dstDir);
                    }
                    $stmtGal = $db->prepare("UPDATE event_images SET event_id = ?, tmp_token = NULL WHERE tmp_token = ? AND event_id IS NULL");
                    $stmtGal->execute([$newId, $tmp_token_save]);
                }
                $_SESSION['flash'] = 'Etkinlik oluşturuldu.';
                error_log('[ADMIN_EVENTS] Event created id=' . intval($newId) . ' by user=' . intval($current_user_id));
            }
            header('Location: ' . BASE_PATH . '/admin/events.php');
            exit;
        } else {
            $form_errors = $errors;
            // Re-render the form with all entered data preserved
            $show_modal = true;
            $modal_data = [
                'id'            => $event_id,
                'title'         => $title,
                'description'   => $description,
                'event_date'    => trim($_POST['event_date'] ?? ''),
                'location'      => trim($_POST['location'] ?? ''),
                'max_attendees' => trim($_POST['max_attendees'] ?? ''),
                'cover_image'   => trim($_POST['cover_image'] ?? ''),
                'tmp_token'     => trim($_POST['tmp_token'] ?? ''),
                'is_active'     => $is_active,
            ];
        }
    }
}

// Fallback GET handlers for environments where form POSTs or JS are blocked
if (!empty($_GET['action']) && in_array($_GET['action'], ['toggle','delete','create','edit'])) {
    // log the GET action and current user for diagnostics
    error_log('[ADMIN_EVENTS] GET action=' . ($_GET['action'] ?? '') . ' current_user=' . intval($current_user_id) . ' is_admin=' . (is_admin() ? 1 : 0));

    if (in_array($_GET['action'], ['toggle','delete'])) {
        if (!is_admin() && !admin_has_perm(null, 'manage_events')) {
            $_SESSION['flash_error'] = 'Bu işlemi gerçekleştirmek için yetkiniz yok.';
            exit;
        }

        $eid = !empty($_GET['id']) ? intval($_GET['id']) : 0;
        if (empty($eid)) {
            $_SESSION['flash_error'] = 'Geçersiz etkinlik ID.';
            header('Location: ' . BASE_PATH . '/admin/events.php');
            exit;
        }

        if ($_GET['action'] === 'toggle') {
            $stmt = $db->prepare("SELECT is_active FROM events WHERE id = ? LIMIT 1");
            $stmt->execute([$eid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $new = $row['is_active'] ? 0 : 1;
                $upd = $db->prepare("UPDATE events SET is_active = ? WHERE id = ?");
                $upd->execute([$new, $eid]);
                $_SESSION['flash'] = 'Etkinlik durumu güncellendi.';
            } else {
                $_SESSION['flash_error'] = 'Etkinlik bulunamadı.';
            }
        } else {
            $del = $db->prepare("DELETE FROM events WHERE id = ?");
            $del->execute([$eid]);
            $_SESSION['flash'] = 'Etkinlik silindi.';
        }

        header('Location: ' . BASE_PATH . '/admin/events.php');
        exit;
    }

    // for create/edit we let the regular page rendering continue (show inline form)
}

require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';

// Get all events
$stmt = $db->query("
    SELECT e.*, u.username as creator_username
    FROM events e
    LEFT JOIN users u ON e.created_by = u.id
    ORDER BY e.event_date DESC
");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count active events
$active_count = count(array_filter($events, fn($e) => $e['is_active']));

// Prepare modal display data (show modal when ?action=create or ?action=edit)
// Prepare form display — $show_modal may already be true if set by upload_event_image POST handler
if (!isset($show_modal)) {
    $show_modal = false;
}
if (!isset($modal_data)) {
    $modal_data = ['id' => null, 'title' => '', 'description' => '', 'event_date' => '', 'location' => '', 'max_attendees' => '', 'cover_image' => '', 'tmp_token' => '', 'is_active' => 1];
}
if (!empty($_GET['action']) && in_array($_GET['action'], ['create','edit'])) {
    // Allow full admins and users with manage_events permission
    if ($current_user && (is_admin() || admin_has_perm(null, 'manage_events'))) {
        $show_modal = true;
        if ($_GET['action'] === 'edit' && !empty($_GET['id'])) {
            $eid = intval($_GET['id']);
            foreach ($events as $ev) {
                if ((int)$ev['id'] === $eid) {
                    $modal_data = [
                        'id'            => $ev['id'],
                        'title'         => $ev['title'],
                        'description'   => $ev['description'],
                        'event_date'    => date('Y-m-d\TH:i', strtotime($ev['event_date'])),
                        'location'      => $ev['location'] ?? '',
                        'max_attendees' => $ev['max_attendees'] ?? '',
                        'cover_image'   => $ev['cover_image'] ?? '',
                        'tmp_token'     => '',
                        'is_active'     => $ev['is_active'],
                    ];
                    break;
                }
            }
        }
    } else {
        // non-admin attempted to open modal — ignore and show notice
        $form_errors[] = 'Bu işlemi gerçekleştirmek için yönetici olarak giriş yapmalısınız.';
    }
}

// Build cover image URL for display (handles both legacy paths and new filename-only storage)
function event_cover_img_url(array $md): string {
    $ci = $md['cover_image'] ?? '';
    if ($ci === '') return '';
    if ($ci[0] === '/') return $ci; // legacy: full path
    if (!empty($md['id'])) {
        return BASE_PATH . '/event_img.php?event=' . intval($md['id']) . '&type=header&file=' . urlencode($ci);
    }
    if (!empty($md['tmp_token'])) {
        return BASE_PATH . '/event_img.php?token=' . urlencode($md['tmp_token']) . '&type=header&file=' . urlencode($ci);
    }
    return '';
}

function event_gallery_img_url(array $row): string {
    if (!empty($row['event_id'])) {
        return BASE_PATH . '/event_img.php?event=' . intval($row['event_id']) . '&type=images&file=' . urlencode($row['filename']);
    }
    if (!empty($row['tmp_token'])) {
        return BASE_PATH . '/event_img.php?token=' . urlencode($row['tmp_token']) . '&type=images&file=' . urlencode($row['filename']);
    }
    return '';
}

// Load gallery images when showing the form
$gallery_images = [];
if (!empty($show_modal)) {
    if (!empty($modal_data['id'])) {
        $gStmt = $db->prepare("SELECT * FROM event_images WHERE event_id = ? ORDER BY sort_order, id");
        $gStmt->execute([$modal_data['id']]);
        $gallery_images = $gStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif (!empty($modal_data['tmp_token'])) {
        $gStmt = $db->prepare("SELECT * FROM event_images WHERE tmp_token = ? AND event_id IS NULL ORDER BY sort_order, id");
        $gStmt->execute([$modal_data['tmp_token']]);
        $gallery_images = $gStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

?>

<?php if (!empty($show_modal)): ?>

        <div class="admin-page">

            <!-- Page header row -->
            <div class="event-form-page-header">
                <a href="<?= BASE_PATH ?>/admin/events.php" class="event-back-link">← Etkinlik Listesine Dön</a>
                <h1 class="page-title" style="margin:0;">
                    <?= $modal_data['id'] ? '✏️ Etkinliği Düzenle' : '📅 Yeni Etkinlik Oluştur' ?>
                </h1>
            </div>

            <?php if (!empty($form_errors)): ?>
            <div class="event-alert event-alert-error">
                <?php foreach ($form_errors as $e): ?><div>⚠ <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($form_success)): ?>
            <div class="event-alert event-alert-success">✓ <?= htmlspecialchars($form_success) ?></div>
            <?php endif; ?>

            <!-- ONE unified form — multipart so it can carry file uploads AND all text fields together -->
            <!-- Every button uses name="action" value="..." to tell PHP what to do -->
            <form method="POST" enctype="multipart/form-data" id="event-main-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="sid"        value="<?= htmlspecialchars($_REQUEST['sid'] ?? '') ?>">
                <input type="hidden" name="event_id"   value="<?= htmlspecialchars($modal_data['id'] ?? '') ?>">
                <input type="hidden" name="tmp_token"  value="<?= htmlspecialchars($modal_data['tmp_token'], ENT_QUOTES) ?>">
                <!-- cover_image filename is updated by PHP after a successful header upload -->
                <input type="hidden" name="cover_image" value="<?= htmlspecialchars($modal_data['cover_image'], ENT_QUOTES) ?>">

            <!-- SECTION 1: Temel Bilgiler -->
            <div class="section event-form-section">
                <div class="event-form-section-title">Temel Bilgiler</div>

                <div class="form-group">
                    <label for="ef_title">Etkinlik Başlığı <span class="event-required">*</span></label>
                    <input class="form-control" type="text" id="ef_title" name="title" required
                           value="<?= htmlspecialchars($modal_data['title']) ?>"
                           placeholder="Etkinlik başlığını girin">
                </div>

                <div class="form-group">
                    <label for="ef_description">Açıklama <span class="event-required">*</span></label>
                    <textarea class="form-control" id="ef_description" name="description" rows="6" required
                              placeholder="Etkinlik hakkında bilgi verin"><?= htmlspecialchars($modal_data['description']) ?></textarea>
                </div>
            </div>

            <!-- SECTION 2: Two-column row -->
            <div class="event-form-cols">

                <!-- Left: Tarih, Yer, Katılımcı, Durum -->
                <div class="section event-form-section">
                    <div class="event-form-section-title">Tarih &amp; Lokasyon</div>

                    <div class="form-group">
                        <label for="ef_event_date">Tarih ve Saat <span class="event-required">*</span></label>
                        <input class="form-control" type="datetime-local" id="ef_event_date" name="event_date" required
                               value="<?= htmlspecialchars($modal_data['event_date']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="ef_location">Konum / Mekan</label>
                        <input class="form-control" type="text" id="ef_location" name="location"
                               value="<?= htmlspecialchars($modal_data['location']) ?>"
                               placeholder="Örn. İstanbul, Beylikdüzü Meydan">
                    </div>

                    <div class="form-group">
                        <label for="ef_max_attendees">Maks. Katılımcı <span class="event-field-note">(boş = sınırsız)</span></label>
                        <input class="form-control" type="number" id="ef_max_attendees" name="max_attendees" min="1"
                               value="<?= htmlspecialchars($modal_data['max_attendees']) ?>"
                               placeholder="Sınırsız">
                    </div>

                    <div class="form-group">
                        <label>Durum</label>
                        <div class="event-radio-group">
                            <label class="event-radio-label">
                                <input type="radio" name="is_active" value="1"
                                       <?= $modal_data['is_active'] ? 'checked' : '' ?>>
                                <span class="event-radio-text event-radio-active">Aktif</span>
                            </label>
                            <label class="event-radio-label">
                                <input type="radio" name="is_active" value="0"
                                       <?= !$modal_data['is_active'] ? 'checked' : '' ?>>
                                <span class="event-radio-text event-radio-pasif">Pasif (Taslak)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Right: Cover image upload -->
                <div class="section event-form-section">
                    <div class="event-form-section-title">Kapak Görseli</div>

                    <?php $cover_url = event_cover_img_url($modal_data); ?>
                    <?php if ($cover_url !== ''): ?>
                    <div class="event-img-preview-wrap">
                        <img src="<?= htmlspecialchars($cover_url) ?>"
                             alt="Kapak görseli" class="event-img-preview">
                        <div class="event-img-preview-label">Mevcut kapak görseli</div>
                    </div>
                    <?php else: ?>
                    <div class="event-img-placeholder">Henüz kapak görseli yüklenmedi</div>
                    <?php endif; ?>

                    <div class="form-group" style="margin-top:16px;">
                        <label for="ef_cover_file">Kapak Görseli Seç</label>
                        <input class="form-control" type="file" id="ef_cover_file"
                               name="cover_image_file" accept="image/jpeg,image/png,image/gif,image/webp">
                        <div class="event-field-hint">JPEG, PNG, GIF veya WebP · Maks. 5MB</div>
                    </div>
                    <button type="submit" name="action" value="upload_event_image" class="btn btn-approve">📤 Kapak Görselini Yükle</button>
                </div>
            </div>

            <!-- SECTION 3: Gallery images -->
            <div class="section event-form-section">
                <div class="event-form-section-title">Etkinlik Galeri Görselleri</div>

                <?php if (!empty($gallery_images)): ?>
                <div class="event-gallery-grid" style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                    <?php foreach ($gallery_images as $gi): ?>
                    <?php $gi_url = event_gallery_img_url($gi); ?>
                    <div class="event-gallery-item" style="position:relative;">
                        <?php if ($gi_url): ?>
                        <img src="<?= htmlspecialchars($gi_url) ?>" alt="" style="width:120px;height:90px;object-fit:cover;border-radius:4px;border:1px solid #ddd;">
                        <?php endif; ?>
                        <!-- Delete button encodes image id in its value; PHP splits it out -->
                        <label style="display:block;font-size:11px;margin:4px 0;">
                            <input type="checkbox" name="confirm_delete_gallery_<?= intval($gi['id']) ?>" value="1">
                            Silmeyi onayla
                        </label>
                        <button type="submit" name="action" value="delete_gallery_image:<?= intval($gi['id']) ?>"
                                class="btn btn-revoke"
                                style="margin-top:4px;width:100%;font-size:12px;padding:2px 6px;">
                            🗑️ Sil
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="event-img-placeholder" style="margin-bottom:16px;">Henüz galeri görseli yüklenmedi</div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="ef_gallery_file">Galeri Görseli Ekle</label>
                    <input class="form-control" type="file" id="ef_gallery_file"
                           name="gallery_image_file" accept="image/jpeg,image/png,image/gif,image/webp">
                    <div class="event-field-hint">JPEG, PNG, GIF veya WebP · Maks. 5MB · Her seferinde bir görsel</div>
                </div>
                <button type="submit" name="action" value="upload_event_gallery" class="btn btn-approve">📤 Galeri Görseli Yükle</button>
            </div>

            <!-- Footer actions -->
            <div class="event-form-footer">
                <button type="submit" name="action" value="save_event" class="btn btn-approve event-save-btn">✓ Etkinliği Kaydet</button>
                <a href="<?= BASE_PATH ?>/admin/events.php" class="btn btn-cancel" style="text-decoration:none;">✕ İptal</a>
            </div>

            </form><!-- end #event-main-form -->

        </div>

<?php else: ?>

        <div class="admin-page">
            <h1 class="page-title">📅 Etkinlik Yönetimi</h1>

            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?= count($events) ?></div>
                    <div class="stat-label">Toplam Etkinlik</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $active_count ?></div>
                    <div class="stat-label">Aktif Etkinlik</div>
                </div>
            </div>

            <?php if (!empty($_SESSION['flash'])): ?>
            <div class="event-alert event-alert-success">✓ <?= htmlspecialchars($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); endif; ?>

            <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="event-alert event-alert-error">⚠ <?= htmlspecialchars($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); endif; ?>

            <?php if (!empty($form_errors)): ?>
            <div class="event-alert event-alert-error">
                <?php foreach ($form_errors as $e): ?><div>⚠ <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="section">
                <div class="flex-row" style="justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2 style="margin:0;">Etkinlik Listesi</h2>
                    <?php if ($current_user && ($current_user['role'] ?? '') === 'admin'): ?>
                        <form method="GET" action="<?= BASE_PATH ?>/admin/events.php" style="display:inline;margin:0;">
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid'] ?? '') ?>">
                            <button type="submit" class="btn btn-approve">+ Yeni Etkinlik</button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if (count($events) > 0): ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:44px;">Görsel</th>
                            <th>Başlık</th>
                            <th>Etkinlik Tarihi</th>
                            <th>Konum</th>
                            <th>Oluşturan</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                        <?php
                            $thumb_url = '';
                            if (!empty($event['cover_image'])) {
                                $ci = $event['cover_image'];
                                $thumb_url = ($ci !== '' && $ci[0] === '/')
                                    ? $ci
                                    : BASE_PATH . '/event_img.php?event=' . intval($event['id']) . '&type=header&file=' . urlencode($ci);
                            }
                        ?>
                        <tr>
                            <td>
                                <?php if ($thumb_url !== ''): ?>
                                    <img src="<?= htmlspecialchars($thumb_url) ?>"
                                         alt="" class="event-thumb">
                                <?php else: ?>
                                    <span class="event-thumb-empty">—</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($event['title']) ?></strong></td>
                            <td style="white-space:nowrap;"><?= date('d.m.Y H:i', strtotime($event['event_date'])) ?></td>
                            <td><?= !empty($event['location']) ? htmlspecialchars($event['location']) : '<span style="color:#bbb;">—</span>' ?></td>
                            <td>@<?= htmlspecialchars($event['creator_username']) ?></td>
                            <td>
                                <?php if ($event['is_active']): ?>
                                    <span class="badge badge-active">✓ Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-rookie">Pasif</span>
                                <?php endif; ?>
                            </td>
                            <td class="admin-actions">
                            <?php if ($current_user && ($current_user['role'] ?? '') === 'admin'): ?>
                                <form method="GET" action="<?= BASE_PATH ?>/admin/events.php" style="display:inline;margin:0;">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?= $event['id'] ?>">
                                    <input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid'] ?? '') ?>">
                                    <button type="submit" class="btn btn-approve">✏️ Düzenle</button>
                                </form>
                                <form method="POST" action="<?= BASE_PATH ?>/api/admin_toggle_event.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/events.php">
                                    <input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid'] ?? '') ?>">
                                    <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $event['is_active'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-demote"><?= $event['is_active'] ? '🔒 Pasif Yap' : '✓ Aktif Yap' ?></button>
                                </form>
                                <noscript><a href="<?= BASE_PATH ?>/admin/events.php?action=toggle&id=<?= $event['id'] ?>" class="btn btn-demote"><?= $event['is_active'] ? '🔒 Pasif Yap' : '✓ Aktif Yap' ?></a></noscript>
                                <form method="POST" action="<?= BASE_PATH ?>/api/admin_delete_event.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/events.php">
                                    <input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid'] ?? '') ?>">
                                    <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                    <button type="submit" class="btn btn-revoke">🗑️ Sil</button>
                                </form>
                                <noscript><a href="<?= BASE_PATH ?>/admin/events.php?action=delete&id=<?= $event['id'] ?>" class="btn btn-revoke">🗑️ Sil</a></noscript>
                            <?php else: ?>
                                <span style="color:#999;font-size:13px;">(Yönetici girişi gerekli)</span>
                            <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:30px;">Henüz etkinlik yok.</div>
                <?php endif; ?>
            </div>

        </div>

<?php endif; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
