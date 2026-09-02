<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
// lang.php defines t(); must load before any translation helper/call below.
// (_header.php also loads it later via includes/header.php, but group_labels run first.)
require_once __DIR__ . '/../includes/lang.php';

// Role management requires the manage_roles permission (superadmin has it by default).
require_admin_perm('manage_roles');

// t() returns the key itself when missing; use explicit fallbacks for UI copy.
$tf = static function (string $key, string $fallback): string {
    $val = t($key);
    return ($val === $key || $val === '') ? $fallback : $val;
};

/**
 * Only permissions that are actually enforced by admin pages / APIs.
 * Dead keys still present in DB (approve_events, resolve_event_reports,
 * run_health_checks, view_debug) are intentionally hidden from this UI.
 */
$active_perm_keys = [
    'view_admin_dashboard',
    'manage_users',
    'approve_profiles',
    'view_reports',
    'moderate_content',
    'manage_bad_words',
    'manage_whitelist',
    'manage_roles',
    'manage_events',
    'manage_badges',
    'manage_billing',
    'view_billing_reports',
    'manage_notifications',
    'view_system_logs',
];

$perm_groups = [
    'panel' => ['view_admin_dashboard'],
    'users' => ['manage_users', 'approve_profiles', 'manage_roles', 'manage_badges'],
    'content' => ['view_reports', 'moderate_content', 'manage_bad_words', 'manage_whitelist'],
    'events' => ['manage_events'],
    'billing' => ['manage_billing', 'view_billing_reports'],
    'comms' => ['manage_notifications'],
    'system' => ['view_system_logs'],
];

$group_labels = [
    'panel' => $tf('roles_group_panel', 'Panel'),
    'users' => $tf('roles_group_users', 'Kullanıcılar'),
    'content' => $tf('roles_group_content', 'İçerik'),
    'events' => $tf('roles_group_events', 'Etkinlikler'),
    'billing' => $tf('roles_group_billing', 'Ödeme'),
    'comms' => $tf('roles_group_comms', 'Bildirimler'),
    'system' => $tf('roles_group_system', 'Sistem'),
];

$all_permissions = get_all_permissions();
$permissions_by_key = [];
foreach ($all_permissions as $p) {
    $permissions_by_key[$p['key']] = $p;
}

// Keep only active permissions, in the catalog order.
$permissions = [];
foreach ($active_perm_keys as $key) {
    if (isset($permissions_by_key[$key])) {
        $permissions[$key] = $permissions_by_key[$key];
    }
}

$roles = get_all_roles();
$edit_key = isset($_GET['edit']) ? preg_replace('/[^a-z0-9_\-]/i', '', (string)$_GET['edit']) : '';

// Non-admin roles cannot hold admin permissions; keep UI simple.
$non_admin_roles = ['member', 'rookie'];
// Superadmin always bypasses permission checks in code — editing its matrix is misleading.
$locked_roles = ['superadmin'];

include __DIR__ . '/_header.php';
require_once __DIR__ . '/_nav.php';
?>

<div class="admin-page">
    <h1 class="page-title">🔐 <?= htmlspecialchars(t('admin_roles_title')) ?></h1>

    <?php if (!empty($_SESSION['flash'])): ?>
        <div class="flash mb-20"><?= htmlspecialchars($_SESSION['flash']) ?></div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="flash flash-error mb-20"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <div class="section">
        <p class="muted"><?= htmlspecialchars($tf('roles_page_intro', 'Rollere atanan admin izinlerini buradan düzenleyin. Kullanıcı rolleri Kullanıcılar sayfasından atanır.')) ?></p>
    </div>

    <div class="section">
        <h2><?= htmlspecialchars(t('roles_list_heading')) ?></h2>
        <div class="admin-table-wrapper">
            <table class="admin-table roles-table">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars($tf('role', 'Rol')) ?></th>
                        <th><?= htmlspecialchars($tf('description', 'Açıklama')) ?></th>
                        <th><?= htmlspecialchars(t('roles_permissions_column')) ?></th>
                        <th><?= htmlspecialchars(t('roles_actions_column')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $r): ?>
                        <?php
                        $role_key = (string)$r['key'];
                        $rp = get_role_permissions($r['id']);
                        $is_edit = ($edit_key !== '' && $edit_key === $role_key);
                        $is_locked = in_array($role_key, $locked_roles, true);
                        $is_non_admin = in_array($role_key, $non_admin_roles, true);
                        $role_label = t('role.' . $role_key);
                        if ($role_label === 'role.' . $role_key || $role_label === '') {
                            $role_label = $r['name'];
                        }
                        $active_assigned = array_values(array_intersect(array_keys($rp), $active_perm_keys));
                        ?>
                        <tr class="<?= $is_edit ? 'role-row-editing' : '' ?>">
                            <td class="role-name-cell">
                                <strong><?= htmlspecialchars($role_label) ?></strong>
                                <code><?= htmlspecialchars($role_key) ?></code>
                            </td>
                            <td class="role-desc-cell"><?= htmlspecialchars((string)($r['description'] ?? '')) ?></td>
                            <td class="role-perms-cell">
                                <?php if ($is_non_admin): ?>
                                    <span class="role-empty-note"><?= htmlspecialchars($tf('roles_no_admin_perms', 'Admin izni yok')) ?></span>
                                <?php elseif ($is_locked && !$is_edit): ?>
                                    <span class="role-empty-note"><?= htmlspecialchars($tf('roles_superadmin_all', 'Tüm admin izinleri (kod düzeyinde tam erişim)')) ?></span>
                                    <details class="role-perm-details">
                                        <summary><?= htmlspecialchars($tf('roles_show_matrix', 'İzin matrisini göster')) ?></summary>
                                        <div class="perms-groups perms-readonly">
                                            <?php foreach ($perm_groups as $gkey => $keys): ?>
                                                <?php
                                                $group_perms = array_values(array_filter($keys, static function ($k) use ($permissions) {
                                                    return isset($permissions[$k]);
                                                }));
                                                if (!$group_perms) continue;
                                                ?>
                                                <div class="perm-group">
                                                    <div class="perm-group-title"><?= htmlspecialchars($group_labels[$gkey] ?? $gkey) ?></div>
                                                    <div class="perms-grid">
                                                        <?php foreach ($group_perms as $pkey): ?>
                                                            <?php $p = $permissions[$pkey]; ?>
                                                            <label class="perm-label">
                                                                <input type="checkbox" disabled checked>
                                                                <span><?= htmlspecialchars($tf('perm.' . $p['key'], $p['name'])) ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php elseif ($is_edit && !$is_locked && !$is_non_admin): ?>
                                    <div class="perms-edit" id="edit-<?= htmlspecialchars($role_key) ?>">
                                        <form method="POST" action="<?= BASE_PATH ?>/api/admin_update_role.php">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="role_key" value="<?= htmlspecialchars($role_key) ?>">
                                            <div class="perms-groups">
                                                <?php foreach ($perm_groups as $gkey => $keys): ?>
                                                    <?php
                                                    $group_perms = array_values(array_filter($keys, static function ($k) use ($permissions) {
                                                        return isset($permissions[$k]);
                                                    }));
                                                    if (!$group_perms) continue;
                                                    ?>
                                                    <div class="perm-group">
                                                        <div class="perm-group-title"><?= htmlspecialchars($group_labels[$gkey] ?? $gkey) ?></div>
                                                        <div class="perms-grid">
                                                            <?php foreach ($group_perms as $pkey): ?>
                                                                <?php $p = $permissions[$pkey]; ?>
                                                                <label class="perm-label">
                                                                    <input type="checkbox" name="perms[]" value="<?= htmlspecialchars($p['key']) ?>" <?= isset($rp[$p['key']]) ? 'checked' : '' ?>>
                                                                    <span><?= htmlspecialchars($tf('perm.' . $p['key'], $p['name'])) ?></span>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="perms-edit-actions">
                                                <button class="btn" type="submit"><?= htmlspecialchars(t('save_btn')) ?></button>
                                                <a href="<?= BASE_PATH ?>/admin/roles.php" class="btn btn-muted"><?= htmlspecialchars(t('cancel_btn')) ?></a>
                                            </div>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <?php if (empty($active_assigned)): ?>
                                        <span class="role-empty-note"><?= htmlspecialchars($tf('roles_no_admin_perms', 'Admin izni yok')) ?></span>
                                    <?php else: ?>
                                        <div class="perms-groups perms-readonly" id="readonly-<?= htmlspecialchars($role_key) ?>">
                                            <?php foreach ($perm_groups as $gkey => $keys): ?>
                                                <?php
                                                $group_assigned = array_values(array_filter($keys, static function ($k) use ($rp, $permissions) {
                                                    return isset($permissions[$k]) && isset($rp[$k]);
                                                }));
                                                if (!$group_assigned) continue;
                                                ?>
                                                <div class="perm-group">
                                                    <div class="perm-group-title"><?= htmlspecialchars($group_labels[$gkey] ?? $gkey) ?></div>
                                                    <ul class="perm-chip-list">
                                                        <?php foreach ($group_assigned as $pkey): ?>
                                                            <li class="perm-chip"><?= htmlspecialchars($tf('perm.' . $pkey, $permissions[$pkey]['name'] ?? $pkey)) ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="role-actions-cell">
                                <?php if ($is_non_admin || $is_locked): ?>
                                    <span class="muted"><?= htmlspecialchars($tf('roles_locked', 'Kilitli')) ?></span>
                                <?php elseif ($is_edit): ?>
                                    <a class="btn btn-muted" href="<?= BASE_PATH ?>/admin/roles.php"><?= htmlspecialchars(t('cancel_btn')) ?></a>
                                <?php else: ?>
                                    <a class="btn" href="<?= BASE_PATH ?>/admin/roles.php?edit=<?= rawurlencode($role_key) ?>"><?= htmlspecialchars(t('edit_btn')) ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="section">
        <h2><?= htmlspecialchars(t('roles_create_heading')) ?></h2>
        <form method="POST" action="<?= BASE_PATH ?>/api/admin_create_role.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="role-create-form">
                <input name="key" placeholder="role_key (ör. moderator)" pattern="[a-z0-9_\-]+" title="küçük harf, rakam, _ veya -" required>
                <input name="name" placeholder="Görünür ad" required>
                <input name="description" placeholder="Kısa açıklama">
                <button class="btn" type="submit"><?= htmlspecialchars(t('save_btn')) ?></button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
