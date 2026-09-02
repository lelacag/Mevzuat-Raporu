<?php
// notifications module — helper functions only
// create_notification() lives in functions_legacy.php (correct schema)

if (!function_exists('mark_notification_read')) {
    function mark_notification_read($notification_id, $user_id = null) {
        if ($user_id !== null) {
            query("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?", [$notification_id, $user_id]);
        } else {
            query("UPDATE notifications SET read_at = NOW() WHERE id = ?", [$notification_id]);
        }
    }
}

if (!function_exists('get_notifications_for_user')) {
    function get_notifications_for_user($user_id, $limit = 50) {
        $stmt = query("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?", [$user_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
