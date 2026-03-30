<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$user = get_user($current_user_id);
if (!$user) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

$step = 1;
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = 'Invalid CSRF token';
    } elseif (isset($_POST['confirm_delete'])) {
        // Step 1: User confirmed deletion, send email token
        if (!empty($user['email'])) {
            // Generate deletion token
            $deletion_token = bin2hex(random_bytes(32));
            $token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Store in session
            $_SESSION['deletion_token'] = $deletion_token;
            $_SESSION['deletion_token_expiry'] = $token_expiry;
            
            // Send email
            $subject = 'Hesap Silme Onayı - ' . SITE_NAME;
            $message = "Merhaba " . htmlspecialchars($user['username']) . ",\n\n";
            $message .= "Hesabınızı silmek için aşağıdaki onay kodunu kullanın:\n\n";
            $message .= "Onay Kodu: " . $deletion_token . "\n\n";
            $message .= "Bu kod 1 saat geçerlidir.\n\n";
            $message .= "Bu işlemi siz yapmadıysanız, bu e-postayı görmezden gelebilirsiniz.\n\n";
            $message .= "İyi günler!";
            
            if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
                send_email($user['email'], $subject, $message);
            }
            
            $step = 2;
        } else {
            // No email, delete immediately
            query("UPDATE users SET deleted_at = NOW() WHERE id = ?", [$current_user_id]);
            logout();
            $success = true;
        }
    } elseif (isset($_POST['verify_token'])) {
        // Step 2: Verify token and delete account
        $token = trim($_POST['token'] ?? '');
        
        if (empty($token)) {
            $errors[] = 'Lütfen onay kodunu girin';
        } elseif (!isset($_SESSION['deletion_token']) || !isset($_SESSION['deletion_token_expiry'])) {
            $errors[] = 'Oturum süresi doldu. Lütfen tekrar deneyin.';
        } elseif (strtotime($_SESSION['deletion_token_expiry']) < time()) {
            $errors[] = 'Onay kodu süresi dolmuş. Lütfen tekrar başlayın.';
            unset($_SESSION['deletion_token']);
            unset($_SESSION['deletion_token_expiry']);
        } elseif ($token !== $_SESSION['deletion_token']) {
            $errors[] = 'Geçersiz onay kodu';
        } else {
            // Valid token, delete account
            query("UPDATE users SET deleted_at = NOW() WHERE id = ?", [$current_user_id]);
            unset($_SESSION['deletion_token']);
            unset($_SESSION['deletion_token_expiry']);
            logout();
            $success = true;
        }
        
        if (!empty($errors)) {
            $step = 2;
        }
    }
}

// If already in step 2 (email sent)
if (isset($_SESSION['deletion_token']) && !$success) {
    $step = 2;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="main-container single-column">
    <main class="content-area padded">
        
        <?php if ($success): ?>
            <!-- Success: Account Deleted -->
            <div class="text-center padded">
                <h1 class="text-success mb-20">✓ Hesabınız Silindi</h1>
                <div class="alert alert-success">
                    <p style="margin: 0;">
                        Hesabınız başarıyla silindi. Tüm verileriniz kalıcı olarak kaldırılmıştır.
                        <br><br>
                        Platformumuzu kullandığınız için teşekkür ederiz.
                    </p>
                </div>
                <a href="<?= BASE_PATH ?>/index.php" class="btn btn-success mt-12">Ana Sayfaya Dön</a>
            </div>
            
        <?php elseif ($step === 1): ?>
            <!-- Step 1: Confirmation -->
            <div class="text-center">
                <h1 class="danger-title mb-20">⚠️ Hesabı Silmeyi Onayla</h1>
                
                <div class="alert alert-warning text-left">
                    <h3 style="margin: 0 0 15px 0;">Bu İşlem Geri Alınamaz!</h3>
                    <p style="margin: 0 0 10px 0;">
                        Hesabınızı sildiğinizde:
                    </p>
                    <ul style="margin: 10px 0; padding-left: 20px;">
                        <li>Tüm gönderileriniz silinecek</li>
                        <li>Takipçi ve takip ettikleriniz listesi kaldırılacak</li>
                        <li>Profil bilgileriniz kalıcı olarak silinecek</li>
                        <li>Beğenileriniz ve yanıtlarınız kaldırılacak</li>
                        <li>Premium üyeliğiniz (varsa) iptal edilecek</li>
                    </ul>
                    <p class="text-danger font-weight-600">
                        Devam etmek istediğinizden emin misiniz?
                    </p>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div style="padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 20px;">
                        <?php foreach ($errors as $error): ?>
                            <p style="margin: 5px 0; color: #721c24;"><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="mt-12">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="confirm_delete" value="1">

                    <div class="flex-row justify-center">
                        <button type="submit" class="btn btn-danger btn-lg">Evet, Hesabımı Sil</button>
                        <a href="<?= BASE_PATH ?>/profile_edit.php" class="btn btn-secondary">İptal</a>
                    </div>
                </form>
            </div>
            
        <?php elseif ($step === 2): ?>
            <!-- Step 2: Email Verification -->
            <div style="text-align: center;">
                <h1 style="color: #f39c12; margin-bottom: 20px;">📧 E-posta Doğrulama</h1>
                
                <div class="alert alert-info">
                    <p style="margin:0 0 10px 0;"><strong><?= htmlspecialchars($user['email']) ?></strong> adresine bir onay kodu gönderdik.</p>
                    <p class="muted" style="margin:0;">E-postanızı kontrol edin ve aşağıdaki alana onay kodunu girin. Kodu görmüyorsanız spam klasörünü kontrol edin.</p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <p class="muted" style="margin:5px 0;"><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="mt-12">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="verify_token" value="1">

                    <div style="margin-bottom: 20px;">
                        <label class="form-label">Onay Kodu:</label>
                        <input type="text" name="token" required placeholder="Onay kodunu buraya girin" class="input-token">
                    </div>

                    <div class="flex-row justify-center mt-12">
                        <button type="submit" class="btn btn-danger btn-lg">Onayla ve Sil</button>
                        <a href="<?= BASE_PATH ?>/profile_edit.php" class="btn btn-secondary">İptal</a>
                    </div>
                </form>
                
                <p style="margin-top: 30px; color: #666; font-size: 13px;">
                    Kod 1 saat içinde geçerlidir. Kodu almadıysanız sayfayı yenileyip tekrar deneyin.
                </p>
            </div>
            
        <?php endif; ?>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
