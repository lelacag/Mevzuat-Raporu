<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}
$current_user = get_user($current_user_id);
// Require RBAC permission to manage users
require_admin_perm('manage_users');

ensure_user_ip_history_table();

$history_user = null;
$ip_history = [];
$user_id_to_view = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
if ($user_id_to_view) {
    $history_user = get_user($user_id_to_view);
    if (!$history_user) {
        $_SESSION['flash_error'] = 'Kullanıcı bulunamadı.';
        header('Location: ' . BASE_PATH . '/admin/users.php');
        exit;
    }
    $ip_history = get_user_ip_history($history_user['id'], 200);
}

if (!$history_user) {
    // Search functionality
    $search = $_GET['search'] ?? '';
    $params = [];

    // Get unapproved rookies (pending approval)
    $where_clause_pending = "deleted_at IS NULL AND is_approved = 0";
    if ($search) {
        $where_clause_pending .= " AND username LIKE ?";
        $params_pending = ['%' . $search . '%'];
    } else {
        $params_pending = [];
    }
    $stmt = query("SELECT id, username, email, role, is_premium, premium_until, created_at,
                           (SELECT ip_address FROM user_ip_history WHERE user_id = users.id ORDER BY created_at DESC LIMIT 1) AS last_ip
                           FROM users WHERE role = 'rookie' AND $where_clause_pending ORDER BY created_at DESC", $params_pending);
    $rookies = $stmt->fetchAll();

    // Get all approved users (both rookie and member)
    $where_clause_approved = "deleted_at IS NULL AND is_approved = 1";
    if ($search) {
        $where_clause_approved .= " AND username LIKE ?";
        $params_approved = ['%' . $search . '%'];
    } else {
        $params_approved = [];
    }
    $stmt = query("SELECT id, username, email, role, is_premium, premium_until, created_at,
                           (SELECT ip_address FROM user_ip_history WHERE user_id = users.id ORDER BY created_at DESC LIMIT 1) AS last_ip
                           FROM users WHERE $where_clause_approved ORDER BY created_at DESC", $params_approved);
    $users = $stmt->fetchAll();
}

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>

        <div class="admin-page">
            <h1 class="page-title">👥 Kullanıcı Yönetimi</h1>

            <?php if (isset($_SESSION['flash'])): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($_SESSION['flash']) ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div style="background:#ffebee;border:1px solid #e74c3c;padding:12px;margin-bottom:20px;border-radius:3px;color:#c62828;font-size:13px;">
                    <?= htmlspecialchars($_SESSION['flash_error']) ?>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <?php if ($history_user): ?>
                <div class="section">
                    <h2>📍 @<?= htmlspecialchars($history_user['username']) ?> için IP Geçmişi</h2>
                    <p><strong>Email:</strong> <?= htmlspecialchars($history_user['email'] ?? '-') ?></p>
                    <p><a class="btn" href="<?= BASE_PATH ?>/admin/users.php">← Kullanıcılar listesine geri dön</a></p>
                </div>
                <div class="section">
                    <?php if (count($ip_history) > 0): ?>
                        <div class="admin-table-wrapper">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>IP Adresi</th>
                                        <th>Kaydedilen Zaman</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ip_history as $index => $entry): ?>
                                        <tr>
                                            <td><?= $index + 1 ?></td>
                                            <td><?= htmlspecialchars($entry['ip_address']) ?></td>
                                            <td><?= date('d.m.Y H:i:s', strtotime($entry['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Bu kullanıcı için herhangi bir IP kaydı bulunamadı.</div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="search-section">
                    <form method="GET" action="" class="search-form">
                        <input type="text" name="search" placeholder="Kullanıcı adı ara..." value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn-search">🔍 Ara</button>
                        <?php if ($search): ?>
                            <a href="<?= BASE_PATH ?>/admin/users.php" class="btn-clear">✕ Temizle</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (count($rookies) > 0): ?>
            <div class="section">
                <h2>🔔 Onay Bekleyen Kullanıcılar (<?= count($rookies) ?>)</h2>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Kullanıcı</th>
                            <th>Email</th>
                            <th>IP</th>
                            <th>Kayıt Tarihi</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rookies as $r): ?>
                            <tr>
                                <td>@<?= htmlspecialchars($r['username']) ?></td>
                                <td><?= htmlspecialchars($r['email']) ?></td>
                                <td>
                                    <?php if (!empty($r['last_ip'])): ?>
                                        <a href="<?= BASE_PATH ?>/admin/users.php?user_id=<?= $r['id'] ?>"><?= htmlspecialchars($r['last_ip']) ?></a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></td>
                                <td class="admin-actions">
                                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_approve_user.php" style="display:inline">
                                        <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/users.php">
                                        <input type="hidden" name="user_id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <button type="submit" class="btn btn-approve">✓ Onayla</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="section">
                <h2>✅ Onaylanmış Kullanıcılar (<?= count($users) ?>)</h2>
                <?php if (count($users) > 0): ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Kullanıcı</th>
                            <th>Email</th>
                            <th>IP</th>
                            <th>Rol</th>
                            <th>Premium</th>
                            <th>Kayıt Tarihi</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <a href="<?= profile_url($u['username']) ?>" target="_blank">
                                        @<?= htmlspecialchars($u['username']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td>
                                    <?php if (!empty($u['last_ip'])): ?>
                                        <a href="<?= BASE_PATH ?>/admin/users.php?user_id=<?= $u['id'] ?>"><?= htmlspecialchars($u['last_ip']) ?></a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $primary = get_user_primary_role($u['id']); $role_key = $primary['key'] ?? ($u['role'] ?? 'member'); ?>
                                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_assign_role.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <select name="role_key">
                                            <?php foreach (get_all_roles() as $ropt): ?>
                                                <?php $label = t('role.' . $ropt['key']) ?? $ropt['name']; ?>
                                                <option value="<?= htmlspecialchars($ropt['key']) ?>" <?= ($ropt['key'] === $role_key) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?> (<?= htmlspecialchars($ropt['key']) ?>)</option>
                                            <?php endforeach; ?>
                                            <option value=""><?= t('none') ?? '(rol yok)' ?></option>
                                        </select>
                                        <button class="btn" type="submit"><?= t('save_btn') ?></button>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($u['is_premium']): ?>
                                        <span class="badge badge-premium">
                                            ⭐ Premium
                                            <?php if ($u['premium_until']): ?>
                                                <br><small><?= date('d.m.Y', strtotime($u['premium_until'])) ?></small>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-free">Free</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d.m.Y', strtotime($u['created_at'])) ?></td>
                                <td class="admin-actions">
                                    <?php if (!$u['is_premium']): ?>
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_assign_premium.php" style="display:inline;">
                                            <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/users.php">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="plan_type" value="lifetime">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <button type="submit" class="btn btn-premium">⭐ Premium Yap</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_revoke_premium.php" style="display:inline;">
                                            <input type="hidden" name="referer" value="<?= BASE_PATH ?>/admin/users.php">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <button type="submit" class="btn btn-revoke">↓ Premium İptal</button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if (($role_key ?? ($u['role'] ?? '')) !== 'rookie' && $u['id'] != $current_user_id): ?>
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_demote_user.php" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <button type="submit" class="btn btn-demote">⬇ Rookie Yap</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">Kullanıcı bulunamadı</div>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>