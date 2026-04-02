<?php
/**
 * Landing Page - Facebook Style Design with Our Customizations
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/captcha.php';
// Ensure cookie notice handler runs on landing (it must execute before output)
require_once __DIR__ . '/includes/cookie-notice-handler.php';

$user_id = get_current_user_id();

// If logged in, redirect to main feed
if ($user_id) {
    header('Location: ' . home_url());
    exit;
}

// Get 3 random posts using a bounded candidate sampling approach.
// This avoids expensive ORDER BY RAND() while providing good randomness.
$landing_posts = get_random_posts(3, 300);

// Handle registration
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['login_submit'])) {

    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = 'Geçersiz istek. Lütfen tekrar deneyin.';
    } else {
        $username      = sanitize_input($_POST['username'] ?? '');
        $password      = $_POST['password'] ?? '';
        $email         = sanitize_input($_POST['email'] ?? '');
        $captcha_input = $_POST['captcha'] ?? '';
        $captcha_token = $_POST['captcha_token'] ?? '';
        $identifier    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $captcha_result = verify_captcha($captcha_input, $captcha_token);
        if (!$captcha_result['valid']) {
            $errors[] = 'CAPTCHA verification failed: ' . $captcha_result['error'];
        }
        elseif (!check_rate_limit('register', $identifier, 2, 3600)) {
            $errors[] = 'Too many registration attempts. Please try again later.';
        }
        elseif (empty($username) || empty($password) || empty($email)) {
            $errors[] = 'All fields are required.';
        } elseif (strlen($username) < 3 || strlen($username) > 50) {
            $errors[] = 'Username must be between 3 and 50 characters.';
        } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters long.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address format.';
        } else {
            /* ---- Block reserved usernames from self-registration --- */
            if (is_reserved_username($username)) {
                $errors[] = 'Bu kullanıcı adı kullanılamaz.';
            } else {
                $stmt = query(
                    "SELECT id FROM users WHERE username = ? OR email = ?",
                    [$username, $email]
                );
                if ($stmt->fetch()) {
                    $errors[] = 'Username or email already exists.';
                } else {
                    $verification_token  = bin2hex(random_bytes(32));
                    $verification_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    $hash                = password_hash($password, PASSWORD_DEFAULT);

                    query(
                        "INSERT INTO users (username, password_hash, email, email_verified, is_approved, verification_token, verification_token_expiry)
                         VALUES (?, ?, ?, 0, 0, ?, ?)",
                        [$username, $hash, $email, $verification_token, $verification_expiry]
                    );
                    $user_id_new = insert_id();

                    if ($user_id_new) {
                        try {
                            query(
                                "UPDATE users SET accepted_terms = 1, accepted_privacy = 1, accepted_kvkk = 1, accepted_terms_at = NOW()
                                 WHERE id = ?",
                                [$user_id_new]
                            );
                        } catch (Exception $_) {}
                    }

                    $rookie_badge = query(
                        "SELECT id FROM badges WHERE slug = 'yeni-gelen' LIMIT 1"
                    )->fetch(PDO::FETCH_ASSOC);
                    if ($rookie_badge) {
                        query(
                            "INSERT INTO user_badges (user_id, badge_id) VALUES (?, ?)",
                            [$user_id_new, $rookie_badge['id']]
                        );
                    }

                    if ($user_id_new) {
                        $verification_url = full_url(BASE_PATH . '/verify_email.php?token=' . urlencode($verification_token));

                        $subject = 'E-posta Doğrulama - ' . SITE_NAME;
                        $message = "Merhaba " . htmlspecialchars($username) . ",\n\n";
                        $message .= SITE_NAME . " platformuna hoş geldiniz!\n\n";
                        $message .= "Hesabınızı aktifleştirmek için lütfen aşağıdaki bağlantıya tıklayın:\n\n";
                        $message .= $verification_url . "\n\n";
                        $message .= "Bu bağlantı 24 saat geçerlidir.\n\n";
                        $message .= "İyi günler!";

                        if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
                            $mail_sent = send_email($email, $subject, $message);
                            if (!$mail_sent) {
                                error_log('[LANDING] verification email send failure for ' . $email);
                                $errors[] = 'Doğrulama e-postası gönderilemedi. Lütfen destekle iletişime geçiniz.';
                            } else {
                                $success = true;
                            }
                        } else {
                            $errors[] = 'E-posta gönderimi kapalı. Lütfen yönetici ile iletişime geçiniz.';
                        }
                    } else {
                        $errors[] = 'Registration failed. Please try again.';
                    }
                }
            }
        }
    }
}

    
    $csrf_token = generate_csrf_token();
?>
<?php if ($_SERVER['REQUEST_URI'] == '/ascii'): ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?> - ASCII Landing</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 32px 16px;
            background: #fff;
            color: #000;
            font-family: 'Courier New', Courier, monospace;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }

        .ascii-block {
            width: 100%;
            max-width: 82ch;
        }

        pre.ascii-box {
            font-family: inherit;
            font-size: 14px;
            line-height: 1.4;
            white-space: pre;
            overflow-x: auto;
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
            border: none;
        }

        .ascii-form-section {
            width: 100%;
            border-top: 1px solid #aaa;
            margin-top: 0;
            padding: 20px 0 0 0;
        }

        .ascii-form-section h2 {
            font-family: inherit;
            font-size: 14px;
            font-weight: bold;
            margin: 0 0 6px 0;
            letter-spacing: 0.05em;
        }

        .ascii-form-section p {
            font-size: 13px;
            margin: 0 0 16px 0;
        }

        .ascii-form-section .field-row {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            gap: 8px;
        }

        .ascii-form-section .field-label {
            width: 18ch;
            flex-shrink: 0;
            font-size: 13px;
        }

        .ascii-form-section input[type="text"],
        .ascii-form-section input[type="email"],
        .ascii-form-section input[type="password"] {
            font-family: inherit;
            font-size: 13px;
            color: #000;
            background: #fff;
            border: 1px solid #000;
            padding: 4px 8px;
            flex: 1;
            min-width: 0;
        }

        .ascii-form-section .form-actions {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-top: 14px;
            flex-wrap: wrap;
        }

        .ascii-form-section button[type="submit"] {
            font-family: inherit;
            font-size: 13px;
            background: #000;
            color: #fff;
            border: 1px solid #000;
            padding: 6px 20px;
            cursor: pointer;
            letter-spacing: 0.05em;
        }

        .ascii-form-section button[type="submit"]:hover {
            background: #333;
        }

        .ascii-form-section a {
            color: #000;
            font-size: 13px;
        }

        .ascii-alerts { margin-bottom: 12px; font-size: 13px; }
        .ascii-alerts .err { color: #c00; }
        .ascii-alerts .ok  { color: #060; }

        .ascii-footer {
            margin-top: 24px;
            font-size: 12px;
            color: #444;
            border-top: 1px solid #aaa;
            padding-top: 12px;
        }

        .ascii-footer a { color: #444; }
    </style>
</head>
<body>
<div class="ascii-block">
<pre class="ascii-box">
╔══════════════════════════════════════════════════════════════════════════════╗
║                                                                              ║
║                            MEVZUAT RAPORU                                    ║
║                                                                              ║
║              RAHAT  •  GURULTUSUZ  •  YERLI MALI SOSYAL MEDYA               ║
╚══════════════════════════════════════════════════════════════════════════════╝

  Hos geldiniz!

  Mevzuat Raporu, gereksiz gurultu icermeyen, kisisel verilerinizi cope
  atmayan, ucuncu sahislara satmayan bir platformdur.

  Yerli mali, yurdun mali anlayisi ile herkesin rahatlıkla kullanabilecegi
  bir alan yaratmak istedik.

  www.mevzuatraporu.com

════════════════════════════════════════════════════════════════════════════════
  SON PAYLAŞIMLAR
════════════════════════════════════════════════════════════════════════════════
<?php if (empty($landing_posts)): ?>
  Henuz gonderi yok. Giris yap ve ilk gonderini paylas!
<?php else: ?>
<?php foreach ($landing_posts as $post):
    $content = strip_tags(render_rich_text($post['content']));
    $content = preg_replace('/\s+/', ' ', trim($content));
    $content = wordwrap($content, 74, "\n  ", false);
?>
  [@<?= htmlspecialchars($post['username']) ?>]
  <?= $content ?>

  [ likes: <?= $post['like_count'] ?>  |  comments: <?= $post['comment_count'] ?> ]

────────────────────────────────────────────────────────────────────────────────
<?php endforeach; ?>
<?php endif; ?>

════════════════════════════════════════════════════════════════════════════════
  NEDEN MEVZUAT RAPORU?
════════════════════════════════════════════════════════════════════════════════
  [*] Tamamen JavaScript'siz
  [*] Takip edilmiyorsunuz
  [*] Verileriniz satilmiyor
  [*] Sade ve hızlı
  [*] Turk yapimi

════════════════════════════════════════════════════════════════════════════════
  (C) 2026 Mevzuat Raporu  |  Her sey metin tabanli. Daha fazlasina gerek yok.
════════════════════════════════════════════════════════════════════════════════
</pre>

<div class="ascii-form-section">
    <h2>[ HEMEN KATIL ]</h2>
    <p>Hesap olusturmak cok basit. Formu doldurarak uye olabilirsiniz.</p>

    <?php if (!empty($errors)): ?>
    <div class="ascii-alerts">
        <?php foreach ($errors as $error): ?>
        <div class="err">  [!] <?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="ascii-alerts">
        <div class="ok">  [OK] Kayit basarili! E-posta adresinizi kontrol edin.</div>
    </div>
    <?php else: ?>
    <?php
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $country = get_country_by_ip($ip);
    if ($country === 'TR' || is_country_open($country)):
    ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="field-row">
            <span class="field-label">Kullanici Adi :</span>
            <input type="text" name="username" required autocomplete="username">
        </div>
        <div class="field-row">
            <span class="field-label">E-posta       :</span>
            <input type="email" name="email" required autocomplete="email">
        </div>
        <div class="field-row">
            <span class="field-label">Sifre         :</span>
            <input type="password" name="password" required autocomplete="new-password">
        </div>
        <?php render_captcha(); ?>
        <div class="form-actions">
            <button type="submit" name="sign-up">[ KAYIT OL ]</button>
            <a href="<?= BASE_PATH ?>/login.php">Zaten hesabin var mi? Giris yap</a>
        </div>
    </form>
    <?php else: ?>
    <div class="ascii-alerts">
        <div class="err">  [!] Bu ulkeden (<?= htmlspecialchars($country) ?>) dogrudan kayit alinmamaktadir.</div>
    </div>
    <p><a href="<?= BASE_PATH ?>/signup_request.php">Kayit talebi olusturmak icin tiklayin</a></p>
    <?php endif; ?>
    <?php endif; ?>
</div>

<div class="ascii-footer">
    <a href="<?= privacy_url() ?>">Gizlilik</a> &nbsp;|&nbsp;
    <a href="<?= rules_url() ?>">Kurallar</a> &nbsp;|&nbsp;
    <a href="<?= kvkk_url() ?>">KVKK</a> &nbsp;|&nbsp;
    <a href="<?= BASE_PATH ?>">Ana Sayfa</a>
</div>
</div>
</body>
</html>
<?php else: ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?> - Hoş Geldiniz</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/captcha.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/landing.css">
</head>
<body class="page-landing">
    <!-- HEADER SECTION START -->
    <header class="header-section">
        <div class="header-container">
            <div class="w-25">
                <div class="logo-area">
                    <?php $logo_path = __DIR__ . '/assets/logo-green.svg'; $logo_ver = (file_exists($logo_path) ? filemtime($logo_path) : time()); ?>
                    <a href="<?= BASE_PATH ?>" class="logo-landing">
                        <img src="<?= BASE_PATH ?>/assets/logo-green.svg?v=<?= $logo_ver ?>" alt="logo" class="site-logo">
                        <span class="logo-text">
                            <span class="site-name"><?= SITE_NAME ?></span>
                            <span class="logo-version">deneme sürüm 1.1</span>
                        </span>
                    </a>
                </div>
            </div>
            <div class="w-75">
                <div class="login-section w-75">
                    <?php if (isset($_SESSION['login_error'])): ?>
                        <div class="form-alert form-alert-error">
                            <?= htmlspecialchars($_SESSION['login_error']) ?>
                        </div>
                        <?php unset($_SESSION['login_error']); ?>
                    <?php endif; ?>
                    <form action="<?= BASE_PATH ?>/api/login.php" method="POST"> <!-- API endpoint remains php -->
                        <?php if (!empty($_GET['reject_cookies'])): ?>
                            <input type="hidden" name="reject_cookies" value="1">
                            <div style="color:#e67e22;font-size:12px;margin-bottom:6px;">Bu oturum için çerez kullanmadan siteyi kullanmayı seçtiniz.</div>
                        <?php endif; ?>
                        <div class="w-40 spece">
                            <label for="email-or-phone">Kullanıcı adı</label>
                            <input type="text" name="email_or_username" id="email-or-phone" required />
                        </div>
                        <div class="w-40 spece">
                            <label for="password">Şifre</label>
                            <input type="password" name="password" id="password" required />
                            <a href="<?= BASE_PATH ?>/forgot_password.php">Şifreni mi unuttun?</a>
                        </div>

                        <div class="w-20 spece">
                            <input type="submit" name="login_submit" id="submit" value="Giriş" />
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </header>
    <!-- HEADER SECTION END -->

    <!-- CONTENT SECTION START -->
    <div class="content-section">
        <div class="content-container">
            <!-- Posts Section -->
            <div class="content-item posts-section">
                <div class="help-heading">
                    <h2><?= SITE_NAME ?> rahat bir sosyal medya platformudur. Gereksiz gürültü içermez, kişisel bilgilerinizi çarçur etmez ve üçüncü, dördüncü kişilere
         satmaz. Yerli malı, yurdun malı herkes onu kullanmalı anlayışıyla başlamıştır. Hoş geldiniz!</h2>
                </div>
                <div class="posts-feed">
                    <?php if (empty($landing_posts)): ?>
                        <div style="text-align: center; padding: 30px 10px; color: #666; font-size: 12px;">
                            <p>Henüz gönderi yok. Giriş yap ve ilk gönderini paylaş!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($landing_posts as $post): ?>
                            <div class="post-feed-item">
                                <div class="post-feed-header">
                                    <a href="<?= profile_url($post['username']) ?>" class="post-feed-username">@<?= htmlspecialchars($post['username']) ?></a>
                                </div>
                                <div class="post-feed-content landing-post-preview">
                                    <?= render_rich_text($post['content']) ?>
                                </div>
                                <div class="post-feed-meta">
                                    <span>♡ <?= $post['like_count'] ?></span>
                                    <span>💬 <?= $post['comment_count'] ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Registration Section -->
            <div class="content-item registration-section">
                <div class="main-heading">
                    <h2>Hesap kayıt işlerin için buradan devam</h2>
                    <h3> Formu doldurarak kolay bir şekilde üye olabilirsiniz</h3>
                </div>

                <div class="form-section">
                    <?php if (!empty($errors)): ?>
                        <div class="form-alert form-alert-error">
                            <?php foreach ($errors as $error): ?>
                                ✗ <?= htmlspecialchars($error) ?><br>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="form-alert form-alert-success">
                            ✓ Kayıt başarılı! E-posta adresinizi kontrol edin.
                        </div>
                    <?php else: ?>
                        <?php
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                        $country = get_country_by_ip($ip);
                        if ($country === 'TR' || is_country_open($country)):
                        ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                            <div class="w-50 i-spece-one">
                                <input type="email" name="email" id="email" placeholder="E-posta" required />
                            </div>
                            <div class="w-50 i-spece-two">
                                <input type="text" name="username" id="username" placeholder="Kullanıcı adı" required />
                            </div>

                            <input type="password" name="password" id="new-password" placeholder="Yeni şifre" required />

                            <?php render_captcha(); ?>

                            <div class="agree-text" style="margin: 20px 0; color:#555; font-size:14px;">
                                <p style="margin:0;"><?= sprintf(t('register_agree_text'), '<a href="' . rules_url() . '" target="_blank">' . t('rules_and_terms') . '</a>', '<a href="' . privacy_url() . '" target="_blank">' . t('privacy_policy') . '</a>') ?></p>
                                <p style="margin:6px 0 0 0; color:#777; font-size:12px;"><?= sprintf(t('register_agree_additional'), '<a href="' . kvkk_url() . '" target="_blank">' . t('kvkk_policy') . '</a>', '<a href="' . cookie_policy_url() . '" target="_blank">' . t('cookie_policy_title') . '</a>') ?></p>
                            </div>

                            <div class="submit">
                                <input type="submit" name="sign-up" id="sign-up" value="Hesap Oluştur" />
                            </div>
                        </form>
                        <?php else: ?>
                            <div class="form-alert form-alert-error">Şu anda bulunduğunuz ülkeden (<?= htmlspecialchars($country) ?>) doğrudan kayıt alınmamaktadır.</div>
                            <p><a href="<?= BASE_PATH ?>/signup_request.php">Kayıt talebi oluşturmak için tıklayın</a> — e-posta ile bilgilendirileceksiniz.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <!-- CONTENT SECTION END -->

    <!-- FOOTER SECTION START -->
    <footer class="footer-section">
        <div class="footer-container">
            <div class="other-pages-link">
                <div class="line"></div>
                <ul>
                    <li><a href="<?= rules_url() ?>"><?= t('rules_and_terms') ?></a></li>
                    <li><a href="<?= privacy_url() ?>">Gizlilik</a></li>
                    <li><a href="<?= kvkk_url() ?>">KVKK</a></li>
                    <li><a href="<?= cookie_policy_url() ?>">Çerezler</a></li>
                    <li><a href="<?= BASE_PATH ?>/ascii_landing.php">ASCII Landing</a></li>
                    <?php if (is_admin()): ?>
                        <li><a href="<?= BASE_PATH ?>/admin/">Yönetim</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="copywrite">
                <p>&copy; <?= date('Y') ?> <?= SITE_NAME ?>. Tüm hakları saklıdır. Bulana çerek altın veriyoruz.🟡  </p>
            </div>
        </div>
    </footer>
    <!-- FOOTER SECTION END -->
</body>
</html>
<?php endif; ?>