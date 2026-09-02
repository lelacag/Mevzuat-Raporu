<?php /* EN + TR comments used. */
// IAP module stub. To re-enable: set IAP_ENABLED=true and ensure modules/iap/ files are in place.
if (!defined('IAP_ENABLED')) define('IAP_ENABLED', false);
if (IAP_ENABLED) {
    require_once __DIR__ . '/../modules/iap/admin_iap_status.php';
    exit;
}
// Module inactive — show informational page
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
$current_user_id = get_current_user_id();
if (!$current_user_id) { header('Location: ' . BASE_PATH . '/giris'); exit; }
require_admin_perm('manage_billing');
$title = 'IAP Status';
include __DIR__ . '/_header.php';
include __DIR__ . '/_nav.php';
?>
<div class="admin-panel">
    <h1>IAP Status</h1>
    <p class="notice">The IAP (In-App Purchase) module is currently <strong>inactive</strong>.</p>
    <p>This module handles Google Play and App Store subscription validation for the mobile app.
    It has been intentionally disabled in this web-only environment.</p>
    <p>To re-enable, set <code>IAP_ENABLED = true</code> in <code>modules/iap/config.php</code>
    and configure the required environment variables (<code>IAP_API_TOKEN</code>,
    <code>GOOGLE_PLAY_SERVICE_ACCOUNT_JSON</code>, <code>APPLE_SHARED_SECRET</code>).</p>
    <p><a href="<?= BASE_PATH ?>/admin/premium_users.php" class="btn">Back to Premium</a></p>
</div>
