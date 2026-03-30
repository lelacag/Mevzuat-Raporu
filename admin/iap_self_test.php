<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/google_play.php';
require_once __DIR__ . '/../includes/apple_receipt.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
$current_user = get_user($current_user_id);
require_admin_perm('manage_billing');

$title = 'IAP Self Test';
include __DIR__ . '/_header.php';
include __DIR__ . '/_nav.php';

$results = [];

// Google Play access token test
$token = google_play_get_access_token();
if ($token) {
    $results['google_play'] = ['ok' => true, 'note' => 'access_token_obtained', 'masked_token' => substr($token,0,8) . '...'];
} else {
    $results['google_play'] = ['ok' => false, 'note' => 'could_not_get_access_token'];
}

// Apple test: only test if shared secret set
$apple_shared = getenv('APPLE_SHARED_SECRET') ?: '';
if ($apple_shared) {
    $test_receipt = base64_encode('TEST_SELF');
    $res = apple_verify_receipt($test_receipt);
    $results['apple'] = ['ok' => $res['success'], 'detail' => $res];
} else {
    $results['apple'] = ['ok' => false, 'note' => 'APPLE_SHARED_SECRET_missing'];
}

?>

<div class="admin-panel">
    <h1>IAP Self-Test Results</h1>

    <h3>Google Play</h3>
    <?php if ($results['google_play']['ok']): ?>
        <p class="success">Access token obtainable (masked token: <?= htmlspecialchars($results['google_play']['masked_token']) ?>)</p>
    <?php else: ?>
        <p class="error">Could not obtain Google Play access token. Check `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` and permissions.</p>
    <?php endif; ?>

    <h3>Apple</h3>
    <?php if (!empty($results['apple']['ok'])): ?>
        <p class="success">Receipt verification endpoint reached and returned success.</p>
        <pre><?= htmlspecialchars(json_encode($results['apple']['detail'], JSON_PRETTY_PRINT)) ?></pre>
    <?php else: ?>
        <p class="error">Apple verification not successful: <?= htmlspecialchars($results['apple']['note'] ?? json_encode($results['apple']['detail'])) ?></p>
        <?php if (!empty($results['apple']['detail'])): ?>
            <pre><?= htmlspecialchars(json_encode($results['apple']['detail'], JSON_PRETTY_PRINT)) ?></pre>
        <?php endif; ?>
    <?php endif; ?>

    <p><a href="<?= BASE_PATH ?>/admin/iap_status.php" class="btn">Back to IAP Status</a></p>
</div>
