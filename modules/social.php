<?php
// social module
if (!function_exists('follow_user')) {
    function follow_user($follower_id, $following_id) {
        if ($follower_id == $following_id) return false;
        if (!$follower_id || !$following_id) return false;
        try {
            query("INSERT IGNORE INTO followers (follower_id, following_id) VALUES (?, ?)", [$follower_id, $following_id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('get_followed_user_ids')) {
    function get_followed_user_ids($user_id) {
        if (!$user_id) return [];
        $stmt = query("SELECT following_id FROM followers WHERE follower_id = ?", [$user_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function($row){ return $row['following_id']; }, $results);
    }
}

if (!function_exists('get_friend_suggestions')) {
    function get_friend_suggestions($user_id, $limit = 5) {
        if (!$user_id) return [];

        $stmt = query(
            "SELECT u.id, u.username, u.is_online, u.last_activity, COUNT(*) AS mutual_count
             FROM follows f1
             JOIN follows f2 ON f1.following_id = f2.follower_id
             JOIN users u ON u.id = f2.following_id
             WHERE f1.follower_id = ? AND u.deleted_at IS NULL AND u.id != ?
               AND u.id NOT IN (SELECT following_id FROM follows WHERE follower_id = ?)
             GROUP BY u.id
             ORDER BY mutual_count DESC, u.last_activity DESC
             LIMIT ?",
            [$user_id, $user_id, $user_id, $limit]
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $res = [];
        $selected_ids = [];
        foreach ($rows as $r) {
            $mutual = intval($r['mutual_count']);
            $reason = $mutual > 0 ? ($mutual . ' ortak takipçi') : 'Önerilen';
            $res[] = ['id' => $r['id'], 'username' => $r['username'], 'is_online' => !empty($r['is_online']), 'last_activity' => $r['last_activity'], 'reason' => $reason];
            $selected_ids[] = $r['id'];
        }

        if (count($res) < $limit) {
            $remaining = $limit - count($res);
            $stmt2 = query("SELECT following_id FROM follows WHERE follower_id = ?", [$user_id]);
            $already = array_column($stmt2->fetchAll(), 'following_id');
            $exclude = array_unique(array_merge([$user_id], $already, $selected_ids));
            $placeholders = implode(',', array_fill(0, max(1, count($exclude)), '?'));
            $params = array_merge($exclude, [$remaining]);
            $sql = "SELECT id, username, is_online, last_activity FROM users WHERE deleted_at IS NULL AND id NOT IN ($placeholders) ORDER BY last_activity DESC LIMIT ?";
            $stmt3 = query($sql, $params);
            foreach ($stmt3->fetchAll() as $r) {
                $res[] = ['id' => $r['id'], 'username' => $r['username'], 'is_online' => !empty($r['is_online']), 'last_activity' => $r['last_activity'], 'reason' => 'Popüler üye'];
                if (count($res) >= $limit) break;
            }
        }

        return $res;
    }
}
