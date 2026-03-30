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
    $token = sanitize_input($_GET['token']);
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
        $token = sanitize_input($_POST['reset_token'] ?? '');
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
        $email_or_username = sanitize_input($_POST['email_or_username'] ?? '');
        
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
                $message = "Merhaba " . htmlspecialchars($user['username']) . ",\n\n";
                $message .= "Şifrenizi sıfırlamak için aşağıdaki bağlantıya tıklayın:\n\n";
                $message .= $reset_url . "\n\n";
                $message .= "Bu bağlantı 1 saat geçerlidir.\n\n";
                $message .= "Eğer bu isteği siz yapmadıysanız, bu e-postayı görmezden gelebilirsiniz.\n\n";
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

require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Make the password reset form wider and centered */
    .forgot-password-container {
        width: 100%;
        max-width: 900px;
        min-width: 520px;
        margin: 0 auto;
    }

    /* Ensure form fields use full width inside the reset card */
    .forgot-password-container .form-group input {
        width: 100%;
        box-sizing: border-box;
    }
</style>

<div class="main-container single-column">
    <main class="content-area" style="max-width:720px;margin:40px auto;">
        <div class="forgot-password-container">
            <h1><?= SITE_NAME ?></h1>

            <?php if ($success && $step === 'email' && !isset($_GET['token'])): ?>
                <div class="alert alert-success">
                    Şifre sıfırlama bağlantısı e-posta adresine gönderilmiştir. Lütfen e-postanızı kontrol edin.
                </div>
            <?php elseif ($success && $step === 'email' && isset($_GET['token'])): ?>
                <div class="alert alert-success">
                    Şifreniz başarıyla sıfırlanmıştır. Şimdi yeni şifrenizle giriş yapabilirsiniz.
                </div>
            <?php elseif ($step === 'reset' && !empty($reset_user)): ?>
                <!-- Password Reset Form -->
                <h2 style="font-size: 20px; margin-bottom: 20px;">Yeni Şifre Belirle</h2>

                <?php if (!empty($errors)): ?>
                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
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

                    <button type="submit" class="submit-btn">Şifre Sıfırla</button>
                </form>
            <?php else: ?>
                <!-- Password Recovery Form -->
                <p>Hesabınıza ilişkili e-posta adresini veya kullanıcı adını girin. Şifre sıfırlama bağlantısı e-posta adresine gönderilecektir.</p>

                <?php if (!empty($errors)): ?>
                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="request_reset" value="1">

                    <div class="form-group">
                        <label for="email-or-username">E-posta veya Kullanıcı Adı</label>
                        <input type="text" name="email_or_username" id="email-or-username" placeholder="E-posta veya kullanıcı adınız" required>
                    </div>

                    <button type="submit" class="submit-btn">Şifre Sıfırlama Bağlantısı Gönder</button>
                </form>
            <?php endif; ?>

            <div class="back-link">
                <a href="<?= BASE_PATH ?>/landing.php">← Giriş sayfasına dön</a>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
