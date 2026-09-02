<?php
// invitations module
if (!function_exists('get_invite_token')) {
    function get_invite_token($user_id) {
        $token = bin2hex(random_bytes(16));
        query("INSERT INTO invites (user_id, token, created_at) VALUES (?, ?, NOW())", [$user_id, $token]);
        return $token;
    }
}

if (!function_exists('validate_invite_token')) {
    function validate_invite_token($token) {
        $stmt = query("SELECT * FROM invites WHERE token = ? AND used = 0 LIMIT 1", [$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
