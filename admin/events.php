<?php /* EN + TR comments used. */
// Ensure session/auth/db are available before handling redirects (so headers can be sent)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$db = db_connect();
$current_user_id = get_current_user_id();
$current_user = $current_user_id ? get_user($current_user_id) : null;

// Require RBAC permission to manage events
require_admin_perm('manage_events');


// Handle create/edit POST (server-side)
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !empty($_POST['action']) &&
    in_array($_POST['action'], ['save_event'])
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

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $event_date = trim($_POST['event_date'] ?? '');
    $is_active = !empty($_POST['is_active']) ? 1 : 0;
    $event_id = !empty($_POST['event_id']) ? intval($_POST['event_id']) : null;

    $errors = [];
    if ($title === '') $errors[] = 'Başlık gerekli.';
    if ($description === '') $errors[] = 'Açıklama gerekli.';
    if ($event_date === '') $errors[] = 'Etkinlik tarihi gerekli.';

    if (empty($errors)) {
        if ($event_id) {
            $stmt = $db->prepare("UPDATE events SET title = ?, description = ?, event_date = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$title, $description, $event_date, $is_active, $event_id]);
            $_SESSION['flash'] = 'Etkinlik güncellendi.';
            error_log('[ADMIN_EVENTS] Event updated id=' . intval($event_id) . ' by user=' . intval($current_user_id));
        } else {
            $stmt = $db->prepare("INSERT INTO events (title, description, event_date, created_by, is_active, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$title, $description, $event_date, $current_user_id, $is_active]);
            $newId = $db->lastInsertId();
            $_SESSION['flash'] = 'Etkinlik oluşturuldu.';
            error_log('[ADMIN_EVENTS] Event created id=' . intval($newId) . ' by user=' . intval($current_user_id));
        }
        header('Location: ' . BASE_PATH . '/admin/events.php');
        exit;
    } else {
        $form_errors = $errors;
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

require_once __DIR__ . '/../includes/header.php';
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
$show_modal = false;
$modal_data = ['id' => null, 'title' => '', 'description' => '', 'event_date' => '', 'is_active' => 1];
if (!empty($_GET['action']) && in_array($_GET['action'], ['create','edit'])) {
    // only allow showing the modal to admins
    if ($current_user && ($current_user['role'] ?? '') === 'admin') {
        $show_modal = true;
        if ($_GET['action'] === 'edit' && !empty($_GET['id'])) {
            $eid = intval($_GET['id']);
            foreach ($events as $ev) {
                if ((int)$ev['id'] === $eid) {
                    $modal_data = [
                        'id' => $ev['id'],
                        'title' => $ev['title'],
                        'description' => $ev['description'],
                        'event_date' => date('Y-m-d\TH:i', strtotime($ev['event_date'])),
                        'is_active' => $ev['is_active']
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

?>



        <div class="admin-page">
            <h1 class="page-title">📅 Etkinlik Güncellemeleri</h1>

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

            <div class="section">
                <div class="flex-row" style="justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2>Etkinlik Listesi</h2>
                    <?php if ($current_user && ($current_user['role'] ?? '') === 'admin'): ?>
                        <!-- Use GET form (more reliable than anchor for some browsers/environments) -->
                        <form method="GET" action="<?= BASE_PATH ?>/admin/events.php" style="display:inline;margin:0;">
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid'] ?? '') ?>">
                            <button type="submit" class="btn btn-approve">+ Yeni Etkinlik</button>
                        </form>
                    <?php else: ?>
                        <span style="color:#999;font-size:13px;">(Yönetici girişi yapmadınız)</span>
                    <?php endif; ?>
                </div>

                <?php if (count($events) > 0): ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Başlık</th>
                            <th>Etkinlik Tarihi</th>
                            <th>Oluşturan</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($event['title']) ?></strong></td>
                            <td><?= date('d.m.Y H:i', strtotime($event['event_date'])) ?></td>
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
                                    <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/events.php">
                                    <input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid'] ?? '') ?>">
                                    <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $event['is_active'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-demote"><?= $event['is_active'] ? '🔒 Pasif Yap' : '✓ Aktif Yap' ?></button>
                                </form>
                                <!-- Fallback link for non-JS clients (only shown when JS is disabled) -->
                                <noscript><a href="<?= BASE_PATH ?>/admin/events.php?action=toggle&id=<?= $event['id'] ?>" class="btn btn-demote" style="margin-left:6px;"><?= $event['is_active'] ? '🔒 Pasif Yap' : '✓ Aktif Yap' ?></a></noscript>
                                <form method="POST" action="<?= BASE_PATH ?>/api/admin_delete_event.php" style="display:inline;">
                                    <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/events.php">
                                    <input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid'] ?? '') ?>">
                                    <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                    <button type="submit" class="btn btn-revoke">🗑️ Sil</button>
                                </form>
                                <!-- Fallback link for non-JS clients (only shown when JS is disabled) -->
                                <noscript><a href="<?= BASE_PATH ?>/admin/events.php?action=delete&id=<?= $event['id'] ?>" class="btn btn-revoke" style="margin-left:6px;">🗑️ Sil</a></noscript>
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
                    <div class="empty-state" style="padding:20px;">Henüz etkinlik yok.</div>
                <?php endif; ?>

<?php if (!empty($show_modal)): ?>
<div class="section" style="margin-top:18px;">
    <div style="padding:20px;">
        <h2 style="margin-top:0;"><?= $modal_data['id'] ? 'Etkinliği Düzenle' : 'Yeni Etkinlik Ekle' ?></h2>
        <form method="POST">
            <input type="hidden" name="action" value="save_event">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="sid" value="<?= htmlspecialchars($_REQUEST['sid'] ?? '') ?>">
            <input type="hidden" id="event_id" name="event_id" value="<?= htmlspecialchars($modal_data['id']) ?>">

            <div class="form-group">
                <label for="title">Etkinlik Başlığı *</label>
                <input type="text" id="title" name="title" required value="<?= htmlspecialchars($modal_data['title']) ?>">
            </div>

            <div class="form-group">
                <label for="description">Açıklama *</label>
                <textarea id="description" name="description" rows="5" required><?= htmlspecialchars($modal_data['description']) ?></textarea>
            </div>

            <div class="form-group">
                <label for="event_date">Etkinlik Tarihi ve Saati *</label>
                <input type="datetime-local" id="event_date" name="event_date" required value="<?= htmlspecialchars($modal_data['event_date']) ?>">
            </div>

            <div class="form-group">
                <label><input type="checkbox" id="is_active" name="is_active" value="1" <?= $modal_data['is_active'] ? 'checked' : '' ?>> Aktif</label>
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="submit" class="btn btn-approve">Kaydet</button>
                <a href="<?= BASE_PATH ?>/admin/events.php" class="btn btn-cancel" style="text-decoration:none;display:inline-block;">İptal</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
