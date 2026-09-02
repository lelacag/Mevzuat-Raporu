<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$user_id = get_current_user_id();
$slug = $_GET['slug'] ?? '';
$token = $_GET['token'] ?? null;

if (!$slug) {
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

$pdo = db_connect();
$group_stmt = $pdo->prepare("SELECT * FROM groups_table WHERE slug = ? LIMIT 1");
$group_stmt->execute([$slug]);
$group = $group_stmt->fetch(PDO::FETCH_ASSOC);
if (!$group) {
    $_SESSION['flash_error'] = 'Grup bulunamadı.';
    header('Location: ' . BASE_PATH . '/topluluklar');
    exit;
}

function translate_group_invite_status(string $status): string {
    switch ($status) {
        case 'pending':
            return 'Beklemede';
        case 'accepted':
            return 'Kabul edildi';
        case 'expired':
            return 'Süresi doldu';
        case 'revoked':
            return 'Geri çekildi';
        default:
            return $status;
    }
}

ensure_group_invites_table();

$membership_check = false;
if ($user_id) {
    $membership_check = is_group_member((int)$group['id'], $user_id);
}

$invite_sent = false;
$errors = [];
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user_id) {
        header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
        exit;
    }

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Geçersiz istek.';
    } elseif (!$membership_check) {
        $errors[] = 'Yalnızca grup üyeleri davet gönderebilir.';
    } else {
        $target_username = trim($_POST['target_username'] ?? '');
        $target_username = ltrim($target_username, '@');
        $target_email = trim(mb_strtolower($_POST['target_email'] ?? ''));
        $target_user = null;

        if ($target_username !== '') {
            $target_user = get_user_by_username($target_username);
            if (!$target_user) {
                $errors[] = 'Kullanıcı bulunamadı.';
            }
        }

        if ($target_username === '' && $target_email === '') {
            $errors[] = 'Lütfen kullanıcı adı veya e-posta girin.';
        }

        if ($target_email !== '' && !filter_var($target_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Geçerli bir e-posta girin.';
        }

        if (empty($errors)) {
            if ($target_user && is_group_member((int)$group['id'], (int)$target_user['id'])) {
                $errors[] = 'Bu kullanıcı zaten grupta.';
            }
            if ($target_email !== '') {
                $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = (SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1) LIMIT 1');
                $stmt->execute([$group['id'], $target_email]);
                if ($stmt->fetchColumn()) {
                    $errors[] = 'Bu e-posta gruba zaten kayıtlı.';
                }
            }
        }

        if (empty($errors)) {
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+14 days'));
            $invite_email = null;
            $invited_user_id = null;

            if ($target_user) {
                $invited_user_id = $target_user['id'];
                $invite_email = $target_user['email'] ?? null;
            } elseif ($target_email !== '') {
                $invite_email = $target_email;
            }

            try {
                $dup_stmt = $pdo->prepare("SELECT 1 FROM group_invites WHERE group_id = ? AND status = 'pending' AND (invited_user_id = ? OR invite_email = ?) LIMIT 1");
                $dup_stmt->execute([$group['id'], $invited_user_id, $invite_email]);
                if ($dup_stmt->fetchColumn()) {
                    $errors[] = 'Bu kullanıcıya veya e-postaya zaten bekleyen bir davet gönderilmiş.';
                } else {
                    $ins = $pdo->prepare("INSERT INTO group_invites (group_id, invited_by_user_id, invited_user_id, invite_email, invite_token, status, expires_at) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
                    $ins->execute([$group['id'], $user_id, $invited_user_id, $invite_email, $token, $expires_at]);

                    $invite_url = full_url(group_invite_url($group['slug'], $token));
                    if ($target_user) {
                        send_group_invite_notification((int)$target_user['id'], $user_id, $group, $invite_url);
                    } else {
                        send_group_invite_email($invite_email, $group, $invite_url);
                    }
                    $success_message = 'Davet gönderildi.';
                    $invite_sent = true;
                }
            } catch (Exception $e) {
                error_log('group_invite create error: ' . $e->getMessage());
                $errors[] = 'Davet oluşturulurken bir hata oluştu.';
            }
        }
    }
}

// If accessing by token and not POST, attempt acceptance flow
if ($token && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM group_invites WHERE group_id = ? AND invite_token = ? LIMIT 1");
        $stmt->execute([$group['id'], $token]);
        $invite = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invite) {
            $_SESSION['flash_error'] = 'Geçersiz veya süresi dolmuş davet.';
            header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
            exit;
        }
        if ($invite['status'] !== 'pending') {
            $_SESSION['flash_error'] = 'Bu davet artık kullanılamıyor.';
            header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
            exit;
        }
        if (!empty($invite['expires_at']) && strtotime($invite['expires_at']) < time()) {
            $pdo->prepare("UPDATE group_invites SET status = 'expired' WHERE id = ?")->execute([$invite['id']]);
            $_SESSION['flash_error'] = 'Davetin süresi doldu.';
            header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
            exit;
        }

        if (!$user_id) {
            $_SESSION['invite_token_to_accept'] = $token;
            header('Location: ' . BASE_PATH . '/giris');
            exit;
        }

        $target_match = false;
        if (!empty($invite['invited_user_id']) && (int)$invite['invited_user_id'] === (int)$user_id) {
            $target_match = true;
        }
        if (!empty($invite['invite_email']) && !empty(get_user($user_id)['email']) && strtolower(get_user($user_id)['email']) === strtolower($invite['invite_email'])) {
            $target_match = true;
        }

        if (!$target_match) {
            $_SESSION['flash_error'] = 'Bu daveti kabul etmeye yetkiniz yok.';
            header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
            exit;
        }

        if (!is_group_member((int)$group['id'], $user_id)) {
            $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")->execute([$group['id'], $user_id]);
        }
        $pdo->prepare("UPDATE group_invites SET status = 'accepted', accepted_at = NOW() WHERE id = ?")->execute([$invite['id']]);
        $_SESSION['flash'] = 'Davet kabul edildi. Gruba eklendiniz.';
        header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
        exit;
    } catch (Exception $e) {
        error_log('group_invite accept error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Davet kabul edilirken bir hata oluştu.';
        header('Location: ' . BASE_PATH . '/g/' . urlencode($slug));
        exit;
    }
}

$csrf_token = generate_csrf_token();
$pending_invites = [];
if ($membership_check) {
    $stmt = $pdo->prepare("SELECT gi.*, u.username AS invited_username FROM group_invites gi LEFT JOIN users u ON gi.invited_user_id = u.id WHERE gi.group_id = ? ORDER BY gi.created_at DESC LIMIT 100");
    $stmt->execute([$group['id']]);
    $pending_invites = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="main-container single-column">
    <main class="content-area narrow">
        <h1>Davet Et • <?= htmlspecialchars($group['name']) ?></h1>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <?php if (!$user_id): ?>
            <div class="alert alert-info">Davet göndermek için giriş yapın.</div>
        <?php elseif (!$membership_check): ?>
            <div class="alert alert-info">Bu gruba davet gönderebilmek için önce grup üyesi olmanız gerekir.</div>
        <?php else: ?>
            <form method="POST" class="form-box">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="form-group">
                    <label>Kullanıcı adı</label>
                    <input type="text" name="target_username" class="input-full" placeholder="@kullaniciadi"> 
                </div>
                <div class="form-group">
                    <label>Veya e-posta</label>
                    <input type="email" name="target_email" class="input-full" placeholder="ornek@ornek.com"> 
                </div>
                <button type="submit" class="btn btn-primary">Daveti Gönder</button>
            </form>

            <?php if (!empty($pending_invites)): ?>
                <section class="panel muted-panel" style="margin-top:24px;">
                    <h2>Bekleyen Davetler</h2>
                    <table class="table table-full">
                        <thead>
                            <tr>
                                <th>Kullanıcı / E-posta</th>
                                <th>Durum</th>
                                <th>Oluşturuldu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_invites as $invite): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($invite['invited_username'])): ?>
                                            @<?= htmlspecialchars($invite['invited_username']) ?>
                                        <?php else: ?>
                                            <?= htmlspecialchars($invite['invite_email']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars(translate_group_invite_status($invite['status'])) ?></td>
                                    <td><?= htmlspecialchars($invite['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<?php require_once __DIR__ . '/includes/footer.php';
