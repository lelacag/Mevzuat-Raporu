<?php
/*
 * invite.php – approved users can send up to ten invitation emails.
 * No Javascript; all logic runs on the server.
 */
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

// only "approved" users may invite (rookies cannot)
$usr = get_user($user_id);
if (!$usr || !empty($usr['role']) && $usr['role'] === 'rookie') {
    echo "<div class=\"form-alert form-alert-error\">Bu sayfayı görüntüleme izniniz yok.</div>";
    require __DIR__ . '/includes/footer.php';
    exit;
}

$errors = [];
$result = null;

// ensure table exists (migration may not have run yet)
ensure_invitations_table();

// compute remaining invites for display
$inv_remain = 10;
if ($user_id) {
    $stmt = db_connect()->prepare("SELECT COUNT(*) FROM user_invitations WHERE invited_by = ?");
    $stmt->execute([$user_id]);
    $inv_remain = max(0, 10 - (int)$stmt->fetchColumn());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_POST['action']) && $_POST['action'] === 'revoke' && !empty($_POST['invite_id'])) {
        $invite_id = intval($_POST['invite_id']);
        $stmt = db_connect()->prepare("SELECT * FROM user_invitations WHERE id = ? AND invited_by = ? LIMIT 1");
        $stmt->execute([$invite_id, $user_id]);
        $invite = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invite) {
            $errors[] = 'Davet bulunamadı.';
        } elseif ($invite['status'] !== 'pending') {
            $errors[] = 'Sadece beklemedeki davetler iptal edilebilir.';
        } else {
            query("UPDATE user_invitations SET status='revoked' WHERE id = ?", [$invite_id]);
            $result = ['created' => 0, 'skipped' => [], 'revoked' => 1];
        }
    } else {
        $raw = $_POST['emails'] ?? '';
        $emails = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $result = create_user_invitations($user_id, $emails);
    }
}

// sync any pending invite with a user that registered outside the invite link
query(
    "UPDATE user_invitations ui
      JOIN users u ON LOWER(ui.email) COLLATE utf8mb4_unicode_ci = LOWER(u.email) COLLATE utf8mb4_unicode_ci
      SET ui.status='already_registered', ui.invited_user=u.id, ui.accepted_at=NOW()
      WHERE ui.invited_by = ? AND ui.status = 'pending'",
    [$user_id]
);

// fetch past invites with current registration state
$pdo = db_connect();
$stmt = $pdo->prepare(
    "SELECT ui.id, ui.email, ui.status, ui.created_at, ui.accepted_at,
            (SELECT COUNT(*) FROM users u WHERE LOWER(u.email) COLLATE utf8mb4_unicode_ci = LOWER(ui.email) COLLATE utf8mb4_unicode_ci) > 0 AS is_registered
      FROM user_invitations ui
      WHERE ui.invited_by = ?
      ORDER BY ui.created_at DESC"
);
$stmt->execute([$user_id]);
$invites = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                        <?php if (!empty($result['created'])): ?>
                            <?= (int)$result['created'] ?> davet gönderildi.<br>
                        <?php endif; ?>
                        <?php if (!empty($result['revoked'])): ?>
                            <?= (int)$result['revoked'] ?> davet iptal edildi.<br>
                        <?php endif; ?>
                        <?php if (!empty($result['skipped'])): ?>
                            <?= count($result['skipped']) ?> adres atlandı.
                        <?php endif; ?>
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
                    <div style="overflow-x:auto; width:100%;">
                    <table class="table-full table-headers table-compact">
                        <thead>
                          <tr><th>E-posta</th><th class="status">Kayıtlı</th><th>Durum</th><th class="action">Eylem</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invites as $inv): ?>
                            <tr>
                                <td><?= htmlspecialchars($inv['email']) ?></td>
                                <td><?= $inv['is_registered'] ? '✅' : '❌' ?></td>
                                <td><?= invite_status_label($inv['status']) ?></td>
                                <td>
                                    <?php if ($inv['status'] === 'pending'): ?>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="invite_id" value="<?= (int)$inv['id'] ?>">
                                            <input type="hidden" name="action" value="revoke">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <button type="submit" class="btn-small">Sil</button>
                                        </form>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php';
