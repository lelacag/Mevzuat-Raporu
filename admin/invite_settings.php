<?php
// Admin-only stub for invite settings
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

if (!is_admin()) {
    header('Location: ' . BASE_PATH . '/');
    exit;
}

if (!defined('INVITES_MODULE_ENABLED') || !INVITES_MODULE_ENABLED) {
    http_response_code(404);
    echo "<div class=\"form-alert form-alert-error\">Sayfa bulunamadı.</div>";
    exit;
}

$moduleFile = __DIR__ . '/../modules/invites/admin_settings.php';
if (!is_file($moduleFile) || !is_readable($moduleFile)) {
    http_response_code(404);
    echo "<div class=\"form-alert form-alert-error\">Sayfa bulunamadı.</div>";
    exit;
}

require $moduleFile;
