<?php
// module-level invite page.  mirrors root invite.php but lives under modules/invites.

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/functions.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

// module guard (extra safety; root stub already checks)
if (!defined('INVITES_MODULE_ENABLED') || !INVITES_MODULE_ENABLED) {
    echo "<div class=\"form-alert form-alert-error\">Davet özelliği şu anda devre dışı.</div>";
    require __DIR__ . '/../../includes/footer.php';
    exit;
}

// only "approved" users may invite (rookies cannot)
$usr = get_user($user_id);
if (!$usr || !empty($usr['role']) && $usr['role'] === 'rookie') {
    echo "<div class=\"form-alert form-alert-error\">Bu sayfayı görüntüleme izniniz yok.</div>";
    require __DIR__ . '/../../includes/footer.php';
    exit;
}

$errors = [];
$result = null;

// ensure table exists (migration may not have run yet)
if (defined('INVITES_MODULE_ENABLED') && INVITES_MODULE_ENABLED) {
    ensure_invitations_table();

    // compute remaining invites for display
    $inv_remain = 10;
    if ($user_id) {
        $stmt = db_connect()->prepare("SELECT COUNT(*) FROM user_invitations WHERE invited_by = ?");
        $stmt->execute([$user_id]);
        $inv_remain = max(0, 10 - (int)$stmt->fetchColumn());
    }
} else {
    $inv_remain = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Geçersiz istek.';
    } else {
        $raw = $_POST['emails'] ?? '';
        $emails = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $result = create_user_invitations($user_id, $emails);
    }
}

// fetch past invites
$invites = [];
if (defined('INVITES_MODULE_ENABLED') && INVITES_MODULE_ENABLED) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT email,status,created_at,accepted_at FROM user_invitations WHERE invited_by = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $invites = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$csrf_token = generate_csrf_token();
?>
<div class="main-container single-column">
    <div class="content-section">
        <div class="content-container">
            <section class="registration-section invite-section">
                <h2>🚀 Davet Gönder</h2>
                <p>
                    Bir kerede en fazla 10 adres ekleyebilirsiniz.
                </p>
                <?php if ($user_id): ?>
                    <div class="invite-stats">
                        <span class="invite-badge">Kalan davet: <?= $inv_remain ?></span>
                    </div>
                <?php endif; ?>
                <p>
                    Davet ettiğiniz kişiler kayıt olduklarında size <strong>1 ay premium üyelik</strong> verilecektir.
                </p>
                <div class="premium-box">
                    <p class="muted small">
                        Premium üyelik aşağıdaki özellikleri etkinleştirir. Davet edilen
                        kullanıcı kayıt olduğunda bu özelliklerden bir ay boyunca faydalanacaksınız:
                    </p>
                    <ul class="plain-list">
                        <?php foreach (get_premium_features() as $f): ?>
                        <li><?= htmlspecialchars($f) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <?php if ($errors): ?>
                    <div class="form-alert form-alert-error">
                        <?php foreach ($errors as $e): ?>
                            <?= htmlspecialchars($e) ?><br>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($result): ?>
                    <div class="form-alert form-alert-success">
                        <?= (int)$result['created'] ?> davet gönderildi.
                        <?= count($result['skipped']) ?> adres atlandı.
                    </div>
                    <?php if (!empty($result['skipped'])): ?>
                        <ul>
                            <?php foreach ($result['skipped'] as $bad): ?>
                                <li><?= htmlspecialchars($bad) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>

                <form method="post">
                    <textarea name="emails" rows="6" class="invite-textarea" placeholder="email1@example.com, email2@example.com veya her satıra bir adres"></textarea>
                    <div style="margin-top:8px;">
                        <button type="submit" class="btn-post">Daveti Gönder</button>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                </form>

                <?php if (!empty($invites)): ?>
                    <h3 style="margin-top:24px;">Gönderilen Davetler</h3>
                    <table class="table-full table-headers">
                        <thead>
                          <tr><th>E-posta</th><th>Durum</th><th>Oluşturma</th><th>Kabul</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invites as $inv): ?>
                            <tr>
                                <td><?= htmlspecialchars($inv['email']) ?></td>
                                <td><?= invite_status_label($inv['status']) ?></td>
                                <td><?= htmlspecialchars($inv['created_at']) ?></td>
                                <td><?= htmlspecialchars($inv['accepted_at'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php';
