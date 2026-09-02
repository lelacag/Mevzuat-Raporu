<?php
// rbac module
if (!function_exists('is_reserved_username')) {
    function is_reserved_username($username) {
        $username = mb_strtolower(trim((string)$username));
        $reserved = ['mevzuatraporu', 'mevzuat', 'rapor'];
        return in_array($username, $reserved, true);
    }
}

if (!function_exists('get_all_roles')) {
    function get_all_roles() {
        $stmt = query("SELECT id, `key`, name, description FROM roles ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_all_permissions')) {
    function get_all_permissions() {
        $stmt = query("SELECT id, `key`, name, description FROM permissions ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_role_by_key')) {
    function get_role_by_key($role_key) {
        $stmt = query("SELECT id, `key`, name, description FROM roles WHERE `key` = ? LIMIT 1", [$role_key]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_role_permissions')) {
    function get_role_permissions($role_id) {
        $stmt = query("SELECT p.* FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = ?", [$role_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $r;
        }
        return $out;
    }
}

if (!function_exists('get_user_roles')) {
    function get_user_roles($user_id) {
        $stmt = query("SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?", [$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_user_primary_role')) {
    function get_user_primary_role($user_id) {
        $roles = get_user_roles($user_id);
        if (!empty($roles)) return $roles[0];
        $stmt = query("SELECT role FROM users WHERE id = ? LIMIT 1", [$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['role'])) {
            return ['key' => $row['role'], 'name' => ucfirst($row['role'])];
        }
        return null;
    }
}
