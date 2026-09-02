<?php
// Confirmation page for one-time token: renders a no-JS form to POST the token to one_time_landing.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$one = $_GET['one'] ?? '';
$one = preg_replace('/[^A-Fa-f0-9]/', '', $one);
if (empty($one)) {
    // Invalid or missing token, redirect back to landing with error
    session_start();
    $_SESSION['login_error'] = 'Geçersiz veya eksik token.';
    header('Location: ' . BASE_PATH . '/landing.php');
    exit;
}

$masked = substr($one,0,6) . '...' . substr($one,-6);
// Check DB for token row (non-destructive) and log masked status
$token_status = 'unknown';
$expires_at = null;
try {
    require_once __DIR__ . '/includes/db.php';
    $pdo = db_connect();
    $th = hash_hmac('sha256', $one, URL_SESSION_SECRET);
    $sh = hash('sha256', $one);
    $stmt = $pdo->prepare("SELECT user_id, expires_at, created_at FROM url_one_time_tokens WHERE token_hash = ? OR raw_token_hash = ? LIMIT 1");
    $stmt->execute([$th, $sh]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $token_status = 'valid';
        $expires_at = $row['expires_at'];
        error_log('one_time_confirm: token row exists user_id=' . $row['user_id'] . ' masked=' . $masked);
    } else {
        $token_status = 'not_found_or_expired';
        error_log('one_time_confirm: token row not found for masked=' . $masked);
    }
} catch (Exception $e) {
    error_log('one_time_confirm: DB check error: ' . $e->getMessage());
    $token_status = 'db_error';
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Continue to site</title>

</head>
<body class="one-time-confirm">
    <div class="box">
        <h2>Devam etmek için onaylayın</h2>
        <p>Giriş başarılı. Devam etmek için "Devam" butonuna basın. (Sayfa JavaScript gerektirmez.)</p>
        <form method="POST" action="<?= BASE_PATH ?>/one_time_landing.php">
            <input type="hidden" name="one_time" value="<?= htmlspecialchars($one, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">Devam (POST)</button>
        </form>
        <p class="mt-12">Alternatif: <a href="<?= BASE_PATH ?>/one_time_consume.php?one=<?= rawurlencode($one) ?>">Devam et (no-JS magic link)</a></p>
        <p class="muted small mt-12">Token (masked): <?= htmlspecialchars($masked) ?></p>
        <p class="muted small">Durum: <?= htmlspecialchars($token_status) ?><?php if ($expires_at): ?> — Süre sonu: <?= htmlspecialchars($expires_at) ?><?php endif; ?></p>
        <!-- dev diagnostics removed -->
    </div>
</body>
</html>
