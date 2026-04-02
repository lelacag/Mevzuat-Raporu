<?php /* EN + TR comments used. */

$extra_head = "\n    <link rel=\"stylesheet\" href=\"/assets/landing.css\">"
    . "\n    <link rel=\"stylesheet\" href=\"/assets/css/captcha.css\">";
$extra_body_classes = ['login-page'];

require_once __DIR__ . '/includes/header.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection. If the user explicitly requests to reject cookies,
    // we cannot rely on session-stored CSRF tokens (session cookie may be blocked).
    // In that case, skip CSRF verification for this POST only.
    // Honor reject_cookies either from a POST (form) or a GET flag (user chose "use without cookies")
    $reject_cookies_post = !empty($_POST['reject_cookies']) || (isset($_GET['reject_cookies']) && $_GET['reject_cookies'] == '1');
    if (!$reject_cookies_post) {
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            $errors[] = 'Invalid request. Please try again.';
        } else {
            // proceed normally
        }
    } else {
        // Proceed without CSRF token for reject-cookie login flow.
    }
    if (!empty($errors)) {
        // don't process further if CSRF failed
    } else {
        $username = sanitize_input($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $errors[] = 'Username ve password zorunlu.';
        } else {
            $result = login($username, $password);
            if ($result === true) {
                $_SESSION['username'] = $username;

                // If maintenance mode is on, only allow admin accounts to finish login.
                if (function_exists('is_maintenance_mode') && is_maintenance_mode() && !is_admin()) {
                    // force logout
                    session_unset();
                    session_destroy();
                    $errors[] = 'Bakım sırasında yalnızca yönetim girişi yapılabilir.';
                } else {
                    // If the user explicitly chose to reject cookies, create a URL-session
                    // so they can remain logged in without accepting cookies.
                    if (!empty($_POST['reject_cookies'])) {
                        // try to resolve user id
                        $user_id = $_SESSION['user_id'] ?? null;
                        if (!$user_id) {
                            $stmt = query("SELECT id FROM users WHERE username = ? LIMIT 1", [$username]);
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            $user_id = $row['id'] ?? null;
                        }
                        if ($user_id) {
                            // Prefer stateless signed token for no-cookie login
                            try {
                                $sid = create_stateless_url_token($user_id);
                                if ($sid) {
                                    $url = BASE_PATH . '/anasayfa?sid=' . htmlspecialchars($sid, ENT_QUOTES, 'UTF-8');
                                    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Redirecting…</title>";
                                    echo "<meta http-equiv=\"refresh\" content=\"0;url={$url}\">";
                                    echo "</head><body style=\"font-family:Arial,sans-serif;line-height:1.6;\">";
                                    echo "<p>Login successful. If you are not redirected automatically, <a href=\"{$url}\">click here</a>.</p>";
                                    echo "</body></html>";
                                    exit;
                                }
                            } catch (Exception $e) {
                                error_log('Stateless URL token creation failed during login.php: ' . $e->getMessage());
                            }
                            // Fallback to DB-backed token
                            try {
                                $sid2 = create_url_session($user_id);
                                if ($sid2) {
                                    $url = BASE_PATH . '/anasayfa?sid=' . htmlspecialchars($sid2, ENT_QUOTES, 'UTF-8');
                                    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Redirecting…</title>";
                                    echo "<meta http-equiv=\"refresh\" content=\"0;url={$url}\">";
                                    echo "</head><body style=\"font-family:Arial,sans-serif;line-height:1.6;\">";
                                    echo "<p>Login successful. If you are not redirected automatically, <a href=\"{$url}\">click here</a>.</p>";
                                    echo "</body></html>";
                                    exit;
                                }
                            } catch (Exception $e) {
                                error_log('DB URL session creation failed during login.php: ' . $e->getMessage());
                            }
                        }
                    }

                    // Check if user needs to set birthday
                    $user_id = get_current_user_id();
                    $user = get_user($user_id);
                if (empty($user['birthday'])) {
                    header('Location: ' . BASE_PATH . '/set_birthday.php');
                    exit;
                }
                
                // Check if user is under 16
                if (!empty($user['birthday'])) {
                    $birth_date = new DateTime($user['birthday']);
                    $today = new DateTime();
                    $age = $today->diff($birth_date)->y;
                    
                    if ($age < 16) {
                        header('Location: ' . BASE_PATH . '/underage.php');
                        exit;
                    }
                }
                
                // Always redirect to clean homepage URL after login.
                header('Location: ' . BASE_PATH . '/anasayfa');
                exit;
            }
        } elseif (is_array($result) && isset($result['error'])) {
                if ($result['error'] === 'email_not_verified') {
                    $errors[] = 'E-posta adresinizi doğrulamanız gerekiyor. Lütfen e-postanızı kontrol edin. / You need to verify your email address. Please check your email.';
                } elseif ($result['error'] === 'account_deleted') {
                    $errors[] = 'Bu hesap silinmiş. / This account has been deleted.';
                }
            } else {
                $errors[] = 'Wrong username or password. Too many failed attempts will result in temporary lockout.';
            }
        }
    }
}
?>

<style>
    body.login-page {
        background-color: #f9f9f9;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    .login-page .content-container {
        width: auto;
        margin: 0 auto;
    }
    .login-page .content-item {
        width: auto !important;
        float: none !important;
        margin: 0 auto;
    }
    .login-page .content-section {
        display: flex;
        justify-content: center;
        align-items: center;
        padding-top: 0;
    }
    .login-page .login-card {
        max-width: 460px;
        width: 100%;
        text-align: left;
        background: #fff;
        padding: 2rem;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .login-page .main-heading {
        text-align: center;
    }
    .login-page .form-group {
        margin-bottom: 1rem;
        display: flex;
        flex-direction: column;
    }
    .login-page .form-group label {
        margin-bottom: 0.3rem;
        font-weight: 500;
    }
    .login-page .form-group input[type="text"],
    .login-page .form-group input[type="password"] {
        width: 100%;
        padding: 0.45rem 0.6rem;
        box-sizing: border-box;
    }
    .login-page .form-group button {
        display: block;
        margin: 0 auto;
        padding: 0.55rem 1.2rem;
    }
    .login-page .form-links {
        text-align: center;
        margin-top: 1rem;
        font-size: 14px;
    }
    .login-page .form-links p {
        margin: 0.4rem 0;
    }
</style>

<div class="content-section">
    <div class="content-container">
        <div class="content-item login-card">
            <div class="main-heading">
                <h2>Giriş Yap</h2>
            </div>

            <div class="form-section">
                <?php if (!empty($errors)): ?>
                    <div class="form-alert form-alert-error">
                        <?php foreach ($errors as $error): ?>
                            ✗ <?= htmlspecialchars($error) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                    <div class="form-group">
                        <label for="username">Kullanıcı adı</label>
                        <input type="text" id="username" name="username" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Şifre</label>
                        <input type="password" id="password" name="password" required>
                    </div>

                    <?php if (!empty($_GET['reject_cookies'])): ?>
                        <input type="hidden" name="reject_cookies" value="1">
                        <div class="info-note">Bu oturum için çerez kullanmadan siteyi kullanmayı seçtiniz.</div>
                    <?php endif; ?>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">Giriş Yap</button>
                    </div>
                </form>

                <div class="form-links">
                    <p>Hesabın yok mu? <a href="<?= BASE_PATH ?>/kayit">Kayıt ol.</a></p>
                    <p><a href="<?= BASE_PATH ?>/sifremi-unuttum">Şifremi unuttum</a></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

