<?php /* EN + TR comments used. */
/**
 * Forgot Password / Password Recovery Page
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user_id = get_current_user_id();

// If logged in, redirect to index
if ($user_id) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

// Initialize state
$errors = [];
$success = false;
$step = 'email'; // email or reset

// Check if there's a reset token
if (isset($_GET['token'])) {
    $token = trim($_GET['token']);
    $step = 'reset';
    
    // Verify token exists and hasn't expired
    $stmt = query("SELECT id, email FROM users WHERE reset_token = ? AND reset_token_expiry > NOW() LIMIT 1", [$token]);
    $reset_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reset_user) {
        $errors[] = 'Bu şifre sıfırlama bağlantısı geçersiz veya süresi dolmuştur.';
        $step = 'email';
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset_password'])) {
        $token = trim($_POST['reset_token'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($new_password) || empty($confirm_password)) {
            $errors[] = 'Tüm alanlar gereklidir.';
        } elseif (strlen($new_password) < MIN_PASSWORD_LENGTH) {
            $errors[] = 'Şifre en az ' . MIN_PASSWORD_LENGTH . ' karakter uzunluğunda olmalıdır.';
        } elseif ($new_password !== $confirm_password) {
            $errors[] = 'Şifreler eşleşmiyor.';
        } else {
            // Verify token
            $stmt = query("SELECT id FROM users WHERE reset_token = ? AND reset_token_expiry > NOW() LIMIT 1", [$token]);
            $reset_user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($reset_user) {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                query("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?", 
                    [$password_hash, $reset_user['id']]);
                
                $success = true;
                $step = 'email';
            } else {
                $errors[] = 'Bu şifre sıfırlama bağlantısı geçersiz veya süresi dolmuştur.';
            }
        }
    } elseif (isset($_POST['request_reset'])) {
        // Handle password recovery request
        $email_or_username = trim($_POST['email_or_username'] ?? '');
        
        if (empty($email_or_username)) {
            $errors[] = 'E-posta veya kullanıcı adı gereklidir.';
        } else {
            // Find user
            $stmt = query("SELECT id, email, username FROM users WHERE (email = ? OR username = ?) AND deleted_at IS NULL LIMIT 1", 
                [$email_or_username, $email_or_username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Generate reset token
                $reset_token = bin2hex(random_bytes(32));

                // Update user with reset token (use DB time to avoid timezone mismatch)
                query("UPDATE users SET reset_token = ?, reset_token_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?", 
                    [$reset_token, $user['id']]);

                // Send reset email (use canonical helper)    
                $reset_url = full_url(password_reset_url($reset_token));
                
                $subject = 'Şifre Sıfırlama - ' . SITE_NAME;
                $message = "Merhaba " . $user['username'] . ",\n\n";
                $message .= "Şifrenizi sıfırlamak için aşağıdaki bağlantıya tıklayın: " . $reset_url . "\n\n";
                $message .= "Bu bağlantı 1 saat geçerlidir. Eğer bu isteği siz yapmadıysanız, bu e-postayı görmezden gelebilirsiniz.\n\n";
                $message .= "İyi günler!";
                
                if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
                    send_email($user['email'], $subject, $message);
                }
                
                $success = true;
            } else {
                // Don't reveal if email exists (security)
                $success = true;
            }
        }
    }
}

$extra_head = "\n    <link rel=\"stylesheet\" href=\"/assets/landing.css\">";
$extra_body_classes = ['forgot-page'];

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body.forgot-page {
        background-color: #f9f9f9;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    .forgot-page .content-container {
        width: auto;
        margin: 0 auto;
    }
    .forgot-page .content-item {
        width: auto !important;
        float: none !important;
        margin: 0 auto;
    }
    .forgot-page .content-section {
        display: flex;
        justify-content: center;
        align-items: center;
        padding-top: 0;
    }
    .forgot-page .forgot-card {
        max-width: 460px;
        width: 100%;
        text-align: left;
        background: #fff;
        padding: 2rem;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .forgot-page .main-heading {
        text-align: center;
    }
    .forgot-page .form-group {
        margin-bottom: 1rem;
        display: flex;
        flex-direction: column;
    }
    .forgot-page .form-group label {
        margin-bottom: 0.3rem;
        font-weight: 500;
    }
    .forgot-page .form-group input[type="text"],
    .forgot-page .form-group input[type="password"] {
        width: 100%;
        padding: 0.45rem 0.6rem;
        box-sizing: border-box;
    }
    .forgot-page .form-group button,
    .forgot-page button[type="submit"] {
        display: block;
        margin: 0 auto;
        padding: 0.55rem 1.2rem;
    }
    .forgot-page .back-link {
        text-align: center;
        margin-top: 1.2rem;
        font-size: 14px;
    }
</style>

<div class="content-section">
    <div class="content-container">
        <div class="content-item forgot-card">
            <div class="main-heading">
                <h2><?= SITE_NAME ?></h2>
            </div>

            <div class="form-section">
                <?php if ($success && $step === 'email' && !isset($_GET['token'])): ?>
                    <div class="form-alert form-alert-success">
                        ✓ Şifre sıfırlama bağlantısı e-posta adresine gönderilmiştir. Lütfen e-postanızı kontrol edin.
                    </div>
                <?php elseif ($success && $step === 'email' && isset($_GET['token'])): ?>
                    <div class="form-alert form-alert-success">
                        ✓ Şifreniz başarıyla sıfırlanmıştır. Şimdi yeni şifrenizle giriş yapabilirsiniz.
                    </div>
                <?php elseif ($step === 'reset' && !empty($reset_user)): ?>
                    <!-- Password Reset Form -->
                    <h3 style="text-align:center;margin-bottom:1rem;">Yeni Şifre Belirle</h3>

                    <?php if (!empty($errors)): ?>
                        <div class="form-alert form-alert-error">
                            <?php foreach ($errors as $error): ?>
                                ✗ <?= htmlspecialchars($error) ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="reset_password" value="1">
                        <input type="hidden" name="reset_token" value="<?= htmlspecialchars($token) ?>">

                        <div class="form-group">
                            <label for="new-password">Yeni Şifre</label>
                            <input type="password" name="new_password" id="new-password" placeholder="Yeni şifrenizi girin" required>
                        </div>

                        <div class="form-group">
                            <label for="confirm-password">Şifre Tekrar</label>
                            <input type="password" name="confirm_password" id="confirm-password" placeholder="Şifrenizi tekrar girin" required>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Şifre Sıfırla</button>
                        </div>
                    </form>
                <?php else: ?>
                    <!-- Password Recovery Form -->
                    <p style="margin-bottom:1rem;">Hesabınıza ilişkili e-posta adresini veya kullanıcı adını girin. Şifre sıfırlama bağlantısı e-posta adresine gönderilecektir.</p>

                    <?php if (!empty($errors)): ?>
                        <div class="form-alert form-alert-error">
                            <?php foreach ($errors as $error): ?>
                                ✗ <?= htmlspecialchars($error) ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="request_reset" value="1">

                        <div class="form-group">
                            <label for="email-or-username">E-posta veya Kullanıcı Adı</label>
                            <input type="text" name="email_or_username" id="email-or-username" placeholder="E-posta veya kullanıcı adınız" required>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Şifre Sıfırlama Bağlantısı Gönder</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="back-link">
                    <a href="<?= BASE_PATH ?>/giris">← Giriş Yap</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
