<?php
require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();
$errors = [];

// Optional group context
$slug = $_GET['slug'] ?? null;
$group = null;
$is_member = false;
if ($slug) {
    $stmt = $pdo->prepare("SELECT * FROM groups_table WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $group = $stmt->fetch();
    if (!$group) {
        header('Location: ' . BASE_PATH . '/topluluklar');
        exit;
    }
    if ($user_id) {
        $gm = $pdo->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
        $gm->execute([$group['id'], $user_id]);
        $member = $gm->fetch();
        if ($member) $is_member = true;
    }
}

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

// Block rookie users (unless premium/admin) from creating polls
$user_info = get_user($user_id);
$rookie_restricted = is_user_creation_restricted($user_id);

// Check whether polls tables exist; if not, show admin instructions and disable poll creation
$pdo = db_connect();
$polls_installed = true;
try {
    $chk = $pdo->query("SHOW TABLES LIKE 'polls'")->fetch();
    if (!$chk) {
        $polls_installed = false;
    }
} catch (Exception $e) {
    // If the DB is inaccessible or SHOW TABLES fails, mark as not installed
    $polls_installed = false;
    error_log('poll installation check failed: ' . $e->getMessage());
}

$edit_poll_id = (int)($_GET['edit'] ?? $_POST['edit_poll_id'] ?? 0);
$editing = false;
$edit_poll = null;
$edit_poll_content = '';
$edit_poll_title = '';
if ($edit_poll_id) {
    $stmt = $pdo->prepare('SELECT * FROM polls WHERE id = ? LIMIT 1');
    $stmt->execute([$edit_poll_id]);
    $edit_poll = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_poll) {
        $errors[] = 'Anket bulunamadı.';
    } elseif ((int)$edit_poll['user_id'] !== (int)$user_id && !is_admin()) {
        $errors[] = 'Bu anketi düzenleme yetkiniz yok.';
    } else {
        $editing = true;
        $edit_poll_title = trim($edit_poll['title'] ?? '');
        if (!empty($edit_poll['post_id'])) {
            $postStmt = $pdo->prepare('SELECT content FROM posts WHERE id = ? LIMIT 1');
            $postStmt->execute([$edit_poll['post_id']]);
            $row = $postStmt->fetch(PDO::FETCH_ASSOC);
            $edit_poll_content = $row['content'] ?? '';
        } elseif (!empty($edit_poll['group_post_id'])) {
            $gpostStmt = $pdo->prepare('SELECT content FROM group_posts WHERE id = ? LIMIT 1');
            $gpostStmt->execute([$edit_poll['group_post_id']]);
            $row = $gpostStmt->fetch(PDO::FETCH_ASSOC);
            $edit_poll_content = $row['content'] ?? '';
        }
        $optStmt = $pdo->prepare('SELECT text FROM poll_options WHERE poll_id = ? ORDER BY id ASC');
        $optStmt->execute([$edit_poll_id]);
        $options = array_map(function($x){return $x['text'];}, $optStmt->fetchAll(PDO::FETCH_ASSOC));
        if (empty($options)) {
            $options = ['', ''];
        }
    }
}

// Build options array state (persist across add_option submissions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $options = [];
    error_log('poll.php POST start user=' . intval($user_id) . ' group=' . (isset($group['id']) ? intval($group['id']) : 'none'));
    try {
        // Block rookies from submitting create_poll
        if (!empty($rookie_restricted) && isset($_POST['create_poll'])) {
            $errors[] = 'Yeni hesaplar (rookie) anket oluşturamaz. <a href="' . BASE_PATH . '/premium.php">Premium</a> olarak erişim kazanabilirsiniz.';
        }

        // Reconstruct options from submitted names like option_1, option_2, ...
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'option_') === 0) {
                $options[] = (string)$v;
            }
        }

        // If add_option was clicked - add a blank option (server-side) but cap at 10
        if (isset($_POST['add_option'])) {
            if (count($options) >= 10) {
                $errors[] = 'Seçenek sayısı en fazla 10 olabilir.';
            } else {
                $options[] = '';
            }
        }

        // If remove_option was clicked - remove that option index (only if more than 2 remain)
        if (isset($_POST['remove_option'])) {
            $remove_idx = (int)$_POST['remove_option'] - 1;
            if (count($options) > 2 && isset($options[$remove_idx])) {
                array_splice($options, $remove_idx, 1);
            }
        }

        if (isset($_POST['update_poll'])) {
            $edit_poll_id = (int)($_POST['edit_poll_id'] ?? 0);
            if ($edit_poll_id <= 0) {
                $errors[] = 'Düzenlenecek anket bulunamadı.';
            } else {
                $title = trim($_POST['title'] ?? '');
                $content = trim($_POST['content'] ?? '');
                if ($content === '') {
                    $errors[] = 'Anket sorusu gerekli.';
                }

                $opts = [];
                foreach ($options as $o) {
                    $t = trim((string)$o);
                    if ($t !== '') $opts[] = $t;
                }
                $uniq_opts = array_values(array_unique($opts));
                if (count($uniq_opts) < 2) {
                    $errors[] = 'En az 2 farklı ve benzersiz seçenek giriniz.';
                }

                if (empty($errors)) {
                    $res = update_poll($user_id, $edit_poll_id, $title, $content, $uniq_opts);
                    if (isset($res['error'])) {
                        switch ($res['error']) {
                            case 'need_two_options':
                                $errors[] = 'En az 2 farklı ve benzersiz seçenek giriniz.';
                                break;
                            case 'too_many_options':
                                $errors[] = 'En fazla 10 seçenek ekleyebilirsiniz.';
                                break;
                            case 'has_votes':
                                $errors[] = 'Oy kullanılmış anket seçenekleri düzenlenemez.';
                                break;
                            case 'forbidden':
                                $errors[] = 'Bu anketi düzenleme yetkiniz yok.';
                                break;
                            case 'not_found':
                                $errors[] = 'Anket bulunamadı.';
                                break;
                            default:
                                $errors[] = 'Anket güncellenemedi: ' . htmlspecialchars($res['error']);
                                if (!empty($res['message'])) {
                                    $errors[] = 'Hata detayı: ' . htmlspecialchars($res['message']);
                                }
                                break;
                        }
                    } else {
                        $redirect_slug = rawurlencode($res['slug'] ?? 'anket-' . $edit_poll_id);
                        $_SESSION['flash'] = 'Anket güncellendi.';
                        header('Location: ' . BASE_PATH . '/anket/' . $redirect_slug . '/' . $edit_poll_id);
                        exit;
                    }
                }
            }
        }

        if (isset($_POST['create_poll'])) {
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            if ($content === '') {
                $errors[] = 'Anket sorusu gerekli.';
            }
            // Collect non-empty, trimmed options and ensure uniqueness
            $opts = [];
            foreach ($options as $o) {
                $t = trim((string)$o);
                if ($t !== '') $opts[] = $t;
            }
            // Deduplicate — user must supply at least two different options
            $uniq_opts = array_values(array_unique($opts));
            if (count($uniq_opts) < 2) {
                $errors[] = 'En az 2 farklı ve benzersiz seçenek giriniz.';
            }

            if (empty($errors)) {
                // Use unique options only when creating poll
                $opts = $uniq_opts;
                // Create post (empty or optional description)
                $content = trim($_POST['content'] ?? '');
                if ($group) {
                    // Must be a member to post in group
                    if (!$is_member && !is_admin()) {
                        $errors[] = 'Bu gruba gönderi göndermek için üye olmalısınız.';
                    } else {
                        // Insert group post
                        $stmt = $pdo->prepare("INSERT INTO group_posts (group_id, user_id, content) VALUES (?, ?, ?)");
                        $stmt->execute([$group['id'], $user_id, $content]);
                        $group_post_id = (int)$pdo->lastInsertId();
                        // Use the Açıklama content as the poll title (first 200 chars) when title not provided
                        $title_to_use = mb_substr(trim($content), 0, 200);
                        $res = create_poll($user_id, $title_to_use, null, $group_post_id, $opts);
                        if (!is_array($res) || (!isset($res['id']) && !isset($res['error']))) {
                            error_log('poll.php: create_poll unexpected return (group path): ' . var_export($res, true));
                            $errors[] = 'Anket oluşturulamadı.';
                        } elseif (isset($res['error'])) {
                            // Map known error codes to friendly messages
                            switch ($res['error']) {
                                case 'need_two_options':
                                    $errors[] = 'En az 2 farklı ve benzersiz seçenek giriniz.';
                                    break;
                                case 'too_many_options':
                                    $errors[] = 'En fazla 10 seçenek ekleyebilirsiniz.';
                                    break;
                                case 'rookie_restricted':
                                    $errors[] = 'Yeni hesaplar (rookie) anket oluşturamaz. <a href="' . BASE_PATH . '/premium.php">Premium</a> olarak erişim kazanabilirsiniz.';
                                    break;
                                case 'db_error':
                                    error_log('create_poll DB error: ' . ($res['message'] ?? ''));
                                    $errors[] = 'Veritabanı hatası oluştu; lütfen daha sonra tekrar deneyin veya yöneticinize bildiriniz.';
                                    if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production' && !empty($res['message'])) {
                                        $errors[] = 'DEBUG: ' . htmlspecialchars($res['message']);
                                    }
                                    break;
                                default:
                                    $errors[] = 'Anket oluşturulamadı: ' . htmlspecialchars($res['error']);
                            }
                        } else {
                            // Redirect to SEO-friendly poll URL
                            $poll_id = (int)($res['id'] ?? 0);
                            $slug = $res['slug'] ?? generate_slug($title) . '-' . $poll_id;
                            $_SESSION['flash'] = 'Anket oluşturuldu.';
                            header('Location: ' . BASE_PATH . '/anket/' . rawurlencode($slug) . '/' . $poll_id);
                            exit;
                        }
                    }
                } else {
                    // regular post with poll attached
                    $resPost = create_post($user_id, $content);
                    if (!is_array($resPost) || !isset($resPost['id'])) {
                        error_log('poll.php: create_post unexpected return: ' . var_export($resPost, true));
                        $errors[] = 'Gönderi oluşturulamadı.';
                    } else {
                        $post_id = (int)$resPost['id'];
                        // Use the Açıklama content as the poll title (first 200 chars)
                        $title_to_use = mb_substr(trim($content), 0, 200);
                        $res = create_poll($user_id, $title_to_use, $post_id, null, $opts);
                        if (!is_array($res) || (!isset($res['id']) && !isset($res['error']))) {
                            error_log('poll.php: create_poll unexpected return: ' . var_export($res, true));
                            $errors[] = 'Anket oluşturulamadı.';
                        } elseif (isset($res['error'])) {
                            switch ($res['error']) {
                                case 'need_two_options':
                                    $errors[] = 'En az 2 farklı ve benzersiz seçenek giriniz.';
                                    break;
                                case 'too_many_options':
                                    $errors[] = 'En fazla 10 seçenek ekleyebilirsiniz.';
                                    break;
                                case 'db_error':
                                    error_log('create_poll DB error: ' . ($res['message'] ?? ''));
                                    $errors[] = 'Veritabanı hatası oluştu; lütfen daha sonra tekrar deneyin veya yöneticinize bildiriniz.';
                                    if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production' && !empty($res['message'])) {
                                        $errors[] = 'DEBUG: ' . htmlspecialchars($res['message']);
                                    }
                                    break;
                                default:
                                    $errors[] = 'Anket oluşturulamadı: ' . htmlspecialchars($res['error']);
                            }
                        } else {
                            // Redirect to SEO-friendly poll URL
                            $poll_id = (int)($res['id'] ?? 0);
                            $slug = $res['slug'] ?? generate_slug($title_to_use) . '-' . $poll_id;
                            $_SESSION['flash'] = 'Anket paylaşıldı.';
                            header('Location: ' . BASE_PATH . '/anket/' . rawurlencode($slug) . '/' . $poll_id);
                            exit;
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('poll.php POST exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $errors[] = 'Sunucu hatası oluştu; lütfen daha sonra tekrar deneyin.';
        if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production') {
            $errors[] = 'DEBUG: ' . htmlspecialchars($e->getMessage());
        }
    }
    error_log('poll.php POST end user=' . intval($user_id));
}

// If initial GET or no options provided, ensure two option fields exist
if (empty($options)) {
    $options = ['', ''];
}

?>
<div class="main-container">
    <aside class="sidebar sidebar-left">
        <a href="<?= BASE_PATH ?>/anasayfa" class="btn btn-primary">← Geri Dön</a>
    </aside>
    <main class="content-area narrow">
        <h1 class="page-title"><?= $editing ? 'Anketi Düzenle' : 'Yeni Anket' ?></h1>
        <?php if (!empty($errors)): ?>
            <div class="form-alert form-alert-error">
                <?php foreach ($errors as $err): ?>
                    <div class="form-alert-item"><?= htmlspecialchars($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$polls_installed): ?>
            <div class="card-box padded admin-note-error">
                <strong>Veritabanı hazır değil</strong>
                <p class="muted">Anket tablosu bulunamadı; lütfen şu adımları izleyip migration'ları çalıştırın:</p>
                <ol>
                    <li>Projede migration dosyasının var olduğundan emin olun: <code>migrations/20260211_add_polls.sql</code></li>
                    <li>Migration'ları çalıştırın: <code>./scripts/run_migrations.sh</code> ya da <code>mysql -u user -p yourdb &lt; migrations/20260211_add_polls.sql</code></li>
                </ol>
                <p class="muted">(Geliştirme ortamında iseniz, <code>ENVIRONMENT=development</code> ile hata detayları görünecektir.)</p>
            </div>
        <?php else: ?>
            <?php if ($rookie_restricted): ?>
                <div class="card-box padded admin-note-error">
                    <strong>Yeni kullanıcılar anket oluşturamaz</strong>
                    <p class="muted">Hesabınız <strong>rookie</strong> olduğu için anket oluşturma özelliği devre dışı. <a href="<?= BASE_PATH ?>/premium.php">Premium</a> olarak erişim kazanabilirsiniz.</p>
                </div>
            <?php else: ?>
        <div class="post-form-container">
            <form method="POST" class="card-box padded">
            <?php endif; ?>                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <?php if ($group): ?>
                    <input type="hidden" name="group_slug" value="<?= htmlspecialchars($group['slug']) ?>">
                    <div class="muted">Gruba: <?= htmlspecialchars($group['name']) ?></div>
                <?php endif; ?>

                <?php $content_val = $_POST['content'] ?? ($editing ? $edit_poll_content : ''); $content_len = mb_strlen($content_val); ?>
                <div class="form-row poll-form-row">
                    <label for="poll-content"><strong>Soru / Açıklama</strong></label>
                    <textarea id="poll-content" name="content" placeholder="Anket sorusu veya açıklama" required maxlength="200" class="post-textarea"><?= htmlspecialchars($content_val) ?></textarea>
                    <div class="poll-char-counter"><?= $content_len ?> / 200</div>
                </div>

                <div class="form-row poll-form-row">
                    <label><strong>Seçenekler</strong> <span class="muted small">(en az 2, en fazla 10)</span></label>
                    <div class="poll-options-builder">
                        <?php foreach ($options as $i => $opt): $idx = $i + 1; ?>
                            <div class="poll-option-row">
                                <span class="poll-option-number"><?= $idx ?></span>
                                <input type="text" class="post-option-input poll-option-input-field" name="option_<?= $idx ?>" value="<?= htmlspecialchars($opt) ?>" placeholder="Seçenek <?= $idx ?>">
                                <?php if (count($options) > 2): ?>
                                    <button type="submit" name="remove_option" value="<?= $idx ?>" class="poll-option-remove" formnovalidate title="Seçeneği kaldır" aria-label="Seçenek <?= $idx ?>'i kaldır">×</button>
                                <?php else: ?>
                                    <span class="poll-option-remove-placeholder"></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($options) < 10): ?>
                    <div class="poll-add-option-wrap">
                        <button type="submit" name="add_option" value="1" class="btn-small btn-outline-green" formnovalidate aria-label="Yeni seçenek ekle">+ Seçenek Ekle</button>
                    </div>
                    <?php else: ?>
                    <div class="poll-add-option-wrap muted small">En fazla 10 seçenek eklenebilir.</div>
                    <?php endif; ?>
                </div>

                <?php if ($editing): ?>
                    <input type="hidden" name="edit_poll_id" value="<?= (int)$edit_poll_id ?>">
                <?php endif; ?>
                <div style="margin-top:16px; margin-left:auto; margin-right:auto; max-width:560px;">
                    <?php if ($editing): ?>
                        <button type="submit" name="update_poll" class="btn-post">Anketi Güncelle</button>
                    <?php else: ?>
                        <button type="submit" name="create_poll" class="btn-post">Anketi Paylaş</button>
                    <?php endif; ?>
                    <a href="<?= BASE_PATH ?>/anasayfa" class="btn-cancel">İptal</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </main>
    <aside class="sidebar sidebar-right">
        <div class="sidebar-help">
            <p class="sidebar-help-title">İpuçları:</p>
            <ul class="sidebar-help-list">
                <li>Anketler en az 2 seçenek gerektirir.</li>
                <li>Her kullanıcı yalnızca bir kez oy kullanabilir; oy değiştirebilir.</li>
            </ul>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
