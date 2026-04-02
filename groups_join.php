<?php /* EN + TR comments used. */
/**
 * Join Group Handler
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$user_id = get_current_user_id();

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf_token)) {
    die('Invalid CSRF token');
}

$group_id = intval($_POST['group_id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'groups.php';

if (!$group_id) {
    header('Location: ' . BASE_PATH . '/groups.php');
    exit;
}

try {
    // Check if group exists
    $stmt = $pdo->prepare("SELECT id, slug, name, created_by, is_private, entry_question FROM groups_table WHERE id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();
    
    if (!$group) {
        header('Location: ' . BASE_PATH . '/groups.php');
        exit;
    }
    
    // If group is private, require entry answer and create a join request
    if (!empty($group['is_private'])) {
        $answer = trim($_POST['entry_answer'] ?? '');
        if ($group['entry_question'] && $answer === '') {
            $_SESSION['flash'] = 'Bu özel gruba katılmak için soruyu yanıtlayın.';
            header('Location: ' . BASE_PATH . '/group.php?slug=' . urlencode($group['slug']));
            exit;
        }
        try {
            // Insert join request (idempotent per user)
            $exists = $pdo->prepare("SELECT id FROM group_join_requests WHERE group_id = ? AND user_id = ? AND status = 'pending'");
            $exists->execute([$group_id, $user_id]);
            if (!$exists->fetch()) {
                $ins = $pdo->prepare("INSERT INTO group_join_requests (group_id, user_id, answer) VALUES (?, ?, ?)");
                $ins->execute([$group_id, $user_id, $answer]);
                // Notify requester that their application was submitted
                try {
                    $textReq = "Başvurunuz gönderildi: " . ($group['name'] ?? ('Grup #' . $group_id));
                    $stmtNReq = $pdo->prepare("INSERT INTO notifications (user_id, type, text, from_user_id, created_at) VALUES (?, 'system', ?, ?, NOW())");
                    $stmtNReq->execute([$user_id, $textReq, $user_id]);
                } catch (PDOException $e) { /* ignore notif errors */ }
                // Notify group admins about the new request
                try {
                    $adm = $pdo->prepare("SELECT gm.user_id FROM group_members gm WHERE gm.group_id = ? AND gm.role = 'admin'");
                    $adm->execute([$group_id]);
                    $admins = $adm->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($admins)) {
                        $groupName = isset($group['name']) ? $group['name'] : ('Grup #' . $group_id);
                        $text = "Yeni grup katılma isteği: @" . get_user($user_id)['username'] . " → " . $groupName;
                        foreach ($admins as $admin_id) {
                            $stmtN = $pdo->prepare("INSERT INTO notifications (user_id, type, text, from_user_id, created_at) VALUES (?, 'system', ?, ?, NOW())");
                            $stmtN->execute([$admin_id, $text, $user_id]);
                        }
                    }
                    // Also notify group owner if not already included
                    if (!empty($group['created_by']) && (empty($admins) || !in_array($group['created_by'], $admins))) {
                        $groupName = isset($group['name']) ? $group['name'] : ('Grup #' . $group_id);
                        $textOwner = "Yeni grup katılma isteği: @" . get_user($user_id)['username'] . " → " . $groupName;
                        $stmtNO = $pdo->prepare("INSERT INTO notifications (user_id, type, text, from_user_id, created_at) VALUES (?, 'system', ?, ?, NOW())");
                        $stmtNO->execute([$group['created_by'], $textOwner, $user_id]);
                    }
                } catch (PDOException $ne) { /* ignore notif errors */ }
            }
            // Do not set a flash message here; group page will show a single pending message
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                // Table missing: fallback message without crashing
                $_SESSION['flash'] = 'Başvurular şu an alınamıyor. Lütfen daha sonra tekrar deneyin.';
            } else {
                error_log('Group join request error: ' . $e->getMessage());
                $_SESSION['flash'] = 'Beklenmeyen bir hata oluştu.';
            }
        }
        header('Location: ' . BASE_PATH . '/group.php?slug=' . urlencode($group['slug']));
        exit;
    }

    // Check if already a member
    $stmt = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$group_id, $user_id]);
    
    if (!$stmt->fetch()) {
        // Add user to group
        $stmt = $pdo->prepare("
            INSERT INTO group_members (group_id, user_id, role)
            VALUES (?, ?, 'member')
        ");
        $stmt->execute([$group_id, $user_id]);
        $_SESSION['flash'] = 'Gruba katıldınız!';
    }
    
} catch (PDOException $e) {
    error_log('Group join error: ' . $e->getMessage());
}

header('Location: ' . BASE_PATH . '/' . $redirect);
exit;
