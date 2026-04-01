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
        $username      = trim($_POST['username'] ?? '');
        $password      = $_POST['password'] ?? '';
        $email         = trim($_POST['email'] ?? '');
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
                            <span class="logo-version">deneme sürüm 1.01</span>
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
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
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
                    <h2>Hesap kayıt işleri için buradan devam</h2>
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