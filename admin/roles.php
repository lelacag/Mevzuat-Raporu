<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Only superadmins may manage roles in Phase-1
if (!is_logged_in() || !is_admin()) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

$roles = get_all_roles();
$permissions = get_all_permissions();

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>

<div class="admin-page">
    <h1 class="page-title">🔐 <?= t('admin_roles_title') ?></h1>

    <div class="section">
        <p><?= t('roles_list_heading') ?> — rollere atanan izinleri buradan düzenleyebilirsiniz. Süper yöneticiler kullanıcı sayfasından rolleri atayabilir.</p>
    </div>

    <div class="section">
        <h2><?= t('roles_list_heading') ?></h2>
        <div class="admin-table-wrapper">
            <table class="admin-table roles-table">
                <thead>
                    <tr>
                        <th><?= t('role') ?? 'Rol' ?></th>
                        <th><?= t('description') ?? 'Açıklama' ?></th>
                        <th><?= t('roles_permissions_column') ?></th>
                        <th><?= t('roles_actions_column') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $r): ?>
                        <?php $rp = get_role_permissions($r['id']); ?>
                        <tr>
                            <td class="role-name-cell">
                                <strong><?= htmlspecialchars(t('role.' . $r['key']) ?? $r['name']) ?></strong>
                                <code><?= htmlspecialchars($r['key']) ?></code>
                            </td>
                            <td class="role-desc-cell"><?= htmlspecialchars($r['description']) ?></td>
                            <td class="role-perms-cell">
                                <!-- Read-only view -->
                                <div class="perms-grid perms-readonly" id="readonly-<?= htmlspecialchars($r['key']) ?>">
                                    <?php foreach ($permissions as $p): ?>
                                        <label class="perm-label">
                                            <input type="checkbox" disabled <?= isset($rp[$p['key']]) ? 'checked' : '' ?>>
                                            <span><?= htmlspecialchars(t('perm.' . $p['key']) ?? $p['name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <!-- Edit view -->
                                <div class="perms-edit" id="edit-<?= htmlspecialchars($r['key']) ?>" style="display:none;">
                                    <form method="POST" action="<?= BASE_PATH ?>/api/admin_update_role.php">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="role_key" value="<?= htmlspecialchars($r['key']) ?>">
                                        <div class="perms-grid">
                                            <?php foreach ($permissions as $p): ?>
                                                <label class="perm-label">
                                                    <input type="checkbox" name="perms[]" value="<?= htmlspecialchars($p['key']) ?>" <?= isset($rp[$p['key']]) ? 'checked' : '' ?>>
                                                    <span><?= htmlspecialchars(t('perm.' . $p['key']) ?? $p['name']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="perms-edit-actions">
                                            <button class="btn" type="submit"><?= t('save_btn') ?></button>
                                            <a href="#" class="btn btn-muted" onclick="toggleRoleEdit('<?= htmlspecialchars($r['key']) ?>');return false;"><?= t('cancel_btn') ?></a>
                                        </div>
                                    </form>
                                </div>
                            </td>
                            <td class="role-actions-cell">
                                <a class="btn" href="#" onclick="toggleRoleEdit('<?= htmlspecialchars($r['key']) ?>');return false;" id="btn-<?= htmlspecialchars($r['key']) ?>"><?= t('edit_btn') ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="section">
        <h2><?= t('roles_create_heading') ?></h2>
        <form method="POST" action="<?= BASE_PATH ?>/api/admin_create_role.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="role-create-form">
                <input name="key" placeholder="role_key (ör. moderator)" required>
                <input name="name" placeholder="Görünür ad" required>
                <input name="description" placeholder="Kısa açıklama">
                <button class="btn" type="submit"><?= t('save_btn') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleRoleEdit(key) {
    var ro = document.getElementById('readonly-' + key);
    var ed = document.getElementById('edit-' + key);
    var btn = document.getElementById('btn-' + key);
    if (!ro || !ed) return;
    var showing = ed.style.display !== 'none';
    ro.style.display = showing ? '' : 'none';
    ed.style.display = showing ? 'none' : '';
    if (btn) btn.textContent = showing ? '<?= t('edit_btn') ?>' : '<?= t('cancel_btn') ?>';
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>