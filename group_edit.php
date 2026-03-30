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
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

// Load group
$stmt = $pdo->prepare("SELECT * FROM groups_table WHERE slug = ?");
$stmt->execute([$slug]);
$group = $stmt->fetch();
if (!$group) {
    $_SESSION['flash'] = 'Grup bulunamadı.';
    header('Location: ' . BASE_PATH . '/groups.php');
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
    header('Location: ' . BASE_PATH . '/group.php?slug=' . urlencode($slug));
    exit;
}

// Handle POST actions (save settings / approve / reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = 'Geçersiz istek.';
        header('Location: ' . BASE_PATH . '/group_edit.php?slug=' . urlencode($slug));
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
                        $stmtN = $pdo->prepare("INSERT INTO notifications (user_id, type, text, from_user_id, created_at) VALUES (?, 'system', ?, ?, NOW())");
                        $stmtN->execute([$req['user_id'], $text, $user_id]);
                    } catch (PDOException $e) { /* ignore notif errors */ }
                    $_SESSION['flash'] = 'Başvuru onaylandı.';
                } else {
                    $up = $pdo->prepare("UPDATE group_join_requests SET status = 'rejected' WHERE id = ?");
                    $up->execute([$rid]);
                    // Notify requester
                    try {
                        $text = "Grup başvurunuz reddedildi: " . $group['name'];
                        $stmtN = $pdo->prepare("INSERT INTO notifications (user_id, type, text, from_user_id, created_at) VALUES (?, 'system', ?, ?, NOW())");
                        $stmtN->execute([$req['user_id'], $text, $user_id]);
                    } catch (PDOException $e) { /* ignore notif errors */ }
                    $_SESSION['flash'] = 'Başvuru reddedildi.';
                }
            }
        } catch (PDOException $e) {
            error_log('Group edit approve/reject error: ' . $e->getMessage());
            $_SESSION['flash'] = 'İşlem sırasında bir hata oluştu.';
        }
        header('Location: ' . BASE_PATH . '/group_edit.php?slug=' . urlencode($slug) . '&tab=applications');
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
        header('Location: ' . BASE_PATH . '/group_edit.php?slug=' . urlencode($slug) . '&tab=members');
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
            header('Location: ' . BASE_PATH . '/groups.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Group delete in edit page error: ' . $e->getMessage());
            $_SESSION['flash'] = 'Grup silinirken bir hata oluştu.';
            header('Location: ' . BASE_PATH . '/group_edit.php?slug=' . urlencode($slug));
            exit;
        }
    } else {
        // Update group settings
        $is_private = !empty($_POST['is_private']) ? 1 : 0;
        $entry_question = trim($_POST['entry_question'] ?? '');
        $group_name = trim($_POST['group_name'] ?? '');
        $group_description = trim($_POST['group_description'] ?? '');
        if ($group_name === '') $group_name = $group['name'];
        try {
            $stmt = $pdo->prepare("UPDATE groups_table SET name = ?, description = ?, is_private = ?, entry_question = ? WHERE id = ?");
            $stmt->execute([$group_name, $group_description !== '' ? $group_description : null, $is_private, $entry_question !== '' ? $entry_question : null, $group['id']]);
            $_SESSION['flash'] = 'Değişiklikler kaydedildi.';
        } catch (PDOException $e) {
            error_log('Group edit update error: ' . $e->getMessage());
            $_SESSION['flash'] = 'Kaydetme başarısız. Veritabanı şeması güncel mi?';
        }
        header('Location: ' . BASE_PATH . '/group.php?slug=' . urlencode($slug));
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
try {
    $ms = $pdo->prepare("SELECT gm.user_id, gm.role, gm.joined_at, u.username FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? ORDER BY gm.role DESC, gm.joined_at DESC LIMIT 500");
    $ms->execute([$group['id']]);
    $edit_members = $ms->fetchAll();
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
                <a href="<?= BASE_PATH ?>/group.php?slug=<?= urlencode($slug) ?>" class="link-muted">İptal</a>
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
            <h2 class="section-subtitle">Üyeler</h2>
            <?php if (empty($edit_members)): ?>
                <div class="empty-state"><p>Üye bulunamadı.</p></div>
            <?php else: ?>
            <table class="table table-full">
                <thead>
                    <tr class="table-headers">
                        <th>Kullanıcı</th>
                        <th>Rol</th>
                        <th class="text-right">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($edit_members as $m): ?>
                    <tr class="table-row">
                        <td><a href="<?= profile_url($m['username']) ?>">@<?= htmlspecialchars($m['username']) ?></a></td>
                        <td class="td-muted"><?= htmlspecialchars($m['role']) ?></td>
                        <td class="text-right">
                            <?php if ($m['user_id'] != $user_id): ?>
                                <form method="POST" class="form-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="member_id" value="<?= (int)$m['user_id'] ?>">
                                    <button type="submit" class="btn btn-danger">Gruptan Çıkar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>
        <?php endif; ?>

    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
