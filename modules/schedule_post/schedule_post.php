<?php
// Schedule post helper functions; treat as reusable module for scheduled post feature.

function schedule_post_validate($user_id, $scheduled_at) {
    if ($scheduled_at === null || trim($scheduled_at) === '') {
        return ['success' => true, 'scheduled_at_sql' => null];
    }

    $ts = strtotime($scheduled_at);
    if (!$ts) {
        return ['success' => false, 'error' => 'scheduled_at_invalid'];
    }
    if ($ts <= time()) {
        return ['success' => false, 'error' => 'scheduled_at_past'];
    }
    if ($ts > strtotime('+365 days')) {
        return ['success' => false, 'error' => 'scheduled_at_too_far'];
    }

    // premium/admin requirement
    $is_admin = function_exists('is_admin') && is_admin();
    if (!is_user_premium($user_id) && !$is_admin) {
        return ['success' => false, 'error' => 'premium_required_scheduled_post'];
    }

    return ['success' => true, 'scheduled_at_sql' => date('Y-m-d H:i:s', $ts)];
}

function schedule_post_is_visible($post, $viewer_id = null) {
    if (empty($post['scheduled_at'])) {
        return true;
    }
    $scheduled_ts = strtotime($post['scheduled_at']);
    if ($scheduled_ts <= time()) {
        return true;
    }
    if ($viewer_id && (int)$viewer_id === (int)$post['user_id']) {
        return true;
    }
    if (function_exists('is_admin') && is_admin()) {
        return true;
    }
    return false;
}

