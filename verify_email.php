<?php
require_once __DIR__ . '/includes/header.php';

$message = '';
$error = '';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = 'Geçersiz doğrulama bağlantısı.';
} else {
    // Find user with this token
    $stmt = query("SELECT id, username, email, verification_token_expiry FROM users WHERE verification_token = ? AND email_verified = 0", [$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $error = 'Doğrulama bağlantısı geçersiz veya zaten kullanılmış.';
    } elseif ($user['verification_token_expiry'] && strtotime($user['verification_token_expiry']) < time()) {
        $error = 'Doğrulama bağlantısının süresi dolmuş. Lütfen yeni bir doğrulama e-postası isteyin.';
    } else {
        // Verify the email
        query("UPDATE users SET email_verified = 1, verification_token = NULL, verification_token_expiry = NULL WHERE id = ?", [$user['id']]);
        $message = 'E-posta adresiniz başarıyla doğrulandı! Artık giriş yapabilirsiniz.';
    }
}
?>

<div class="main-container single-column">
    <main class="content-area" style="text-align: center;">
        <h1 class="section-title">E-posta Doğrulama</h1>
        
        <?php if ($message): ?>
            <div style="padding: 20px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; margin: 20px 0; color: #155724;">
                <p style="margin: 0; font-size: 16px;"><?= htmlspecialchars($message) ?></p>
            </div>
            <a href="<?= BASE_PATH ?>/giris" class="btn btn-primary" style="display: inline-block; padding: 12px 24px; background: #27ae60; color: white; text-decoration: none; border-radius: 4px; font-weight: 600; margin-top: 20px;">
                Giriş Yap
            </a>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div style="padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; margin: 20px 0; color: #721c24;">
                <p style="margin: 0; font-size: 16px;"><?= htmlspecialchars($error) ?></p>
            </div>
            <a href="<?= BASE_PATH ?>/kayit" class="btn" style="display: inline-block; padding: 12px 24px; background: #f5f5f5; color: #333; text-decoration: none; border-radius: 4px; border: 1px solid #ddd; font-weight: 600; margin-top: 20px;">
                Kayıt Sayfasına Dön
            </a>
        <?php endif; ?>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
