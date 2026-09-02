<?php /* EN + TR comments used. */
// admin/_header.php — reuse site header, ensure admin CSS is loaded and open admin container wrappers
$admin_css_path = __DIR__ . '/../assets/css/admin.css';
$admin_css_ver = file_exists($admin_css_path) ? filemtime($admin_css_path) : time();
// Use an absolute root-relative URL so this works even before config constants are loaded.
// The CSS file is served from /assets/css/admin.css.
$admin_css_url = '/assets/css/admin.css';
if (!isset($extra_head)) {
    $extra_head = '';
}
$extra_head .= '<link rel="stylesheet" href="' . htmlspecialchars($admin_css_url, ENT_QUOTES, 'UTF-8') . '?v=' . $admin_css_ver . '">';
// Note: inline <style> blocks were removed — all admin styles are now in admin.css
// so they load via the <link> tag above (allowed by CSP style-src 'self') without
// needing a nonce. If you add new admin-specific classes, add them to admin.css.

require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-main-container" style="display:block!important;grid-template-columns:none!important;max-width:1200px!important;margin:0 auto!important;">
  <div class="content-wrapper">
    <header style="margin-bottom:18px;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <h1 style="margin:0;font-size:20px;">Admin Console</h1>
        <a href="<?= BASE_PATH ?>/admin/index.php" class="btn btn-save">Back to Dashboard</a>
      </div>
    </header>

    <?php /* IAP banner hidden — module inactive. Restore by setting IAP_ENABLED=true in modules/iap/config.php */ ?>
