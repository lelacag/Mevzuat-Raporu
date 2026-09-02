<?php
// IAP module stub. To re-enable: set IAP_ENABLED=true and ensure modules/iap/ files are in place.
if (!defined('IAP_ENABLED')) define('IAP_ENABLED', false);
if (IAP_ENABLED) {
    require_once __DIR__ . '/../modules/iap/apple_receipt.php';
    return;
}
if (!function_exists('apple_verify_receipt')) {
    function apple_verify_receipt($receipt) {
        return ['success' => false, 'error' => 'iap_disabled'];
    }
}
return;
