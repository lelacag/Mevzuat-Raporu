<?php /* EN + TR comments used. */
// admin/_header.php — reuse site header, ensure admin CSS is loaded and open admin container wrappers
$admin_css_path = __DIR__ . '/../assets/css/admin.css';
$admin_css_ver = file_exists($admin_css_path) ? filemtime($admin_css_path) : time();
// Use an absolute root-relative URL so this works even before config constants are loaded.
// The CSS file is served from /assets/css/admin.css.
$admin_css_url = '/assets/css/admin.css';
$extra_head = '<link rel="stylesheet" href="' . htmlspecialchars($admin_css_url, ENT_QUOTES, 'UTF-8') . '?v=' . $admin_css_ver . '">';
// Fallback inline CSS in case external stylesheet fails to load (helps in debugging).
// This is intentionally limited but should make the admin UI readable even when the external
// stylesheet is not applied (e.g. caching issues, CDN problems, or proxy rewrites).
$extra_head .= '<style>\n';
$extra_head .= 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f5f6f8;color:#1a1a1a;}\n';
$extra_head .= '.admin-page{max-width:1200px;margin:0 auto;padding:20px;}\n';
$extra_head .= '.section{margin-bottom:30px;background:#fff;padding:0;border-radius:3px;box-shadow:0 2px 4px rgba(0,0,0,0.08);border:1px solid #e0e0e0;}\n';
$extra_head .= '.section h2{margin:0;padding:15px 20px;color:#333;font-size:16px;font-weight:700;background:#fafafa;border-bottom:2px solid #e0e0e0;}\n';
$extra_head .= '.form-group{margin-bottom:16px;}\n';
$extra_head .= 'label{display:block;font-weight:600;margin-bottom:5px;}\n';
$extra_head .= '.form-control{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:3px;font-size:14px;box-sizing:border-box;}\n';
$extra_head .= '.helper-text{color:#666;font-size:12px;margin-top:4px;display:block;}\n';
$extra_head .= '.flex-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;}\n';
$extra_head .= '.btn{display:inline-block;padding:8px 14px;border-radius:3px;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer;border:none;}\n';
$extra_head .= '.btn-approve{background:#5a9a3c;color:#fff!important;}\n';
$extra_head .= '.btn-cancel{background:#ddd;color:#333;}\n';
$extra_head .= '.admin-table{width:100%;border-collapse:collapse;margin-top:10px;}\n';
$extra_head .= '.admin-table th,.admin-table td{padding:12px 15px;text-align:left;border-bottom:1px solid #f0f0f0;font-size:13px;}\n';
$extra_head .= '.admin-table th{background:#f9f9f9;font-weight:700;color:#666;font-size:12px;text-transform:uppercase;letter-spacing:0.3px;}\n';
$extra_head .= '.admin-table tr:hover{background:#fafafa;}\n';
$extra_head .= '.badge{display:inline-block;padding:3px 8px;border-radius:3px;font-size:10px;font-weight:700;text-transform:uppercase;}\n';
$extra_head .= '.badge-premium{background:#5a9a3c;color:#fff;}\n';
$extra_head .= '.badge-rookie{background:#fff3cd;color:#856404;}\n';
// Admin nav fallback styling (ensures sidebar nav is visible even if external CSS fails)
$extra_head .= '.admin-nav{background:#fff;border-bottom:2px solid #e0e0e0;padding:8px 10px;display:flex;flex-wrap:wrap;gap:6px;margin-bottom:20px;}\n';
$extra_head .= '.admin-nav a{display:inline-block;padding:6px 10px;border-radius:4px;background:#f4f4f4;color:#333;text-decoration:none;font-weight:600;}\n';
$extra_head .= '.admin-nav a:hover{background:#e8e8e8;}\n';
$extra_head .= '.admin-nav a.active{background:#5a9a3c;color:#fff;}\n';
$extra_head .= '</style>';

require_once __DIR__ . '/../includes/header.php';
?>
<div class="main-container">
  <div class="content-wrapper">
    <header style="margin-bottom:18px;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <h1 style="margin:0;font-size:20px;">Admin Console</h1>
        <a href="<?= BASE_PATH ?>/admin/index.php" class="btn btn-save">Back to Dashboard</a>
      </div>
    </header>

    <?php
    // Small IAP status banner (quick glance)
    $iap_ok = getenv('IAP_TEST_MODE') === '1' || ((getenv('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON') ?: '') !== '' && (getenv('APPLE_SHARED_SECRET') ?: '') !== '');
    $iap_color = $iap_ok ? 'success' : 'error';
    $iap_text = $iap_ok ? 'IAP ready (test mode or production creds present)' : 'IAP incomplete — production credentials missing';
    ?>
    <div class="iap-banner <?= $iap_color ?>">
        <strong>IAP</strong>
        <span><?= htmlspecialchars($iap_text) ?></span>
        <a href="<?= BASE_PATH ?>/admin/iap_status.php" class="btn">Details</a>
        <a href="<?= BASE_PATH ?>/admin/iap_self_test.php" class="btn btn-save">Run Self-Test</a>
    </div>
