<?php
// IAP module stub. To re-enable: set IAP_ENABLED=true and ensure modules/iap/ files are in place.
if (!defined('IAP_ENABLED')) define('IAP_ENABLED', false);
if (IAP_ENABLED) {
    require_once __DIR__ . '/../modules/iap/google_play.php';
    return;
}
if (!function_exists('google_play_verify_subscription')) {
    function google_play_verify_subscription($a, $b, $c) {
        return ['success' => false, 'error' => 'iap_disabled'];
    }
    function google_play_get_access_token() {
        return null;
    }
    function google_play_get_service_account() {
        return null;
    }
    function base64url_encode($d) {
        return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    }
}
return;
