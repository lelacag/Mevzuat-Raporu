<?php /* EN + TR comments used. */
/**
 * Admin: Announcements Management
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$current_user = get_user($current_user_id);
require_admin_perm('manage_notifications');

// Ensure the admin header (and its CSS injection) is included so styles load correctly
require_once __DIR__ . '/_header.php';

$db = db_connect();
$action = $_GET['action'] ?? 'list';
$announcement_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Some installs might not have the `sources` column yet (DB migrations may be pending).
// Detect it and fall back to the older schema if needed.
$has_sources_column = false;
try {
    $chk = $db->query("SHOW COLUMNS FROM announcements LIKE 'sources'");
    $has_sources_column = (bool)$chk->fetch();
} catch (Exception $e) {
    $has_sources_column = false;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create' || $_POST['action'] === 'edit') {
        $title = $_POST['title'] ?? '';
        $summary = $_POST['summary'] ?? '';
        $content = $_POST['content'] ?? '';
        $sources = $_POST['sources'] ?? '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Button-based save mode: publish vs draft
        $save_as = $_POST['save_as'] ?? 'publish';
        if ($save_as === 'draft') {
            $is_active = 0;
        }

        // Preview requested? Render preview server-side immediately (no JS)
        if (isset($_POST['preview'])) {
            if (empty($title) || empty($summary) || empty($content)) {
                $_SESSION['flash_error'] = 'Tüm alanlar gerekli.';
            } else {
                $preview = [
                    'title' => $title,
                    'summary' => $summary,
                    'content' => $content,
                    'sources' => $sources,
                    'is_active' => $is_active,
                ];
                // keep form populated for the user to continue editing
                $edit_announcement = array_merge($edit_announcement ?? [], [
                    'title' => $title,
                    'summary' => $summary,
                    'content' => $content,
                    'sources' => $sources,
                    'is_active' => $is_active,
                ]);
                if (isset($_POST['id'])) {
                    $edit_announcement['id'] = (int)$_POST['id'];
                }
            }
        } else {
            if (empty($title) || empty($summary) || empty($content)) {
                $_SESSION['flash_error'] = 'Tüm alanlar gerekli.';
            } else {
                // Generate SEO-friendly slug from title
                $slug = generate_slug($title);
                // Ensure uniqueness: append -2, -3 etc. if slug already exists
                $base_slug = $slug;
                $suffix = 1;
                $exclude_id = ($_POST['action'] === 'edit') ? (int)$_POST['id'] : 0;
                while (true) {
                    $chk = $db->prepare("SELECT id FROM announcements WHERE slug = ? AND id != ?");
                    $chk->execute([$slug, $exclude_id]);
                    if (!$chk->fetch()) break;
                    $suffix++;
                    $slug = $base_slug . '-' . $suffix;
                }

                if ($_POST['action'] === 'create') {
                    if ($has_sources_column) {
                        $stmt = $db->prepare("INSERT INTO announcements (title, slug, summary, content, sources, created_by, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$title, $slug, $summary, $content, $sources, $current_user_id, $is_active]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO announcements (title, slug, summary, content, created_by, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$title, $slug, $summary, $content, $current_user_id, $is_active]);
                    }
                    $_SESSION['flash'] = 'Duyuru başarıyla oluşturuldu.';
                } else {
                    $id = (int)$_POST['id'];
                    if ($has_sources_column) {
                        $stmt = $db->prepare("UPDATE announcements SET title = ?, slug = ?, summary = ?, content = ?, sources = ?, is_active = ? WHERE id = ?");
                        $stmt->execute([$title, $slug, $summary, $content, $sources, $is_active, $id]);
                    } else {
                        $stmt = $db->prepare("UPDATE announcements SET title = ?, slug = ?, summary = ?, content = ?, is_active = ? WHERE id = ?");
                        $stmt->execute([$title, $slug, $summary, $content, $is_active, $id]);
                    }
                    $_SESSION['flash'] = 'Duyuru başarıyla güncellendi.';
                }
                header('Location: ' . BASE_PATH . '/admin/announcements.php');
                exit;
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM announcements WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = 'Duyuru silindi.';
        header('Location: ' . BASE_PATH . '/admin/announcements.php');
        exit;
    }
}

// Get announcements
$stmt = $db->query("SELECT a.*, u.username FROM announcements a JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC");
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get announcement for editing
$edit_announcement = null;
if ($action === 'edit' && $announcement_id > 0) {
    $stmt = $db->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->execute([$announcement_id]);
    $edit_announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($edit_announcement && !isset($edit_announcement['sources'])) {
        $edit_announcement['sources'] = '';
    }
}
// Preview data when user requests a preview (server-side, no JS)
$preview = null;

// Support preview via GET ?action=preview&id=... (preview saved announcement)
if ($action === 'preview' && $announcement_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM announcements WHERE id = ? LIMIT 1");
        $stmt->execute([$announcement_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $preview = $row;
            if (!isset($preview['sources'])) $preview['sources'] = '';
            // Populate edit form so template shows preview + form
            $edit_announcement = $preview;
            // Treat this as an edit view so the create/edit template branch renders
            $action = 'edit';
        } else {
            $_SESSION['flash_error'] = 'Duyuru bulunamadı.';
            header('Location: ' . BASE_PATH . '/admin/announcements.php');
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = 'Önizleme yüklenirken hata oluştu.';
        header('Location: ' . BASE_PATH . '/admin/announcements.php');
        exit;
    }
}
?>
        <style>
            /* Fallback admin styling (applies even if external CSS fails to load) */
            .admin-page { max-width: 1200px; margin: 0 auto; padding: 20px; font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
            .page-title { font-size: 28px; margin: 0 0 14px; }
            a { color: #5a9a3c; text-decoration: none; }
            a:hover { text-decoration: underline; }
            .btn { display: inline-block; padding: 8px 14px; border-radius: 4px; font-weight: 700; text-decoration: none; cursor: pointer; }
            .btn-approve { background: #5a9a3c; color: #fff; }
            .btn-cancel { background: #ddd; color: #333; }
            .section { border: 1px solid #e0e0e0; border-radius: 6px; background: #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.06); padding: 0; margin-bottom: 22px; }
            .section h2 { margin: 0; padding: 15px 18px; background: #fafafa; border-bottom: 1px solid #e0e0e0; font-size: 16px; }
            .admin-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
            .admin-table th, .admin-table td { padding: 12px 14px; border-bottom: 1px solid #f0f0f0; }
            .admin-table th { background: #fafafa; text-transform: uppercase; font-size: 12px; color: #666; }
            .badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
            .badge-premium { background: #5a9a3c; color: #fff; }
            .badge-rookie { background: #fff3cd; color: #856404; }
            .alert { padding: 12px 14px; border-radius: 4px; margin-bottom: 16px; }
            .alert-success { background: #e6ffed; border: 1px solid #a7f0c8; color: #1d6a3f; }
            .alert-error { background: #ffe6e6; border: 1px solid #f0a7a7; color: #8a1d1d; }
            .helper-text { color: #666; font-size: 12px; margin-top: 4px; display: block; }
            .form-group { margin-bottom: 16px; }
            .form-control { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
            .flex-row { display: flex; flex-wrap: wrap; gap: 10px; }
        </style>
        <div class="admin-page">
            <h1 class="page-title">📢 Duyurular</h1>

            <?php if (isset($_SESSION['flash'])): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($_SESSION['flash']) ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($_SESSION['flash_error']) ?>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <?php if ($action === 'create' || $action === 'edit'): ?>
                <!-- Create/Edit Form -->
                <div class="section">
                    <h2><?= $action === 'create' ? '➕ Yeni Duyuru Ekle' : '✏️ Duyuruyu Düzenle' ?></h2>
                    
                    <?php if ($preview): ?>
                        <div class="section" style="border-color:#d0f0d8;">
                            <h2>🔎 Önizleme</h2>
                            <div style="padding:16px;">
                                <h3 style="margin:0 0 8px;"><?= htmlspecialchars($preview['title']) ?></h3>
                                <div style="color:#666;margin-bottom:12px;"><?= render_rich_text($preview['summary'] ?? '') ?></div>
                                <div style="background:#fafafa;border:1px solid #eee;padding:12px;border-radius:4px;"><?= render_rich_text($preview['content'] ?? '') ?></div>
                                <?php if (!empty($preview['sources'])): ?>
                                    <div style="margin-top:10px;font-size:13px;color:#444;">
                                            <strong>Kaynaklar</strong>
                                        <div style="margin-top:6px;"><?= render_rich_text($preview['sources']) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="form-padded">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="<?= $action ?>">
                        <?php if ($edit_announcement): ?>
                            <input type="hidden" name="id" value="<?= $edit_announcement['id'] ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Başlık *</label>
                            <input type="text" name="title" class="form-control" required value="<?= $edit_announcement ? htmlspecialchars($edit_announcement['title']) : '' ?>">
                        </div>

                        <div class="form-group">
                            <label>Özet *</label>
                            <textarea name="summary" class="form-control" rows="3" required><?= $edit_announcement ? htmlspecialchars($edit_announcement['summary']) : '' ?></textarea>
                            <small class="helper-text muted">Ana sayfada gösterilecek kısa açıklama (2-3 satır)</small>
                        </div>

                        <div class="form-group">
                            <label>İçerik (Blog) *</label>
                            <textarea name="content" class="form-control" rows="10" required><?= $edit_announcement ? htmlspecialchars($edit_announcement['content']) : '' ?></textarea>
                            <small class="helper-text muted">Tam blog içeriği - tıklandığında gösterilecek</small>
                        </div>

                        <div class="form-group">
                            <label>Kaynaklar</label>
                            <textarea name="sources" class="form-control" rows="3"><?= $edit_announcement ? htmlspecialchars($edit_announcement['sources'] ?? '') : '' ?></textarea>
                            <small class="helper-text muted">Her satır bir kaynak olabilir. Linkler için [link url="https://..."]metin[/link] kullanabilirsiniz.</small>
                        </div>

                        <div class="form-group">
                            <label>Biçimlendirme (kısa not)</label>
                            <pre class="helper-text" style="background:#f5f5f5;border:1px solid #ddd;padding:8px;white-space:pre-wrap;font-family:monospace;font-size:0.9em;">
[b]Kalın[/b]
[i]İtalik[/i]
[h2]Başlık[/h2]
[link url="https://example.com"]Bağlantı[/link]
- Liste maddesi
                            </pre>
                        </div>

                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_active" value="1" <?= !$edit_announcement || $edit_announcement['is_active'] ? 'checked' : '' ?>>
                                Aktif
                            </label>
                        </div>

                        <div class="flex-row">
                            <button type="submit" name="save_as" value="publish" class="btn btn-approve">💾 Yayınla</button>
                            <button type="submit" name="save_as" value="draft" class="btn btn-cancel">⏳ Taslak Olarak Kaydet</button>
                            <button type="submit" name="preview" value="1" class="btn btn-approve" style="color:#fff">👁️ Önizle</button>
                            <a href="<?= BASE_PATH ?>/admin/announcements.php" class="btn btn-cancel">İptal</a>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- List View -->
                <div class="mb-20">
                    <a href="<?= BASE_PATH ?>/admin/announcements.php?action=create" class="btn btn-approve">+ Yeni Duyuru</a>
                </div>

                <div class="section">
                    <h2>Duyurular (<?= count($announcements) ?>)</h2>

                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">Henüz duyuru eklenmemiş.</div>
                    <?php else: ?>
                        <div class="admin-table-wrapper">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Başlık</th>
                                        <th>Oluşturan</th>
                                        <th>Durum</th>
                                        <th>Tarih</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($announcements as $ann): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($ann['title']) ?></strong></td>
                                            <td>@<?= htmlspecialchars($ann['username']) ?></td>
                                            <td>
                                                <?php if ($ann['is_active']): ?>
                                                    <span class="badge badge-premium">✓ Yayınlandı</span>
                                                <?php else: ?>
                                                    <span class="badge badge-rookie">⏳ Taslak</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('d.m.Y H:i', strtotime($ann['created_at'])) ?></td>
                                            <td class="admin-actions">
                                                <a href="<?= BASE_PATH ?>/admin/announcements.php?action=preview&id=<?= $ann['id'] ?>" class="btn btn-approve" style="color:#fff">👁️ Önizle</a>
                                                <a href="<?= BASE_PATH ?>/admin/announcements.php?action=edit&id=<?= $ann['id'] ?>" class="btn btn-approve" style="color:#fff">✏️ Düzenle</a>
                                                <form method="POST" class="form-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $ann['id'] ?>">
                                                    <button type="submit" class="btn btn-revoke">🗑️ Sil</button>
                                                </form> 
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
