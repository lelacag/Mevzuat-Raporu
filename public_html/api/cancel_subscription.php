<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stripe.php';

$user_id = get_current_user_id();
if (!$user_id) {
    $_SESSION['flash_error'] = 'Lütfen giriş yapın.';
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/premium.php');
    exit;
}

require_csrf();

$immediate = !empty($_POST['immediate']); // optional flag to cancel immediately
$db = db_connect();

try {
    // Find active subscription for this user
    $stmt = $db->prepare("SELECT * FROM premium_subscriptions WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        // Nothing to cancel; demote user just in case their premium flag still set
        $db->beginTransaction();
        query("UPDATE users SET is_premium = 0, premium_until = NULL WHERE id = ?", [$user_id]);
        $db->commit();

        $_SESSION['flash'] = 'Aboneliğiniz bulunamadı veya zaten iptal edilmiş.';
        header('Location: ' . BASE_PATH . '/premium.php');
        exit;
    }

    $stripe_sub_id = $sub['stripe_subscription_id'] ?? null;

    if ($stripe_sub_id && is_stripe_configured()) {
        $client = get_stripe_client();
        if ($client) {
            if ($immediate) {
                // immediate cancel
                $res = $client->subscriptions->cancel($stripe_sub_id, []);
                $new_status = 'cancelled';
                $end_date = isset($res->status) ? null : null;
            } else {
                // cancel at period end
                $res = $client->subscriptions->update($stripe_sub_id, ['cancel_at_period_end' => true]);
                $new_status = 'cancel_pending';
                $end_date = isset($res->current_period_end) ? date('Y-m-d H:i:s', $res->current_period_end) : null;
            }

            // Persist status
            $db->beginTransaction();
            $stmt2 = $db->prepare("UPDATE premium_subscriptions SET status = ?, end_date = ? WHERE id = ?");
            $stmt2->execute([$new_status, $end_date, $sub['id']]);

            // If immediate, demote user now
            if ($new_status === 'cancelled') {
                query("UPDATE users SET is_premium = 0, premium_until = NULL WHERE id = ?", [$user_id]);
            }
            $db->commit();

            $_SESSION['flash'] = $immediate ? 'Aboneliğiniz iptal edildi.' : 'Abonelik iptali talebi alındı; aboneliğiniz dönem sonunda sona erecek.';
            header('Location: ' . BASE_PATH . '/premium.php');
            exit;
        }
    }

    // If no stripe subscription or stripe not configured, just mark cancelled locally
    $db->beginTransaction();
    $stmt3 = $db->prepare("UPDATE premium_subscriptions SET status = 'cancelled' WHERE id = ?");
    $stmt3->execute([$sub['id']]);
    query("UPDATE users SET is_premium = 0, premium_until = NULL WHERE id = ?", [$user_id]);
    $db->commit();

    $_SESSION['flash'] = 'Aboneliğiniz iptal edildi.';
    header('Location: ' . BASE_PATH . '/premium.php');
    exit;

} catch (Exception $e) {
    if ($db && $db->inTransaction()) $db->rollBack();
    error_log('cancel_subscription error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Abonelik iptalinde hata oluştu. Lütfen tekrar deneyin.';
    header('Location: ' . BASE_PATH . '/premium.php');
    exit;
}
