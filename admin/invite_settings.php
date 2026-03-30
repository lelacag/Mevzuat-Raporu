<?php
// stub for invite settings
if (!defined('INVITES_MODULE_ENABLED') || !INVITES_MODULE_ENABLED) {
    http_response_code(404);
    echo "<div class=\"form-alert form-alert-error\">Sayfa bulunamadı.</div>";
    exit;
}
require __DIR__ . '/../modules/invites/admin_settings.php';
