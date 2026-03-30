<?php /* EN + TR comments used. */
include __DIR__ . '/_header.php';
require_once __DIR__ . '/../includes/functions.php';

if (!is_admin()) {
    header('Location: ' . BASE_PATH . '/admin/index.php');
    exit;
}

// Handle revoke (mark revoked)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['revoke']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $token_hash = $_POST['token_hash'] ?? '';
    $token_hash = preg_replace('/[^a-f0-9]/i', '', $token_hash);
    if ($token_hash) {
        try {
            query('UPDATE url_sessions SET revoked_at = NOW() WHERE token_hash = ?', [$token_hash]);
            $message = 'Session revoked (marked).';
            error_log('Admin marked url_session revoked: ' . $token_hash);
        } catch (Exception $e) {
            $message = 'Error revoking session.';
            error_log('Admin revoke error: ' . $e->getMessage());
        }
    }
}
// Handle revoke all (mark all revoked)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['revoke_all']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    try {
        $updated = db_connect()->exec('UPDATE url_sessions SET revoked_at = NOW()');
        $message = 'All URL sessions marked revoked. Rows updated: ' . intval($updated);
        error_log('Admin marked all URL sessions revoked, rows updated: ' . intval($updated));
    } catch (Exception $e) {
        $message = 'Error revoking all sessions.';
        error_log('Revoke all URL sessions failed: ' . $e->getMessage());
    }
}

// Handle create demo token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['create_demo']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $create_user = intval($_POST['create_user'] ?? 1);
    $create_ttl = intval($_POST['create_ttl'] ?? 86400);
    if ($create_user > 0) {
        $newtok = create_url_session($create_user, $create_ttl, true);
        if ($newtok) {
            $message = 'Demo token created for user ' . $create_user . ': ' . substr($newtok,0,6) . '...' . substr($newtok,-6);
            // show raw token in session for one-time display
            $_SESSION['last_demo_token'] = $newtok;
            error_log('Admin created demo token for user ' . $create_user . ' token_prefix=' . substr($newtok,0,6));
        } else {
            $message = 'Failed to create demo token.';
        }
    }
}
$stmt = null;
// Ensure url_sessions table exists before selecting (avoid errors on fresh DB)
try {
    if (function_exists('ensure_url_sessions_table')) {
        ensure_url_sessions_table();
    } else {
        query("CREATE TABLE IF NOT EXISTS url_sessions (
            token_hash VARCHAR(64) PRIMARY KEY,
            raw_token_hash VARCHAR(64) DEFAULT '',
            user_id INT NOT NULL,
            ua_hash VARCHAR(64) DEFAULT '',
            ip_prefix VARCHAR(64) DEFAULT '',
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) {
    error_log('Could not ensure url_sessions table: ' . $e->getMessage());
}

$colExistsStmt = query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'url_sessions' AND COLUMN_NAME = 'raw_token_hash'", [DB_NAME]);
$colExists = (int)$colExistsStmt->fetch(PDO::FETCH_ASSOC)['c'];
// Determine if grants_premium and revoked_at exist
$colGPStmt = query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'url_sessions' AND COLUMN_NAME = 'grants_premium'", [DB_NAME]);
$colGP = (int)$colGPStmt->fetch(PDO::FETCH_ASSOC)['c'];
$colRevStmt = query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'url_sessions' AND COLUMN_NAME = 'revoked_at'", [DB_NAME]);
$colRev = (int)$colRevStmt->fetch(PDO::FETCH_ASSOC)['c'];

if ($colExists) {
    $select = 'SELECT us.token_hash, us.raw_token_hash, us.user_id, us.ua_hash, us.ip_prefix, us.expires_at, us.created_at, u.username';
    if ($colGP) $select .= ', us.grants_premium';
    if ($colRev) $select .= ', us.revoked_at';
    $select .= ' FROM url_sessions us LEFT JOIN users u ON us.user_id = u.id ORDER BY us.created_at DESC';
    $stmt = query($select);
} else {
    $stmt = query('SELECT us.token_hash, us.user_id, us.ua_hash, us.ip_prefix, us.expires_at, us.created_at, u.username FROM url_sessions us LEFT JOIN users u ON us.user_id = u.id ORDER BY us.created_at DESC');
}
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="admin-page">
    <?php include __DIR__ . '/_nav.php'; ?>
    <main class="admin-main">
        <h1>URL Sessions</h1>
        <div class="admin-row">
            <form method="POST" class="form-inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" name="revoke_all" class="btn btn-danger">Revoke All</button>
            </form>

            <form method="POST" class="form-inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <label for="create_user">Create demo token for user:</label>
                <input type="number" id="create_user" name="create_user" value="1" class="small-input">
                <label for="create_ttl">TTL (s):</label>
                <input type="number" id="create_ttl" name="create_ttl" value="86400" class="medium-input">
                <button type="submit" name="create_demo" class="btn btn-primary">Create Demo Token</button>
            </form>
        </div>
        <?php if (!empty($message)): ?>
            <div class="notice success"><?= htmlspecialchars($message) ?></div>
            <?php if (!empty($_SESSION['last_demo_token'])): ?>
                <div class="notice notice-inline">Last demo token (raw, copy now): <code><?= htmlspecialchars($_SESSION['last_demo_token']) ?></code></div>
                <?php unset($_SESSION['last_demo_token']); ?>
            <?php endif; ?>
        <?php endif; ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Created</th>
                    <th>Expires</th>
                    <th>IP Prefix</th>
                    <th>User Agent Hash</th>
                    <?php if ($colGP): ?><th>Demo Grant</th><?php endif; ?>
                    <?php if ($colRev): ?><th>Revoked At</th><?php endif; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['username'] ?? ('#' . $s['user_id'])) ?></td>
                        <td><?= htmlspecialchars($s['created_at']) ?></td>
                        <td><?= htmlspecialchars($s['expires_at']) ?></td>
                        <td><?= htmlspecialchars($s['ip_prefix']) ?></td>
                        <td><?= htmlspecialchars($s['ua_hash']) ?></td>
                        <?php if ($colGP): ?>
                            <td><?= !empty($s['grants_premium']) ? 'Yes' : 'No' ?></td>
                        <?php endif; ?>
                        <?php if ($colRev): ?>
                            <td><?= htmlspecialchars($s['revoked_at'] ?? '') ?></td>
                        <?php endif; ?>
                        <td>
                            <div class="action-row">
                                <div><?= htmlspecialchars(substr($s['token_hash'],0,8) . '...' . substr($s['token_hash'],-8)) ?><?php if (!empty($s['raw_token_hash'])): ?> / <?= htmlspecialchars(substr($s['raw_token_hash'],0,8) . '...' . substr($s['raw_token_hash'],-8)) ?><?php endif; ?></div>
                                <form method="POST" class="form-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="token_hash" value="<?= htmlspecialchars($s['token_hash']) ?>">
                                    <button type="submit" name="revoke" class="btn btn-danger" title="Mark token revoked">Revoke</button>
                                </form>
                                <span class="muted">Dev token check removed</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </main>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
