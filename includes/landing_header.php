<?php
/**
 * Landing page header used only by landing_basic.php.
 * This provides a separate header structure and avoids the shared site header layout.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/cookie-notice-handler.php';

$csrf_token = generate_csrf_token();
$current_user_id = get_current_user_id();

$csp_nonce = base64_encode(random_bytes(16));
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self' 'nonce-" . $csp_nonce . "'; img-src 'self' data:; font-src 'self'; object-src 'none'; form-action 'self'; base-uri 'self'; frame-ancestors 'self'");
    header("Content-Security-Policy-Report-Only: default-src 'self'; script-src 'none'; style-src 'self' 'nonce-" . $csp_nonce . "'; img-src 'self' data: https:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; report-uri " . BASE_PATH . "/tools/csp_report.php");
}

$CURRENT_SID = null;
if (!empty($_REQUEST['sid'])) {
    $CURRENT_SID = preg_replace('/[^A-Za-z0-9._-]/', '', $_REQUEST['sid']);
    ob_start();
}

if (empty($extra_body_classes)) {
    $extra_body_classes = 'page-landing-basic';
}
$body_classes = is_array($extra_body_classes) ? $extra_body_classes : [$extra_body_classes];
$body_attr = $body_classes ? ' class="' . implode(' ', $body_classes) . '"' : '';

$main_css_path = __DIR__ . '/../assets/css/main.css';
$main_css_ver = file_exists($main_css_path) ? filemtime($main_css_path) : time();
$profile_css_path = __DIR__ . '/../assets/css/profile.css';
$profile_css_ver = file_exists($profile_css_path) ? filemtime($profile_css_path) : time();
$post_comment_css_path = __DIR__ . '/../assets/css/post_comment.css';
$group_comment_css_path = __DIR__ . '/../assets/css/group_comment.css';

$register_url = BASE_PATH . (use_clean_urls() ? '/kayit' : '/register.php');
$login_url = BASE_PATH . (use_clean_urls() ? '/giris' : '/login.php');

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title><?= htmlspecialchars(SITE_NAME . ' - Rahat Ağ', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/logo-green.svg">
    <link rel="shortcut icon" href="<?= BASE_PATH ?>/assets/logo-green.svg">
    <link rel="apple-touch-icon" href="<?= BASE_PATH ?>/assets/logo-green.svg">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css?v=<?= $main_css_ver ?>">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/icons.css?v=<?= file_exists(__DIR__ . '/../assets/css/icons.css') ? filemtime(__DIR__ . '/../assets/css/icons.css') : time() ?>">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/profile.css?v=<?= $profile_css_ver ?>">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/post_comment.css?v=<?= file_exists($post_comment_css_path) ? filemtime($post_comment_css_path) : time() ?>">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/group_comment.css?v=<?= file_exists($group_comment_css_path) ? filemtime($group_comment_css_path) : time() ?>">
    <?php if (!empty($extra_head)) { echo $extra_head; } ?>
    <style nonce="<?= htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8') ?>">
        .landing-header {
            width: 100%;
            background: #fff;
            border-bottom: 3px solid #5a9a3c;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            padding: 12px 18px;
        }
        .landing-header-inner {
            max-width: 1180px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
        }
        .landing-logo {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 20px;
            font-weight: 700;
            color: #5a9a3c;
            text-decoration: none;
            white-space: nowrap;
        }
        .landing-logo img, .landing-logo .site-logo {
            height: 28px;
            width: auto;
            display: inline-block;
            margin: 0;
            image-rendering: optimizeQuality;
            align-self: flex-start;
        }
        .landing-logo span {
            display: inline-block;
            line-height: 1;
            margin-top: 0;
        }
        .landing-logo .logo-text {
            display: flex;
            flex-direction: column;
            line-height: 1;
        }
        .landing-logo .logo-version {
            font-size: 11px;
            font-weight: 400;
            opacity: 0.75;
            margin-top: 2px;
        }
        .landing-header-center {
            flex: 1 1 420px;
            display: flex;
            justify-content: center;
            min-width: 260px;
        }
        .landing-search-form {
            width: 100%;
            max-width: 480px;
        }
        .landing-search-form .search-bar {
            width: 100%;
            min-width: 0;
        }
        .landing-header-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
            min-width: 190px;
            justify-content: flex-end;
        }
        .landing-header-right .btn {
            white-space: nowrap;
            padding: 6px 12px;
            font-size: 12px;
            line-height: 1.2;
        }
        .landing-header-right .btn-primary {
            background: #5a9a3c;
            color: #fff;
            border-color: #5a9a3c;
        }
        @media (max-width: 980px) {
            .landing-header-inner {
                justify-content: center;
                text-align: center;
            }
            .landing-logo {
                justify-content: center;
                min-width: auto;
            }
            .landing-header-center {
                order: 3;
                width: 100%;
                justify-content: center;
            }
            .landing-header-right {
                order: 2;
                width: 100%;
                justify-content: center;
                margin-top: 12px;
            }
        }
        @media (max-width: 640px) {
            .landing-header {
                padding: 16px 12px 14px;
            }
            .landing-header-right {
                flex-wrap: wrap;
                gap: 8px;
            }
            .landing-header-center {
                margin-top: 12px;
            }
            .landing-header-right {
                display: none;
            }
        }
    </style>
</head>
<body<?= $body_attr ?>>
    <header class="landing-header">
        <div class="landing-header-inner">
            <a href="<?= BASE_PATH ?>/" class="landing-logo logo">
                <img src="<?= BASE_PATH ?>/assets/logo-green.svg" alt="<?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?> logo" class="site-logo">
                <span class="logo-text">
                    <span class="site-name"><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="logo-version">deneme sürüm 1.03</span>
                </span>
            </a>
            <div class="landing-header-center">
                <form action="<?= BASE_PATH ?><?= search_url() ?>" method="GET" class="landing-search-form">
                    <input type="text" name="q" class="search-bar" placeholder="ara..." value="<?= htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </form>
            </div>
            <div class="landing-header-right">
                <a href="<?= $login_url ?>" class="btn btn-outline">Giriş Yap</a>
                <a href="<?= $register_url ?>" class="btn btn-primary">Kayıt Ol</a>
            </div>
        </div>
    </header>
