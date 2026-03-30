<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Only allow in non-production or admin users
if (defined('ENVIRONMENT') && ENVIRONMENT === 'production' && !is_admin()) {
    http_response_code(404);
    echo "Not found";
    exit;
}

$sid = trim($_REQUEST['sid'] ?? '');
$sid = preg_replace('/[^A-Za-z0-9._-]/', '', $sid);
if ($sid === '') {
    $_SESSION['flash_error'] = 'Sid eksik.';
    header('Location: ' . BASE_PATH . '/events.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        ensure_url_sessions_table();
        $sid_hash = _url_token_hash($sid);
        $raw_hash = hash('sha256', $sid);
        $stmt = query("SELECT token_hash, raw_token_hash FROM url_sessions WHERE token_hash = ? OR raw_token_hash = ? LIMIT 1", [$sid_hash, $raw_hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Try prefix match
            $pref = substr($sid, 0, 12);
            $stmt2 = query("SELECT token_hash FROM url_sessions WHERE token_hash LIKE CONCAT(?, '%') LIMIT 1", [$pref]);
            $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($row2) $row = $row2;
        }
        if (!$row) {
            $_SESSION['flash_error'] = 'Token bulunamadı.';
            header('Location: ' . BASE_PATH . '/events.php');
            exit;
        }
        query("UPDATE url_sessions SET revoked_at = NOW() WHERE token_hash = ? OR raw_token_hash = ?", [$row['token_hash'] ?? '', $row['raw_token_hash'] ?? '']);
        $_SESSION['flash'] = 'Token iptal edildi.';
        header('Location: ' . BASE_PATH . '/events.php');
        exit;
    } catch (Exception $e) {
        error_log('admin/revoke_url_session error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'İptal sırasında hata oluştu.';
        header('Location: ' . BASE_PATH . '/events.php');
        exit;
    }
}

// GET: show confirmation
?>
<div class="main-container">
    <main class="content-area narrow">
        <div class="card-box padded">
            <h1>Token İptal Onayı</h1>
            <p>Bu işlem geri alınamaz. Token: <code><?= htmlspecialchars($sid) ?></code></p>
            <form method="POST">
                <input type="hidden" name="sid" value="<?= htmlspecialchars($sid) ?>">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div style="margin-top:12px;">
                    <button type="submit" class="btn-post">Evet, iptal et</button>
                    <a href="<?= BASE_PATH ?>/events.php" class="btn-cancel">İptal</a>
                </div>
            </form>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php';
