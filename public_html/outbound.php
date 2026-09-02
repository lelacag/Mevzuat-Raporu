<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Outbound link confirmation to protect users from accidental navigation
$raw = $_REQUEST['u'] ?? '';
$url = trim($raw);

// Basic validation
function valid_outbound_url($u) {
    if (!$u) return false;
    // Must be http(s)
    if (!preg_match('#^https?://#i', $u)) return false;
    // Disallow javascript:, data:, or other schemes
    if (preg_match('#^\s*javascript:|^\s*data:#i', $u)) return false;
    // Limit length
    if (strlen($u) > 2048) return false;
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === '1' && isset($_POST['u'])) {
    $dest = $_POST['u'];
    if (!valid_outbound_url($dest)) {
        $_SESSION['error'] = 'Geçersiz bağlantı.';
        header('Location: ' . home_url());
        exit;
    }
    // Outbound redirect is a simple navigation confirmation, not a state-changing action.
    error_log('Outbound redirect: user=' . (get_current_user_id() ?: 'anon') . ' dest=' . $dest);
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $dest, true, 303);
    exit;
}

if (!valid_outbound_url($url)) {
    $_SESSION['error'] = 'Geçersiz bağlantı.';
    header('Location: ' . home_url());
    exit;
}

$page_title = 'Dış Bağlantı Onayı';
// Note: header already included at the top via includes/header.php
// (avoid trying to include non-existent templates/header.php)
?>
<div class="outbound-wrap">
    <div></div>
    <div class="outbound-box">
        <h2>Dış Bağlantıya Gidiyorsunuz</h2>
        <p class="text-muted">Bu bağlantı site dışına çıkıyor:</p>
        <p class="outbound-url"><strong><?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?></strong></p>
        <p style="color:#666;">Bu adresin güvenliğinden emin değilseniz devam etmeyin. Bağlantıya gitmek istiyorsanız "Devam" butonuna basın.</p>
        <div class="outbound-actions">
            <!-- Use a plain anchor for navigation to avoid form/POST focus quirks on some macOS browsers -->
            <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" class="btn-post" rel="noreferrer noopener">Devam</a>
            <a href="<?= BASE_PATH ?>/index.php" class="btn-outline">İptal</a>
        </div>
    </div>
    <div></div>
</div>
<?php require_once __DIR__ . '/includes/footer.php';
