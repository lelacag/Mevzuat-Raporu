<?php
// notifications module
if (!function_exists('create_notification')) {
    function create_notification($user_id, $message, $link = null) {
        query("INSERT INTO notifications (user_id, message, link, read_at, created_at) VALUES (?, ?, ?, NULL, NOW())", [$user_id, $message, $link]);
    }
}

if (!function_exists('mark_notification_read')) {
    function mark_notification_read($notification_id) {
        query("UPDATE notifications SET read_at = NOW() WHERE id = ?", [$notification_id]);
    }
}

if (!function_exists('get_notifications_for_user')) {
    function get_notifications_for_user($user_id, $limit = 50) {
        $stmt = query("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?", [$user_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
