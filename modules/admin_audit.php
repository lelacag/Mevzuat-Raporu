<?php
// admin_audit module
if (!function_exists('block_ip')) {
    function block_ip($ip) {
        return query("INSERT IGNORE INTO blocked_ips (ip, created_at) VALUES (?, NOW())", [$ip]);
    }
}

if (!function_exists('is_ip_blocked')) {
    function is_ip_blocked($ip) {
        $stmt = query("SELECT 1 FROM blocked_ips WHERE ip = ? LIMIT 1", [$ip]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('log_admin_action')) {
    function log_admin_action($admin_id, $action, $target = null) {
        try {
            query("INSERT INTO admin_audit_logs (admin_id, action, target, created_at) VALUES (?, ?, ?, NOW())", [$admin_id, $action, $target]);
        } catch (PDOException $e) {
            // If audit table is missing, don't break user-facing actions
            if ($e->getCode() === '42S02') {
                return false;
            }
            throw $e;
        }
    }
}
