<?php
// user module
if (!function_exists('get_user_by_id')) {
    function get_user_by_id($user_id) {
        return query("SELECT * FROM users WHERE id = ? LIMIT 1", [$user_id])->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_user_slug')) {
    function get_user_slug($username) {
        $stmt = query("SELECT slug FROM users WHERE username = ? AND deleted_at IS NULL", [$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['slug'] ?? null;
    }
}

if (!function_exists('is_user_creation_restricted')) {
    function is_user_creation_restricted($user_id) {
        // placeholder for rookies/premium checks.
        return false;
    }
}
