<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$token = $_GET['token'] ?? '';
$message = '';
$error = '';
if (empty($token)) {
    $error = 'Geçersiz veya eksik token.';
} else {
    $res = verify_signup_request($token);
    if ($res['success']) {
        $message = 'E-posta doğrulandı. Talebiniz kaydedildi.';
        // Run auto-open check immediately
        auto_open_countries_check();
    } else {
        $error = 'Doğrulama başarısız: ' . ($res['error'] ?? 'bilinmeyen hata');
    }
}
?>
<div class="main-container single-column">
    <main class="content-area">
        <h1 class="section-title">Doğrulama</h1>

        <?php if (!empty($message)): ?>
            <div class="form-alert form-alert-success"><?= htmlspecialchars($message) ?></div>
        <?php else: ?>
            <div class="form-alert form-alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <p><a href="<?= BASE_PATH ?>/">Ana sayfaya dön</a></p>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>