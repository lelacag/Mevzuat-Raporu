<?php /* EN + TR comments used. */
/**
 * Group Edit Page - Admin panel for a single group
 * - Toggle private/public
 * - Set entry question
 * - Delete group (with single Save button if delete confirmed)
 */
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$slug = $_GET['slug'] ?? '';

if (!$user_id || !$slug) {
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

// Load group
$stmt = $pdo->prepare("SELECT * FROM groups_table WHERE slug = ?");
$stmt->execute([$slug]);
$group = $stmt->fetch();
if (!$group) {
    $_SESSION['flash'] = 'Grup bulunamadı.';
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

// Determine role
$user_role = null;
$stmt = $pdo->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
$stmt->execute([$group['id'], $user_id]);
$member = $stmt->fetch();
if ($member) {
    $user_role = $member['role'];
}

// Authorization: group admin or site admin
if ($user_role !== 'admin' && !is_admin()) {
    $_SESSION['flash'] = 'Bu sayfaya erişim yetkiniz yok.';
    header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
    exit;
}

// Handle POST actions (save settings / approve / reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = 'Geçersiz istek.';
        header('Location: ' . group_edit_url($slug));
        exit;
    }
    
    $action = $_POST['action'] ?? 'save';
    if ($action === 'approve_request' || $action === 'reject_request') {
        $rid = intval($_POST['request_id'] ?? 0);
        try {
            $rq = $pdo->prepare("SELECT * FROM group_join_requests WHERE id = ? AND group_id = ? AND status = 'pending'");
            $rq->execute([$rid, $group['id']]);
            $req = $rq->fetch();
            if ($req) {
                if ($action === 'approve_request') {
                    // Add member if not exists
                    $chk = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
                    $chk->execute([$group['id'], $req['user_id']]);
                    if (!$chk->fetch()) {
                        $ins = $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')");
                        $ins->execute([$group['id'], $req['user_id']]);
                    }
                    $up = $pdo->prepare("UPDATE group_join_requests SET status = 'approved' WHERE id = ?");
                    $up->execute([$rid]);
                    // Notify requester
                    try {
                        $text = "Grup başvurunuz onaylandı: " . $group['name'];
                        $stmtN = $pdo->prepare("INSERT INTO notifications (user_id, type, text, from_user_id, group_id, target_url, created_at) VALUES (?, 'system', ?, ?, ?, ?, NOW())");
                        $stmtN->execute([$req['user_id'], $text, $user_id, $group['id'], group_url($group['slug'])]);
                    } catch (PDOException $e) { /* ignore notif errors */ }
                    $_SESSION['flash'] = 'Başvuru onaylandı.';
                } else {
                    $up = $pdo->prepare("UPDATE group_join_requests SET status = 'rejected' WHERE id = ?");
                    $up->execute([$rid]);
                    // Notify requester
                    try {
                        $text = "Grup başvurunuz reddedildi: " . $group['name'];
                        $stmtN = $pdo->prepare("INSERT INTO notifications (user_id, type, text, from_user_id, group_id, target_url, created_at) VALUES (?, 'system', ?, ?, ?, ?, NOW())");
                        $stmtN->execute([$req['user_id'], $text, $user_id, $group['id'], group_url($group['slug'])]);
                    } catch (PDOException $e) { /* ignore notif errors */ }
                    $_SESSION['flash'] = 'Başvuru reddedildi.';
                }
            }
        } catch (PDOException $e) {
            error_log('Group edit approve/reject error: ' . $e->getMessage());
            $_SESSION['flash'] = 'İşlem sırasında bir hata oluştu.';
        }
        header('Location: ' . group_edit_url($slug) . '?tab=applications');
        exit;
    }

    if ($action === 'remove_member') {
        $member_id = intval($_POST['member_id'] ?? 0);
        try {
            // Only remove if the user is currently a member (any role); prevent removing self to be safe
            if ($member_id && $member_id !== $user_id) {
                $chk = $pdo->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
                $chk->execute([$group['id'], $member_id]);
                $row = $chk->fetch();
                if ($row) {
                    // Remove member
                    $del = $pdo->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
                    $del->execute([$group['id'], $member_id]);
                    // Optional: notify removed user
                    try {
                        $text = "'" . $group['name'] . "' grubundan çıkarıldınız.";
                        $stmtN = $pdo->prepare("INSERT INTO notifications (user_id, type, text, from_user_id, created_at) VALUES (?, 'system', ?, ?, NOW())");
                        $stmtN->execute([$member_id, $text, $user_id]);
                    } catch (PDOException $e) { /* ignore notif errors */ }
                    $_SESSION['flash'] = 'Üye gruptan çıkarıldı.';
                }
            }
        } catch (PDOException $e) {
            error_log('Remove member error: ' . $e->getMessage());
            $_SESSION['flash'] = 'Üye çıkarılırken bir hata oluştu.';
        }
        header('Location: ' . group_edit_url($slug) . '?tab=members');
        exit;
    }

    if ($action === 'send_group_invite') {
        $target_username = trim($_POST['target_username'] ?? '');
        $target_username = ltrim($target_username, '@');
        $target_email = trim(mb_strtolower($_POST['target_email'] ?? ''));
        $errors = [];

        if ($target_username === '' && $target_email === '') {
            $errors[] = 'Lütfen kullanıcı adı veya e-posta girin.';
        }
        if ($target_email !== '' && !filter_var($target_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Geçerli bir e-posta adresi girin.';
        }

        $invited_user_id = null;
        if ($target_username !== '') {
            $target_user = get_user_by_username($target_username);
            if (!$target_user) {
                $errors[] = 'Kullanıcı bulunamadı.';
            } else {
                $invited_user_id = $target_user['id'];
            }
        }

        if (empty($errors)) {
            if ($invited_user_id && is_group_member($group['id'], $invited_user_id)) {
                $errors[] = 'Bu kullanıcı zaten grupta.';
            }
            if ($target_email !== '' && $invited_user_id === null) {
                $stmt = $pdo->prepare("SELECT u.id FROM users u JOIN group_members gm ON gm.user_id = u.id WHERE gm.group_id = ? AND LOWER(u.email) = ? LIMIT 1");
                $stmt->execute([$group['id'], $target_email]);
                if ($stmt->fetchColumn()) {
                    $errors[] = 'Bu e-posta gruba zaten kayıtlı.';
                }
            }
        }

        if (empty($errors)) {
            $invite_token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+14 days'));
            $invite_email = $invited_user_id ? $target_user['email'] : $target_email;

            try {
                query("CREATE TABLE IF NOT EXISTS group_invites (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    group_id INT NOT NULL,
                    invited_by_user_id INT NOT NULL,
                    invited_user_id INT DEFAULT NULL,
                    invite_email VARCHAR(255) DEFAULT NULL,
                    invite_token VARCHAR(128) NOT NULL UNIQUE,
                    status ENUM('pending','accepted','expired','revoked') NOT NULL DEFAULT 'pending',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    accepted_at DATETIME DEFAULT NULL,
                    expires_at DATETIME DEFAULT NULL,
                    INDEX idx_group_invites_group (group_id),
                    INDEX idx_group_invites_invited_user (invited_user_id),
                    INDEX idx_group_invites_email (invite_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Exception $e) {
                error_log('Create group_invites table error: ' . $e->getMessage());
            }

            $dup_stmt = $pdo->prepare("SELECT 1 FROM group_invites WHERE group_id = ? AND status = 'pending' AND (invited_user_id = ? OR invite_email = ?) LIMIT 1");
            $dup_stmt->execute([$group['id'], $invited_user_id, $invite_email]);
            if ($dup_stmt->fetchColumn()) {
                $errors[] = 'Bu kullanıcıya veya e-postaya zaten bekleyen bir davet gönderilmiş.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO group_invites (group_id, invited_by_user_id, invited_user_id, invite_email, invite_token, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$group['id'], $user_id, $invited_user_id, $invite_email, $invite_token, $expires_at]);

                $invite_url = full_url(group_invite_url($group['slug'], $invite_token));
                if ($invited_user_id) {
                    send_group_invite_notification($invited_user_id, $user_id, $group, $invite_url);
                } else {
                    send_group_invite_email($invite_email, $group, $invite_url);
                }
                $_SESSION['flash'] = 'Davet gönderildi.';
            }
        }
        header('Location: ' . group_edit_url($slug) . '?tab=members');
        exit;
    }

    $doDelete = !empty($_POST['delete_confirm']);
    if ($doDelete) {
        // Delete group and related data
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE gpl FROM group_post_likes gpl INNER JOIN group_posts gp ON gpl.post_id = gp.id WHERE gp.group_id = ?");
            $stmt->execute([$group['id']]);
            $stmt = $pdo->prepare("DELETE gpc FROM group_post_comments gpc INNER JOIN group_posts gp ON gpc.post_id = gp.id WHERE gp.group_id = ?");
            $stmt->execute([$group['id']]);
            $stmt = $pdo->prepare("DELETE FROM group_posts WHERE group_id = ?");
            $stmt->execute([$group['id']]);
            $stmt = $pdo->prepare("DELETE FROM group_members WHERE group_id = ?");
            $stmt->execute([$group['id']]);
            // Optional: delete join requests if table exists
            try {
                $stmt = $pdo->prepare("DELETE FROM group_join_requests WHERE group_id = ?");
                $stmt->execute([$group['id']]);
            } catch (PDOException $e) {
                // ignore if table missing
            }
            $stmt = $pdo->prepare("DELETE FROM groups_table WHERE id = ?");
            $stmt->execute([$group['id']]);
            $pdo->commit();
            $_SESSION['flash'] = 'Grup başarıyla silindi.';
            header('Location: ' . BASE_PATH . '/topluluklar');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Group delete in edit page error: ' . $e->getMessage());
            $_SESSION['flash'] = 'Grup silinirken bir hata oluştu.';
            header('Location: ' . group_edit_url($slug));
            exit;
        }
    } else {
        // Update group settings
        $is_private        = !empty($_POST['is_private']) ? 1 : 0;
        $entry_question    = trim($_POST['entry_question'] ?? '');
        $group_name        = trim($_POST['group_name'] ?? '');
        $group_description = trim($_POST['group_description'] ?? '');
        $new_slug_input    = trim($_POST['new_slug'] ?? '');

        if ($group_name === '') $group_name = $group['name'];

        // --- Slug rename logic ---
        $slug_error     = null;
        $active_slug    = $group['slug']; // will be updated if rename succeeds
        $do_slug_rename = false;

        if ($new_slug_input !== '' && $new_slug_input !== $group['slug']) {
            // Cooldown: once per 30 days per group
            $slug_updated_at = $group['slug_updated_at'] ?? null;
            if ($slug_updated_at) {
                $next_allowed = strtotime($slug_updated_at) + (30 * 24 * 3600);
                if (time() < $next_allowed) {
                    $slug_error = 'Grup URL\'si ayda bir kez değiştirilebilir. Tekrar değiştirebileceğiniz tarih: '
                                  . date('d.m.Y', $next_allowed) . '.';
                }
            }

            if (!$slug_error) {
                // Generate a clean slug from the input (handles Turkish chars)
                $candidate_slug = generate_slug($new_slug_input);
                if ($candidate_slug === '' || $candidate_slug === 'item') {
                    $slug_error = 'Geçerli bir grup URL\'si girin.';
                } else {
                    // Check uniqueness
                    $chkSlug = $pdo->prepare("SELECT id FROM groups_table WHERE slug = ? AND id != ?");
                    $chkSlug->execute([$candidate_slug, $group['id']]);
                    if ($chkSlug->fetch()) {
                        $slug_error = 'Bu URL zaten başka bir grup tarafından kullanılıyor: <strong>' . htmlspecialchars($candidate_slug) . '</strong>';
                    } else {
                        $do_slug_rename = true;
                        $active_slug    = $candidate_slug;
                    }
                }
            }
        }

        if ($slug_error) {
            $_SESSION['flash_error'] = $slug_error;
            header('Location: ' . group_edit_url($slug));
            exit;
        }

        try {
            $pdo->beginTransaction();

            if ($do_slug_rename) {
                // Archive the old slug for 301 redirects
                $pdo->prepare(
                    "INSERT INTO group_slug_history (group_id, old_slug, changed_at) VALUES (?, ?, NOW())"
                )->execute([$group['id'], $group['slug']]);

                $pdo->prepare(
                    "UPDATE groups_table SET name = ?, description = ?, is_private = ?, entry_question = ?, slug = ?, slug_updated_at = NOW() WHERE id = ?"
                )->execute([
                    $group_name,
                    $group_description !== '' ? $group_description : null,
                    $is_private,
                    $entry_question !== '' ? $entry_question : null,
                    $active_slug,
                    $group['id']
                ]);
            } else {
                $pdo->prepare(
                    "UPDATE groups_table SET name = ?, description = ?, is_private = ?, entry_question = ? WHERE id = ?"
                )->execute([
                    $group_name,
                    $group_description !== '' ? $group_description : null,
                    $is_private,
                    $entry_question !== '' ? $entry_question : null,
                    $group['id']
                ]);
            }

            $pdo->commit();
            $_SESSION['flash'] = 'Değişiklikler kaydedildi.';
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Group edit update error: ' . $e->getMessage());
            $_SESSION['flash'] = 'Kaydetme başarısız. Veritabanı şeması güncel mi?';
            header('Location: ' . group_edit_url($slug));
            exit;
        }

        header('Location: ' . BASE_PATH . '/g/' . urlencode($active_slug));
        exit;
    }
}

// Fetch data for all tabs before rendering HTML
// -- Requests (for applications tab) --
$pending_requests = $approved_requests = $rejected_requests = [];
try {
    $stmtP = $pdo->prepare("SELECT gjr.*, u.username FROM group_join_requests gjr JOIN users u ON u.id = gjr.user_id WHERE gjr.group_id = ? AND gjr.status = 'pending' ORDER BY gjr.created_at ASC LIMIT 200");
    $stmtP->execute([$group['id']]);
    $pending_requests = $stmtP->fetchAll();

    $stmtA = $pdo->prepare("SELECT gjr.*, u.username FROM group_join_requests gjr JOIN users u ON u.id = gjr.user_id WHERE gjr.group_id = ? AND gjr.status = 'approved' ORDER BY gjr.created_at DESC LIMIT 200");
    $stmtA->execute([$group['id']]);
    $approved_requests = $stmtA->fetchAll();

    $stmtR = $pdo->prepare("SELECT gjr.*, u.username FROM group_join_requests gjr JOIN users u ON u.id = gjr.user_id WHERE gjr.group_id = ? AND gjr.status = 'rejected' ORDER BY gjr.created_at DESC LIMIT 200");
    $stmtR->execute([$group['id']]);
    $rejected_requests = $stmtR->fetchAll();
} catch (PDOException $e) {
    if ($e->getCode() !== '42S02') {
        error_log('Requests fetch error (edit page): ' . $e->getMessage());
    }
}
$pending_count = count($pending_requests);

// -- Members (for members tab) --
$edit_members = [];
$pending_invites = [];
try {
    $ms = $pdo->prepare("SELECT gm.user_id, gm.role, gm.joined_at, u.username FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? ORDER BY gm.role DESC, gm.joined_at DESC LIMIT 500");
    $ms->execute([$group['id']]);
    $edit_members = $ms->fetchAll();

    ensure_group_invites_table();
    $invStmt = $pdo->prepare("SELECT gi.*, u.username AS invited_username FROM group_invites gi LEFT JOIN users u ON gi.invited_user_id = u.id WHERE gi.group_id = ? ORDER BY gi.created_at DESC LIMIT 100");
    $invStmt->execute([$group['id']]);
    $pending_invites = $invStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Members fetch error: ' . $e->getMessage());
}

// Active tab
$active_tab = $_GET['tab'] ?? 'settings';
if (!in_array($active_tab, ['settings', 'applications', 'members'])) $active_tab = 'settings';
?>

<div class="main-container single-column">
    <main class="content-area narrow">
        <h1 class="section-title">Grubu Düzenle</h1>

        <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-success">
            <?= $_SESSION['flash'] ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger">
            <?= $_SESSION['flash_error'] ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="edit-tabs">
            <a href="?slug=<?= urlencode($slug) ?>&tab=settings" class="edit-tab <?= $active_tab === 'settings' ? 'active' : '' ?>">Ayarlar</a>
            <a href="?slug=<?= urlencode($slug) ?>&tab=applications" class="edit-tab <?= $active_tab === 'applications' ? 'active' : '' ?>">Başvurular<?php if ($pending_count > 0): ?> <span class="badge red"><?= $pending_count ?></span><?php endif; ?></a>
            <a href="?slug=<?= urlencode($slug) ?>&tab=members" class="edit-tab <?= $active_tab === 'members' ? 'active' : '' ?>">Üyeler (<?= count($edit_members) ?>)</a>
        </div>

        <!-- TAB: Settings -->
        <?php if ($active_tab === 'settings'): ?>
        <div class="panel">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="save">

                <div class="form-row">
                    <label class="form-label">Grup Adı</label>
                    <input type="text" name="group_name" value="<?= htmlspecialchars($group['name']) ?>" class="full-width" maxlength="100" required>
                </div>

                <div class="form-row">
                    <label class="form-label">Grup URL'si (Slug)</label>
                    <?php
                        $slug_updated_at_val = $group['slug_updated_at'] ?? null;
                        $can_rename_at = $slug_updated_at_val ? strtotime($slug_updated_at_val) + (30 * 24 * 3600) : null;
                        $slug_locked   = $can_rename_at && time() < $can_rename_at;
                    ?>
                    <?php if ($slug_locked): ?>
                        <input type="text" class="full-width" value="<?= htmlspecialchars($group['slug']) ?>" disabled>
                        <input type="hidden" name="new_slug" value="<?= htmlspecialchars($group['slug']) ?>">
                        <div class="form-hint">
                            ⏱ Grup URL'si <strong><?= date('d.m.Y', strtotime($slug_updated_at_val)) ?></strong> tarihinde değiştirildi.
                            Tekrar değiştirebileceğiniz tarih: <strong><?= date('d.m.Y', $can_rename_at) ?></strong>.
                        </div>
                    <?php else: ?>
                        <input type="text" name="new_slug" value="<?= htmlspecialchars($group['slug']) ?>" class="full-width" maxlength="200" placeholder="grup-url-adi">
                        <div class="form-hint">
                            Mevcut URL: <code><?= htmlspecialchars(group_url($group['slug'])) ?></code><br>
                            URL değiştirirseniz eski adres otomatik yönlendirilir (301). Ayda bir kez değiştirilebilir.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <label class="form-label">Grup Açıklaması</label>
                    <textarea name="group_description" class="full-width textarea-small" maxlength="500" placeholder="Grup hakkında kısa açıklama..."><?= htmlspecialchars($group['description'] ?? '') ?></textarea>
                </div>

                <div class="form-row">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_private" value="1" <?= !empty($group['is_private']) ? 'checked' : '' ?>>
                        Özel grup (içerik üyelerle sınırlıdır)
                    </label>
                </div>

                <div class="form-row">
                    <label class="form-label">Giriş Sorusu (isteğe bağlı)</label>
                    <textarea name="entry_question" class="full-width textarea-small" placeholder="Bu gruba katılmak isteyenlere sorulacak soru..."><?php echo htmlspecialchars($group['entry_question'] ?? ''); ?></textarea>
                </div>

                <div class="danger-panel">
                    <label class="checkbox-label danger-label">
                        <input type="checkbox" name="delete_confirm" value="1">
                        Bu grubu kalıcı olarak silmeyi onaylıyorum
                    </label>
                    <div class="muted note">Tüm gönderiler, yorumlar ve üyelikler geri alınamaz şekilde silinir.</div>
                </div>

                <button type="submit" class="btn-post">Kaydet</button>
                <a href="<?= BASE_PATH ?>/g/<?= urlencode($slug) ?>" class="link-muted">İptal</a>
            </form>
        </div>
        <?php endif; ?>

        <!-- TAB: Applications -->
        <?php if ($active_tab === 'applications'): ?>
        <section class="panel muted-panel">
            <h2 class="section-subtitle">Bekleyen Başvurular (<?= $pending_count ?>)</h2>
            <?php if (empty($pending_requests)): ?> 
                <div class="empty-state"><p>Bekleyen başvuru yok.</p></div>
            <?php else: ?>
            <table class="table table-full">
                <thead>
                    <tr class="table-headers">
                        <th>Kullanıcı</th>
                        <th>Yanıt</th>
                        <th class="text-right">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_requests as $r): ?>
                    <tr class="table-row">
                        <td><a href="<?= profile_url($r['username']) ?>">@<?= htmlspecialchars($r['username']) ?></a></td>
                        <td class="td-muted td-wrap"><?= nl2br(htmlspecialchars($r['answer'])) ?></td>
                        <td class="text-right">
                            <form method="POST" class="form-inline">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="action" value="approve_request">
                                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-join">Onayla</button>
                            </form>
                            <form method="POST" class="form-inline ml-6">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="action" value="reject_request">
                                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-danger">Reddet</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>

        <section class="panel muted-panel">
            <h2 class="section-subtitle">Onaylanan Başvurular</h2>
            <?php if (empty($approved_requests)): ?>
                <div class="empty-state"><p>Onaylanan başvuru yok.</p></div>
            <?php else: ?>
            <table class="table table-full">
                <thead>
                    <tr class="table-headers">
                        <th>Kullanıcı</th>
                        <th>Yanıt</th>
                        <th class="text-right">Durum</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approved_requests as $r): ?>
                    <tr class="table-row">
                        <td><a href="<?= profile_url($r['username']) ?>">@<?= htmlspecialchars($r['username']) ?></a></td>
                        <td class="td-muted td-wrap"><?= nl2br(htmlspecialchars($r['answer'])) ?></td>
                        <td class="text-right text-success font-weight-600">Onaylandı</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>

        <section class="panel muted-panel">
            <h2 class="section-subtitle">Reddedilen Başvurular</h2>
            <?php if (empty($rejected_requests)): ?>
                <div class="empty-state"><p>Reddedilen başvuru yok.</p></div>
            <?php else: ?>
            <table class="table table-full">
                <thead>
                    <tr class="table-headers">
                        <th>Kullanıcı</th>
                        <th>Yanıt</th>
                        <th class="text-right">Durum</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rejected_requests as $r): ?>
                    <tr class="table-row">
                        <td><a href="<?= profile_url($r['username']) ?>">@<?= htmlspecialchars($r['username']) ?></a></td>
                        <td class="td-muted td-wrap"><?= nl2br(htmlspecialchars($r['answer'])) ?></td>
                        <td class="text-right text-danger font-weight-600">Reddedildi</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- TAB: Members -->
        <?php if ($active_tab === 'members'): ?>
        <section class="panel muted-panel">
            <h2 class="section-subtitle">Davetler</h2>
            <form method="POST" class="form-box">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="send_group_invite">
                <div class="form-row">
                    <label class="form-label">Kullanıcı adı</label>
                    <input type="text" name="target_username" class="full-width" placeholder="@kullaniciadi">
                </div>
                <div class="form-row">
                    <label class="form-label">Veya e-posta</label>
                    <input type="email" name="target_email" class="full-width" placeholder="ornek@ornek.com">
                </div>
                <button type="submit" class="btn-post">Daveti Gönder</button>
            </form>

            <?php if (!empty($pending_invites)): ?>
            <section class="panel muted-panel" style="margin-top:20px;">
                <h2 class="section-subtitle">Bekleyen Davetler</h2>
                <table class="table table-full">
                    <thead>
                        <tr class="table-headers">
                            <th>Kullanıcı / E-posta</th>
                            <th>Durum</th>
                            <th>Oluşturuldu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_invites as $invite): ?>
                        <tr class="table-row">
                            <td>
                                <?php if (!empty($invite['invited_username'])): ?>
                                    @<?= htmlspecialchars($invite['invited_username']) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($invite['invite_email']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($invite['status']) ?></td>
                            <td><?= htmlspecialchars($invite['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>
        </section>
        <?php endif; ?>

    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
