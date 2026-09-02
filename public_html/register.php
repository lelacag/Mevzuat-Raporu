<?php /* EN + TR comments used. */
/* ==============================================================
   register.php – registration page (CAPTCHA flow identical to landing.php)
   ============================================================== */

/* -----------------------------------------------------------------
   Debug helpers – remove once you are happy everything works
   ----------------------------------------------------------------- */

/* -----------------------------------------------------------------
   Core includes
   ----------------------------------------------------------------- */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/captcha.php';
require_once __DIR__ . '/includes/cookie-notice-handler.php';

/* -----------------------------------------------------------------
   We'll include the shared header later, after we've handled POST and set up
   any page-specific CSS/classes.  (header.php outputs the DOCTYPE/head/body.)
   ----------------------------------------------------------------- */

// page-specific body class for styling
$extra_body_classes = 'register-page';

/* -----------------------------------------------------------------
   If the visitor is already logged in, send them to the main feed
   ----------------------------------------------------------------- */
$user_id = get_current_user_id();
if ($user_id) {
    header('Location: ' . home_url());
    exit;
}

/* -----------------------------------------------------------------
   Initialise variables
   ----------------------------------------------------------------- */
$errors  = [];
$success = false;
$success_message = '';
if (!empty($_SESSION['register_success']) || (isset($_GET['registered']) && $_GET['registered'] === '1')) {
    $success = true;
    if (!empty($_SESSION['register_success_message'])) {
        $success_message = $_SESSION['register_success_message'];
        unset($_SESSION['register_success_message']);
    }
    unset($_SESSION['register_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $errors[] = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

/* -----------------------------------------------------------------
   Process POSTed registration data
   ----------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['login_submit'])) {
    /* ---- CSRF --------------------------------------------------- */
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = 'Geçersiz istek. Lütfen tekrar deneyin.';
    } else {
        /* ---- Gather & sanitise inputs ---------------------------- */
        $username      = sanitize_input($_POST['username'] ?? '');
        $password      = $_POST['password'] ?? '';
        $email         = mb_strtolower(sanitize_input($_POST['email'] ?? ''));
        $captcha_input = $_POST['captcha'] ?? '';
        $captcha_token = $_POST['captcha_token'] ?? '';
        $identifier    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        /* ---- Verify CAPTCHA (same flow as landing.php) ----------- */
        $captcha_result = verify_captcha($captcha_input, $captcha_token);
        if (!$captcha_result['valid']) {
            $errors[] = 'CAPTCHA verification failed: ' . $captcha_result['error'];
        } elseif (!check_rate_limit('register', $identifier, 5, 3600)) {
            $errors[] = 'Too many registration attempts. Please try again later.';
        } elseif (empty($username) || empty($password) || empty($email)) {
            $errors[] = 'All fields are required.';
        } elseif (mb_strlen(trim($username), 'UTF-8') < 3 || mb_strlen(trim($username), 'UTF-8') > 50) {
            $errors[] = 'Username must be between 3 and 50 characters.';
        } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters long.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address format.';
        } else {
            $username = trim(preg_replace('/\s+/u', ' ', $username));
            if (!preg_match('/^[\p{L}0-9 _-]+$/u', $username)) {
                $errors[] = 'Kullanıcı adı yalnızca harfler, rakamlar, boşluk, alt çizgi ve tire içerebilir.';
            } elseif (is_reserved_username($username)) {
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

                    $pdo_reg = db_connect();
                    $transaction_started = false;
                    try {
                        $transaction_started = $pdo_reg->beginTransaction();
                        if (!$transaction_started) {
                            error_log('[REGISTER] DB transaction could not be started; proceeding without explicit transaction.');
                        }

                        $stmt = $pdo_reg->prepare(
                            "INSERT INTO users (username, password_hash, email, email_verified, is_approved, verification_token, verification_token_expiry)
                             VALUES (?, ?, ?, 0, 0, ?, ?)"
                        );
                        $stmt->execute([$username, $hash, $email, $verification_token, $verification_expiry]);
                        $user_id_new = $pdo_reg->lastInsertId();

                        if ($user_id_new) {
                            ensure_user_slug_column();
                            update_user_slug($user_id_new, $username);

                            $ip = get_client_ip();
                            if ($ip !== '0.0.0.0') {
                                record_user_ip_history($user_id_new, $ip);
                            }

                            try {
                                $stmt2 = $pdo_reg->prepare(
                                    "UPDATE users SET accepted_terms = 1, accepted_privacy = 1, accepted_kvkk = 1, accepted_terms_at = NOW()
                                     WHERE id = ?"
                                );
                                $stmt2->execute([$user_id_new]);
                            } catch (Exception $_) {
                            }

                            $rookie_badge = query(
                                "SELECT id FROM badges WHERE slug = 'yeni-gelen' LIMIT 1"
                            )->fetch(PDO::FETCH_ASSOC);
                            if ($rookie_badge) {
                                $stmt3 = $pdo_reg->prepare(
                                    "INSERT INTO user_badges (user_id, badge_id) VALUES (?, ?)"
                                );
                                $stmt3->execute([$user_id_new, $rookie_badge['id']]);
                            }

                            $accepted = accept_invite_if_valid($user_id_new, $email);
                            if (!$accepted) {
                                mark_invite_as_already_registered($user_id_new, $email);
                            }
                        }

                        if ($transaction_started && $pdo_reg->inTransaction()) {
                            $pdo_reg->commit();
                        }
                    } catch (Exception $e) {
                        if ($transaction_started && $pdo_reg->inTransaction()) {
                            $pdo_reg->rollBack();
                        }
                        error_log('[REGISTER] DB error during registration: ' . $e->getMessage());
                        $user_id_new = null;
                    }

                    if (!empty($user_id_new)) {
                        $verification_url = full_url(BASE_PATH . '/verify_email.php?token=' . urlencode($verification_token));
                        $subject = '[Mevzuat Raporu] E-posta Doğrulama';

                        $template_body = render_email_template('register_verification.html', [
                            'site_name' => htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'),
                            'user_name' => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
                            'verification_url' => htmlspecialchars($verification_url, ENT_QUOTES, 'UTF-8'),
                            'expiry_hours' => '24',
                            'site_url' => htmlspecialchars(rtrim(SITE_URL, '/') . BASE_PATH, ENT_QUOTES, 'UTF-8'),
                            'support_email' => 'admin@mevzuatraporu.com',
                        ]);

                        if ($template_body !== '') {
                            $message = $template_body;
                        } else {
                            $message = "Merhaba " . htmlspecialchars($username) . ",\n\n";
                            $message .= SITE_NAME . " platformuna hoş geldiniz!\n\n";
                            $message .= "Hesabınızı aktifleştirmek için lütfen aşağıdaki bağlantıya tıklayın:\n\n";
                            $message .= $verification_url . "\n\n";
                            $message .= "Bu bağlantı 24 saat geçerlidir.\n\n";
                            $message .= "İyi günler!";
                        }

                        if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
                            $mail_sent = send_email($email, $subject, $message);
                            if (!$mail_sent) {
                                error_log('[REGISTER] verification email send failure for ' . $email . ' — account retained');
                                $_SESSION['register_success'] = 1;
                                $_SESSION['register_success_message'] = 'Hesabınız oluşturuldu, ancak doğrulama e-postası gönderilemedi. Lütfen e-posta adresinizi kontrol edin veya destek ile iletişime geçin.';
                                header('Location: ' . BASE_PATH . '/kayit?registered=1');
                                exit;
                            }
                            $_SESSION['register_success'] = 1;
                            header('Location: ' . BASE_PATH . '/kayit?registered=1');
                            exit;
                        }

                        $_SESSION['register_success'] = 1;
                        header('Location: ' . BASE_PATH . '/kayit?registered=1');
                        exit;
                    }

                    $errors[] = 'Registration failed. Please try again.';
                }
            }
        }
    }
}

/* -----------------------------------------------------------------
   Generate a fresh CSRF token for the form
   ----------------------------------------------------------------- */
$csrf_token = generate_csrf_token();

// Build page‑specific <head> additions (styles, links)
// use META_TITLE so header.php prints the <title> correctly
$META_TITLE = SITE_NAME . ' - Kayıt Ol';
$extra_head = "";
$extra_head .= "\n    <link rel=\"stylesheet\" href=\"" . BASE_PATH . "/assets/css/captcha.css\">";
$extra_head .= "\n    <link rel=\"stylesheet\" href=\"" . BASE_PATH . "/assets/landing.css\">";
$extra_head .= "\n    <!-- PAGE‑SPECIFIC STYLES – they affect ONLY register.php -->";

// include layout header (outputs <doctype>, <html>, <head> and opens <body>)
include __DIR__ . '/includes/landing_header.php';

?>
<!-- body tag already opened by header.php -->
<style>
    /* --------------------------------------------------------
       Override the generic landing page two‑column layout so the single
       registration card is centered.  the global sticky‑footer rules
       take care of vertical spacing; this only adjusts horizontal behaviour.
    -------------------------------------------------------- */
    .register-page .content-container {
        width: auto;             /* let the card size itself instead of 90% */
        margin: 0 auto;          /* center the container in the flex parent */
    }
    .register-page .content-item {
        width: auto !important;  /* cancel the default 50% float width */
        float: none !important;
        margin: 0 auto;
    }

    /* --------------------------------------------------------
       1️⃣  Center the registration card inside its container.  we rely on
           the global stylesheet to make .content-section take up the
           remaining vertical space, so there is no need for min-height or
           flex:1 here.
       -------------------------------------------------------- */
    .register-page .content-section {
        display: flex;
        justify-content: center;      /* horizontal centering */
        align-items: center;          /* vertical centering */
        padding-top: 0;               /* cancel any top padding from other CSS */
    }

    /* --------------------------------------------------------
       2️⃣  Limit the width of the registration card and keep
           internal text left‑aligned (readability)
       -------------------------------------------------------- */
    .register-page .registration-section {
        max-width: 460px;
        width: 100%;
        text-align: left;
        background: #fff;            /* ensure a white background */
        padding: 2rem;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    /* --------------------------------------------------------
       3️⃣  Center headings and any alert messages
       -------------------------------------------------------- */
    .register-page .registration-section .main-heading,
    .register-page .registration-section .form-alert {
        text-align: center;
    }

    /* --------------------------------------------------------
       4️⃣  Stack label + input vertically and add spacing
       -------------------------------------------------------- */
    .register-page .form-group {
        margin-bottom: 1rem;
        display: flex;
        flex-direction: column;
    }

    .register-page .form-group label {
        margin-bottom: 0.3rem;
        font-weight: 500;
    }

    .register-page .form-group input[type="text"],
    .register-page .form-group input[type="email"],
    .register-page .form-group input[type="password"] {
        width: 100%;
        padding: 0.45rem 0.6rem;
        box-sizing: border-box;
    }

    /* --------------------------------------------------------
       5️⃣  Center the CAPTCHA row (image + textbox)
       -------------------------------------------------------- */
    .register-page .captcha-row {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        justify-content: center;
    }

    .register-page .captcha-row input[type="text"] {
        width: 120px;
    }

    /* --------------------------------------------------------
       6️⃣  Center the submit button
       -------------------------------------------------------- */
    .register-page .form-group button,
    .register-page .form-group input[type="submit"] {
        display: block;
        margin: 0 auto;
        padding: 0.55rem 1.2rem;
    }

    /* --------------------------------------------------------
       7️⃣  Light page background so the white card pops out
       -------------------------------------------------------- */
    /* body.register-page is the correct selector; previous rule had no effect */
    body.register-page {
        background-color: #f9f9f9;
        /* make page a column flex container so footer is pushed to bottom */
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    /* spacing between card and footer is now handled globally; individual
       pages should only add extra bottom padding if they have unusual
       content. */

    /* agreement paragraph should not be flex items and center nicely */
    .register-page .agreement-text {
        display: block;           /* override form-group flex behaviour */
        font-size: 12px;
        color: #888;
        text-align: center;
        max-width: 460px;         /* keep same width as card */
        margin: 0 auto;
    }
    .register-page .agreement-text a {
        color: #5a9a3c;
        text-decoration: underline;
    }
</style>

<!-- body tag already opened by header.php -->

<div class="content-section">
    <div class="content-container">
        <div class="content-item registration-section">
            <div class="main-heading">
                <h2>Hesap oluştur</h2>
            </div>

            <div class="form-section">
                <?php if (!empty($errors)): ?>
                    <div class="form-alert form-alert-error">
                        <?php foreach ($errors as $error): ?>
                            ✗ <?= htmlspecialchars($error) ?> 
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="form-alert form-alert-success">
                        ✓ <?= htmlspecialchars($success_message ?: 'Kayıt başarılı! Lütfen e‑posta adresinizi doğrulamak için e‑posta kutunuzu kontrol edin.') ?>
                    </div>
                <?php else: ?>
                    <form method="post" action="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                        <div class="form-group">
                            <label for="username">Kullanıcı Adı</label>
                            <input type="text"
                                   id="username"
                                   name="username"
                                   minlength="3"
                                   maxlength="50"
                                   required
                                   value="<?= isset($_POST['username']) ? htmlspecialchars((string)$_POST['username'], ENT_QUOTES, 'UTF-8') : '' ?>">
                            <small>Kullanıcı adı en az 3, en fazla 50 karakter olmalıdır.</small>
                        </div>

                        <div class="form-group">
                            <label for="email">E‑posta</label>
                            <input type="email"
                                   id="email"
                                   name="email"
                                   maxlength="100"
                                   required
                                   value="<?= isset($_POST['email']) ? htmlspecialchars((string)$_POST['email'], ENT_QUOTES, 'UTF-8') : '' ?>">
                        </div>

                        <div class="form-group">
                            <label for="password">Şifre</label>
                            <input type="password"
                                   id="password"
                                   name="password"
                                   minlength="<?= MIN_PASSWORD_LENGTH ?>"
                                   required>
                        </div>

                        <!-- CAPTCHA – identical to landing.php -->
                        <div class="form-group">
                            <label for="captcha">Güvenlik Kodu</label>
                            <?php render_captcha(); // prints <img> + hidden token field ?>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Kayıt Ol</button>
                        </div>

                        <div class="form-group agreement-text" style="margin-top:10px;">
    Kaydol'a tıklayarak <a href="<?= rules_url() ?>" target="_blank"><?= t('rules_and_terms') ?></a>,
    <a href="<?= privacy_url() ?>" target="_blank"><?= t('privacy_policy') ?></a> ve
    <a href="<?= kvkk_url() ?>" target="_blank"><?= t('kvkk_policy') ?></a>'ni okuduğunuzu ve
    kabul ettiğinizi onaylamış olursunuz.
</div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>