<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
session_start();

$current_user_id = get_current_user_id();
if ($current_user_id) {
    $user = get_user($current_user_id);
    
    if (!empty($user['birthday'])) {
        $birth_date = new DateTime($user['birthday']);
        $today = new DateTime();
        $age = $today->diff($birth_date)->y;
        
        // Calculate when they turn 16
        $sixteenth_birthday = clone $birth_date;
        $sixteenth_birthday->modify('+16 years');
        $days_until_16 = $today->diff($sixteenth_birthday)->days;
    }
}

require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yaş Kısıtlaması - <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/style.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/underage.css">
</head>
<body>
<div class="auth-container">
    <div class="auth-box" style="text-align: center;">
        <h1 class="underage-emoji">🚫</h1>
        <h2 class="auth-title">Yaş Kısıtlaması</h2>
        
        <div class="underage-warning">
            <p><strong>Bu siteyi kullanmak için en az 16 yaşında olmalısınız.</strong></p>
        </div>

        <?php if (isset($age) && isset($days_until_16)): ?>
            <p class="underage-age-info">
                Şu anki yaşınız: <strong><?= $age ?></strong><br>
                16 yaşına girmek için kalan gün sayısı: <strong><?= $days_until_16 ?></strong>
            </p>
            <p class="underage-age-info">
                16 yaşına geldiğinizde (<?= $sixteenth_birthday->format('d.m.Y') ?>) tekrar giriş yapabilirsiniz.
            </p>
        <?php endif; ?>

        <div class="underage-logout">
            <a href="<?= BASE_PATH ?>/cikis" class="auth-btn" style="display: inline-block; text-decoration: none;">
                Çıkış Yap
            </a>
        </div>
    </div>
</div>
</body>
</html>
