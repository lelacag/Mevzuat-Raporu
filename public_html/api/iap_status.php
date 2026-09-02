<?php
// IAP module stub. To re-enable: set IAP_ENABLED=true and ensure modules/iap/ files are in place.
if (!defined('IAP_ENABLED')) define('IAP_ENABLED', false);
if (IAP_ENABLED) {
    require_once __DIR__ . '/../modules/iap/api_iap_status.php'; exit;
}
header('Content-Type: text/plain');
http_response_code(503);
echo http_build_query(['error' => 'iap_disabled', 'message' => 'IAP module is inactive in this environment.']);
exit;
