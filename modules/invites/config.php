<?php
// invites module configuration
// enabled via INVITES_ENABLED environment variable

define('INVITES_MODULE_ENABLED', getenv('INVITES_ENABLED') === 'true');

if (INVITES_MODULE_ENABLED) {
    // additional invite-specific configuration can go here
    // e.g. default premium length
    define('INVITES_PREMIUM_DAYS', intval(getenv('INVITES_PREMIUM_DAYS') ?: 30));
}

// provide fallbacks when module is disabled; config.php is always loaded by
// the generic module loader so the helpers exist regardless of state.
if (!function_exists('accept_invite_if_valid')) {
    function accept_invite_if_valid($user_id, $email) {
        return false;
    }
}
if (!function_exists('invite_status_label')) {
    function invite_status_label($status) {
        return htmlspecialchars($status);
    }
}
