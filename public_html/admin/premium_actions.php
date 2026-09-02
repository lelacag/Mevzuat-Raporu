<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stripe.php';

if (!is_admin()) {
    $_SESSION['flash_error'] = 'Admin access required';
    header('Location: ' . BASE_PATH . '/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = 'Invalid request';
    header('Location: ' . BASE_PATH . '/admin/premium_users.php#subscriptions');
    exit;
}

if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    $_SESSION['flash_error'] = 'Invalid CSRF token';
    header('Location: ' . BASE_PATH . '/admin/premium_users.php#subscriptions');
    exit;
}

$action = $_POST['action'] ?? '';
$id = intval($_POST['id'] ?? 0);

if (!$id || !in_array($action, ['activate', 'cancel'])) {
    $_SESSION['flash_error'] = 'Missing or invalid parameters';
    header('Location: ' . BASE_PATH . '/admin/premium_users.php#subscriptions');
    exit;
}

$db = db_connect();

try {
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM premium_subscriptions WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        throw new Exception('Subscription not found');
    }

    if ($action === 'activate') {
        $start = date('Y-m-d H:i:s');
        $end = null;
        // set end depending on plan_type for simple admin activation
        if ($sub['plan_type'] === 'monthly') {
            $end = date('Y-m-d H:i:s', strtotime('+1 month'));
        } elseif ($sub['plan_type'] === 'yearly') {
            $end = date('Y-m-d H:i:s', strtotime('+1 year'));
        } elseif ($sub['plan_type'] === 'lifetime') {
            $end = null;
        }

        $stmt = $db->prepare("UPDATE premium_subscriptions SET status = 'active', start_date = ?, end_date = ? WHERE id = ?");
        $stmt->execute([$start, $end, $id]);

        // Promote user
        $stmt = $db->prepare("UPDATE users SET role = 'member', is_approved = 1 WHERE id = ?");
        $stmt->execute([$sub['user_id']]);

        $_SESSION['flash'] = 'Subscription activated and user promoted.';
    } elseif ($action === 'cancel') {
        // attempt to cancel at Stripe first if we have a subscription id
        if (!empty($sub['stripe_subscription_id']) && is_stripe_configured()) {
            try {
                $client = get_stripe_client();
                $client->subscriptions->cancel($sub['stripe_subscription_id'], []);
            } catch (Exception $e) {
                // log but continue to update local record
                error_log('admin cancel stripe error: ' . $e->getMessage());
            }
        }

        $end = date('Y-m-d H:i:s');
        $stmt = $db->prepare("UPDATE premium_subscriptions SET status = 'cancelled', end_date = ? WHERE id = ?");
        $stmt->execute([$end, $id]);

        // Optionally demote user (we'll leave role as-is but clear premium flags)
        $stmt = $db->prepare("UPDATE users SET is_premium = 0, premium_until = NULL WHERE id = ?");
        $stmt->execute([$sub['user_id']]);

        $_SESSION['flash'] = 'Subscription cancelled.';
    }

    $db->commit();
    header('Location: ' . BASE_PATH . '/admin/premium_users.php#subscriptions');
    exit;
} catch (Exception $e) {
    $db->rollBack();
    error_log('admin/premium_actions error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'An error occurred: ' . $e->getMessage();
    header('Location: ' . BASE_PATH . '/admin/premium_users.php#subscriptions');
    exit;
}
