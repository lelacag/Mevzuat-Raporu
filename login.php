<?php /* EN + TR comments used. */

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

<div class="main-container single-column">
    <main class="content-area form-centered">
        <div class="content-wrapper">
        <h1>Giris Yap</h1>

        <?php if (!empty($errors)): ?>
            <ul class="errors">
                <?php foreach ($errors as $error): ?>
                    <li><?= $error ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <label>Username:</label><br>
            <input type="text" name="username" required size="30"><br>
            <label>Password:</label><br>
            <input type="password" name="password" required size="30"><br>
            <?php if (!empty($_GET['reject_cookies'])): ?>
                <input type="hidden" name="reject_cookies" value="1">
                <div class="info-note">Bu oturum için çerez kullanmadan siteyi kullanmayı seçtiniz.</div>
            <?php endif; ?>
            <button type="submit">Giris Yap</button>
        </form>

        <p>Hesabin yok mu? <a href="<?= BASE_PATH ?>/kayit">Kayit ol</a></p>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

