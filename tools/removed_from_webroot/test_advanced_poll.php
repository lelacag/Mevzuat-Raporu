<?php /* EN + TR comments used. */
require_once __DIR__ . '/includes/header.php';

// Simple server-side test for the advanced questionnaire feature.
// Uses session to persist in-progress test definition while adding options/thresholds (no DB).

// Helper for creating an empty question template (used for new questions and session initialization)
function test_advanced_poll_empty_question() {
    return [
        'question_text' => '',
        'options' => [
            ['points' => '', 'label' => ''],
            ['points' => '', 'label' => '']
        ]
    ];
}

function test_advanced_poll_state_is_empty(array $state): bool {
    if (empty($state['title'])) {
        if (!isset($state['questions']) || !is_array($state['questions'])) return true;
        if (count($state['questions']) !== 1) return false;
        $q = $state['questions'][0];
        if (!empty(trim((string)($q['question_text'] ?? '')))) return false;
        if (!isset($q['options']) || !is_array($q['options'])) return true;
        foreach ($q['options'] as $opt) {
            if (!empty(trim((string)($opt['points'] ?? '')))) return false;
            if (!empty(trim((string)($opt['label'] ?? '')))) return false;
        }
        if (!isset($state['thresholds']) || !is_array($state['thresholds'])) return true;
        foreach ($state['thresholds'] as $th) {
            if (!empty(trim((string)($th['value'] ?? '')))) return false;
            if (!empty(trim((string)($th['out'] ?? '')))) return false;
        }
        return true;
    }
    return false;
}

$user_id = get_current_user_id();
$errors = [];

if (!$user_id) {
    // Allow testing without login but mark as guest
    $is_guest = true;
} else {
    $is_guest = false;
}

// Block rookie users (unless premium/admin) from creating tests
$rookie_restricted = false;
if ($user_id) {
    $rookie_restricted = is_user_creation_restricted($user_id);
}

if (!isset($_SESSION['test_anket'])) {
    $_SESSION['test_anket'] = [
        'title' => '',
        'questions' => [ test_advanced_poll_empty_question() ],
        'thresholds' => [ ['value' => '', 'out' => ''] ],
    ];
}
if (!isset($_SESSION['test_anket_pending_delete'])) {
    $_SESSION['test_anket_pending_delete'] = null;
}

// Bind shortcut
$state =& $_SESSION['test_anket'];
$pending_delete = &$_SESSION['test_anket_pending_delete'];
// Backwards compat: if older session exists with top-level 'options', convert to questions
if (!empty($state['options']) && empty($state['questions'])) {
    $state['questions'] = [ [ 'question_text' => $state['title'] ?? '', 'options' => $state['options'] ] ];
    unset($state['options']);
} 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Determine edit context so redirects stay on the same edit page.
    $edit_id = !empty($_POST['edit_test_id']) ? (int)$_POST['edit_test_id'] : (int)($_GET['edit'] ?? 0);
    $redirect_to = BASE_PATH . '/tahlil/olustur';
    if ($edit_id) {
        $redirect_to = BASE_PATH . '/tahlil/duzenle/' . $edit_id;
    }

    // If the user has clicked "Resume" (and we are not in edit mode), keep ?resume=1 on intermediate redirects
    $resume_suffix = '';
    if (empty($edit_id)) {
        $resume_flag = !empty($_POST['resume']) || !empty($_GET['resume']);
        if ($resume_flag) {
            $resume_suffix = '?resume=1';
        }
    }

    // Handle draft resume/new actions from the prompt page
    if (isset($_POST['resume'])) {
        $slug = trim((string)($_POST['slug'] ?? ''));
        $q = $slug !== '' ? '?slug=' . rawurlencode($slug) . '&resume=1' : '?resume=1';
        header('Location: ' . BASE_PATH . '/tahlil/olustur' . $q);
        exit;
    }
    if (isset($_POST['start_new'])) {
        unset($_SESSION['test_anket'], $_SESSION['test_anket_pending_delete']);
        $slug = trim((string)($_POST['slug'] ?? ''));
        $q = $slug !== '' ? '?slug=' . rawurlencode($slug) : '';
        header('Location: ' . BASE_PATH . '/tahlil/olustur' . $q);
        exit;
    }

    // Immediately block create_test for restricted users
    if (!empty($rookie_restricted) && isset($_POST['create_test'])) {
        $errors[] = 'Yeni hesaplar (rookie) Tahlil oluşturamaz. <a href="' . BASE_PATH . '/premium.php">Premium</a> olarak erişim kazanabilirsiniz.';
    }

    // Persist top-level fields (title, etc.) so the form doesn't lose them on intermediate actions
    $state['title'] = trim($_POST['title'] ?? $state['title']);
    if (isset($_POST['group_slug'])) {
        $state['group_slug'] = trim($_POST['group_slug']);
    }

    // Update existing questions/options in-place from submitted values (avoid overwriting missing fields)
    foreach ($state['questions'] as $qi => &$q) {
        $qidx = $qi + 1;
        if (isset($_POST['question_text_' . $qidx])) {
            $q['question_text'] = trim((string)$_POST['question_text_' . $qidx]);
        }
        foreach ($q['options'] as $oi => &$opt) {
            $oidx = $oi + 1;
            $pointsKey = 'opt_points_' . $qidx . '_' . $oidx;
            $labelKey = 'opt_label_' . $qidx . '_' . $oidx;
            if (isset($_POST[$pointsKey])) {
                $opt['points'] = trim((string)$_POST[$pointsKey]);
            }
            if (isset($_POST[$labelKey])) {
                $opt['label'] = trim((string)$_POST[$labelKey]);
            }
        }
        unset($opt);
    }
    unset($q);

    // Sync thresholds as an ordered list
    $new_th = [];
    foreach ($_POST as $k => $v) {
        if (strpos($k, 'th_value_') === 0) {
            $idx = (int)substr($k, strlen('th_value_')) - 1;
            $out = $_POST['th_out_' . ($idx+1)] ?? '';
            $new_th[$idx] = ['value' => trim((string)$v), 'out' => trim((string)$out)];
        }
    }
    ksort($new_th);
    $state['thresholds'] = array_values($new_th ?: []);

    // Add option to a specific question (value is question index 1-based)
    if (isset($_POST['add_option'])) {
        $which = (int)$_POST['add_option'];
        if ($which > 0 && isset($state['questions'][$which-1])) {
            if (count($state['questions'][$which-1]['options']) >= 50) $errors[] = 'En fazla 50 seçenek eklenebilir.';
            else $state['questions'][$which-1]['options'][] = ['points' => '', 'label' => ''];
        }
        if (empty($errors)) {
            $_SESSION['test_anket'] = $state;
            header('Location: ' . $redirect_to . $resume_suffix);
            exit;
        }
    }

    // Add a new question
    if (isset($_POST['add_question'])) {
        if (count($state['questions']) >= 50) $errors[] = 'En fazla 50 soru eklenebilir.';
        else $state['questions'][] = test_advanced_poll_empty_question();
        if (empty($errors)) {
            $_SESSION['test_anket'] = $state;
            header('Location: ' . $redirect_to . $resume_suffix);
            exit;
        }
    }

    if (isset($_POST['add_threshold'])) {
        if (count($state['thresholds']) >= 50) $errors[] = 'En fazla 50 eşik değeri eklenebilir.';
        else $state['thresholds'][] = ['value' => '', 'out' => ''];
        if (empty($errors)) {
            $_SESSION['test_anket'] = $state;
            header('Location: ' . $redirect_to . $resume_suffix);
            exit;
        }
    }

    // Delete question (inline confirmation)
    if (isset($_POST['delete_question'])) {
        $idx = (int)$_POST['delete_question'] - 1;
        if ($idx >= 0 && isset($state['questions'][$idx])) {
            $pending_delete = $idx;
        }
    }
    if (isset($_POST['cancel_delete_question'])) {
        $pending_delete = null;
        $_SESSION['test_anket_pending_delete'] = null;
        header('Location: ' . $redirect_to . $resume_suffix);
        exit;
    }
    if (isset($_POST['confirm_delete_question'])) {
        $idx = (int)$_POST['confirm_delete_question'] - 1;
        if ($idx >= 0 && isset($state['questions'][$idx])) {
            array_splice($state['questions'], $idx, 1);
            if (count($state['questions']) === 0) {
                $state['questions'][] = test_advanced_poll_empty_question();
            }
        }
        $pending_delete = null;
        $_SESSION['test_anket_pending_delete'] = null;
        $_SESSION['test_anket'] = $state;
        header('Location: ' . $redirect_to . $resume_suffix);
        exit;
    }

    if (isset($_POST['create_test'])) {
        $state['title'] = trim($_POST['title'] ?? '');
        // Basic validation
        if ($state['title'] === '') $errors[] = 'Açıklama (Başlık) gerekli.';

        // Build valid questions array
        $valid_questions = [];
        foreach ($state['questions'] as $q) {
            $qtext = trim((string)($q['question_text'] ?? ''));
            $opts = [];
            foreach (($q['options'] ?? []) as $opt) {
                $pts = trim((string)$opt['points']);
                $lbl = trim((string)$opt['label']);
                if ($pts === '' || !is_numeric($pts) || $lbl === '') continue;
                $opts[] = ['points' => (int)$pts, 'label' => $lbl];
            }
            if ($qtext === '' || count($opts) < 1) continue; // skip invalid questions
            $valid_questions[] = ['question_text' => $qtext, 'options' => $opts];
        }
        if (count($valid_questions) < 1) $errors[] = 'En az 1 geçerli soru (ve her soru için en az 1 seçenek) gerekli.';

        // thresholds
        $valid_th = [];
        foreach ($state['thresholds'] as $t) {
            $v = trim((string)$t['value']); $o = trim((string)$t['out']);
            if ($v === '' || !is_numeric($v) || $o === '') continue;
            $valid_th[] = ['value' => (int)$v, 'out' => $o];
        }
        if (count($valid_th) < 1) $errors[] = 'En az 1 eşik değeri ve çıktı gerekli.';

        if (empty($errors)) {
            // normalize and sort thresholds ascending; we'll apply rule: first threshold where sum <= value
            usort($valid_th, function($a,$b){ return $a['value'] - $b['value']; });

            // If editing an existing test
            if (!empty($_POST['edit_test_id']) && is_numeric($_POST['edit_test_id']) && !empty($user_id)) {
                $edit_id = (int)$_POST['edit_test_id'];
                $ures = update_test_db($user_id, $edit_id, $state['title'], $valid_questions, $valid_th);
                if (isset($ures['error'])) {
                    if ($ures['error'] === 'forbidden') $errors[] = 'Düzenleme izniniz yok.';
                    else {
                        error_log('update_test_db error for user ' . $user_id . ' test ' . $edit_id . ': ' . ($ures['message'] ?? $ures['error']));
                        $errors[] = 'Test güncellenemedi; lütfen yöneticinize bildirin veya migrationları çalıştırın.';
                        if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production' && !empty($ures['message'])) {
                            $errors[] = 'Hata detayı: ' . htmlspecialchars($ures['message']);
                        }
                    }
                } else {
                    $tb = get_test_by_id($edit_id);
                    // normalize threshold names
                    if (!empty($tb['thresholds'])) {
                        foreach ($tb['thresholds'] as &$th) {
                            if (!isset($th['out']) && isset($th['out_text'])) $th['out'] = $th['out_text'];
                        }
                        unset($th);
                    }
                    $_SESSION['test_anket_final'] = $tb;
                    $_SESSION['flash'] = 'Test güncellendi.';
                    $slug = $ures['slug'] ?? ($tb['slug'] ?? (generate_slug($state['title']) . '-' . $edit_id));
                    error_log('test_advanced_poll: update OK, returning to editor for test ' . $edit_id . ' (user=' . intval($user_id) . ')');
                    // Return user to the editor page (stay on edit view) so they can continue refining
                    header('Location: ' . BASE_PATH . '/tahlil/duzenle/' . $edit_id . '?updated=1');
                    exit;
                }
            }

            // If user is logged in, persist to DB (create new)
            if (!empty($user_id)) {
                $res = create_test_db($user_id, $state['title'], $valid_questions, $valid_th);
                if (isset($res['error'])) {
                    error_log('create_test_db error for user ' . $user_id . ': ' . ($res['message'] ?? $res['error']));
                    if ($res['error'] === 'rookie_restricted') {
                        $errors[] = 'Yeni hesaplar (rookie) Tahlil oluşturamaz. <a href="' . BASE_PATH . '/premium.php">Premium</a> olarak erişim kazanabilirsiniz.';
                    } else {
                        $errors[] = 'Test veritabanına kaydedilemedi; lütfen migrationları çalıştırın veya yöneticinize bildirin.';
                        if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production' && !empty($res['message'])) {
                            $errors[] = 'Hata detayı: ' . htmlspecialchars($res['message']);
                        }
                    }
                } else {
                    $test_id = (int)$res['id'];
                    $tb = get_test_by_id($test_id);
                    // Normalize thresholds from DB (out_text → out) for consistent templates
                    if (!empty($tb['thresholds']) && is_array($tb['thresholds'])) {
                        foreach ($tb['thresholds'] as &$th) {
                            if (!isset($th['out']) && isset($th['out_text'])) $th['out'] = $th['out_text'];
                        }
                        unset($th);
                    }
                    // store DB-backed test structure as final
                    $_SESSION['test_anket_final'] = $tb;
                    $_SESSION['flash'] = 'Test kaydedildi ve kullanılabilir.';

                    // If a group slug was provided (via composer from a group), create both a group post and a profile post.
                    $pdo = db_connect();
                    $group_slug_post = trim($_POST['group_slug'] ?? ($state['group_slug'] ?? ''));
                    if ($group_slug_post !== '') {
                        // Find group id
                        $stmt = $pdo->prepare("SELECT id FROM groups_table WHERE slug = ? LIMIT 1");
                        $stmt->execute([$group_slug_post]);
                        $g = $stmt->fetch();
                        if ($g) {
                            try {
                                // create group post
                                $pdo->prepare("INSERT INTO group_posts (group_id, user_id, content) VALUES (?, ?, ?)")->execute([$g['id'], $user_id, $state['title']]);
                                $gp_id = (int)$pdo->lastInsertId();
                                $pdo->prepare("INSERT INTO post_tests (test_id, group_post_id) VALUES (?, ?)")->execute([$test_id, $gp_id]);
                            } catch (Exception $e) { error_log('attach test to group post error: ' . $e->getMessage()); }

                            // Also create a profile post with a small group indicator
                            $profile_content = $state['title'] . ' (Grup: ' . $group_slug_post . ')';
                            $post_res = create_post($user_id, $profile_content);
                            if (isset($post_res['id'])) {
                                try { $pdo->prepare("INSERT INTO post_tests (test_id, post_id) VALUES (?, ?)")->execute([$test_id, (int)$post_res['id']]); }
                                catch (Exception $e) { error_log('attach test to post error: ' . $e->getMessage()); }
                            }
                        } else {
                            $errors[] = 'Belirtilen grup bulunamadı (slug hatalı).';
                        }
                    } else {
                        // No group: create a profile post only
                        $post_res = create_post($user_id, $state['title']);
                        if (isset($post_res['id'])) {
                            try { $pdo->prepare("INSERT INTO post_tests (test_id, post_id) VALUES (?, ?)")->execute([$test_id, (int)$post_res['id']]); }
                            catch (Exception $e) { error_log('attach test to post error: ' . $e->getMessage()); }
                        }
                    }

                    // Redirect to SEO-friendly Tahlil URL
                    $slug = $res['slug'] ?? (generate_slug($state['title']) . '-' . $test_id);
                    header('Location: ' . BASE_PATH . '/tahlil/' . rawurlencode($slug) . '/' . $test_id);
                    exit;
                }
            } else {
                // No user: keep session-based final result
                $_SESSION['test_anket_final'] = [
                    'title' => $state['title'],
                    'questions' => $valid_questions,
                    'thresholds' => $valid_th
                ];
                $_SESSION['flash'] = 'Test oluşturuldu (geçici). Hesapla devam edebilirsiniz.';
                header('Location: ' . BASE_PATH . '/tahlil/olustur');
                exit;
            }
        }
    }

    // If taking test (multi-question aware)
    if (isset($_POST['take_test']) && isset($_SESSION['test_anket_final'])) {
        $final = $_SESSION['test_anket_final'];
        $sum = 0;
        $answered_count = 0;
        $qcount = count($final['questions'] ?? []);
        foreach (($final['questions'] ?? []) as $qi => $q) {
            $field = 'q_' . $qi;
            if (!isset($_POST[$field])) {
                $errors[] = 'Lütfen tüm soruları cevaplayın.';
                break;
            }
            $val = $_POST[$field];
            // If options have numeric 'id' (DB-backed), match by id
            $matched = false;
            foreach ($q['options'] as $opt_index => $opt) {
                if (isset($opt['id']) && (int)$opt['id'] === (int)$val) {
                    $sum += (int)$opt['points']; $matched = true; break;
                }
            }
            if (!$matched) {
                // try treat value as index
                $vi = (int)$val;
                if (isset($q['options'][$vi])) { $sum += (int)$q['options'][$vi]['points']; $matched = true; }
            }
            if ($matched) $answered_count++; else { $errors[] = 'Geçersiz seçenek gönderildi.'; break; }
        }

        if (!empty($errors)) {
            header('Location: ' . BASE_PATH . '/tahlil/olustur');
            exit;
        }

        // find first threshold where sum <= value (support both 'out' and DB 'out_text')
        $result_out = null;
        foreach ($final['thresholds'] as $th) {
            $th_value = isset($th['value']) ? (int)$th['value'] : 0;
            $th_out = $th['out'] ?? $th['out_text'] ?? '';
            if ($sum <= $th_value) { $result_out = $th_out; break; }
        }
        if ($result_out === null) {
            $last = end($final['thresholds']);
            $result_out = ($last['out'] ?? $last['out_text'] ?? '');
            if ($result_out === '') $result_out = 'Sonuç bulunamadı.';
        }

        // Store result to session for immediate display
        $_SESSION['test_result'] = ['sum' => $sum, 'out' => $result_out];

        // If the user is logged in, persist a system notification so they are prompted about their result
        if (!empty($user_id)) {
            try {
                $notif_text = "Test sonucu: '" . addslashes($final['title'] ?? '') . "' — Toplam puan: " . intval($sum) . " — Sonuç: " . addslashes($result_out);
                query("INSERT INTO notifications (user_id, type, text, from_user_id, created_at) VALUES (?, 'system', ?, NULL, NOW())", [$user_id, $notif_text]);
            } catch (Exception $e) {
                error_log('test notification insert error: ' . $e->getMessage());
            }
        }

        header('Location: ' . BASE_PATH . '/tahlil/olustur');
        exit;
    }
}

// If a draft exists in session, offer the user the choice to resume it or start fresh.
$has_draft = !test_advanced_poll_state_is_empty($state);
$show_resume_prompt = $_SERVER['REQUEST_METHOD'] === 'GET'
    && $has_draft
    && empty($_GET['edit'])
    && empty($_GET['resume']);

if ($show_resume_prompt) {
    $slug = trim((string)($_GET['slug'] ?? ''));
    $stub = $slug !== '' ? '?slug=' . rawurlencode($slug) : '';

    // Draft summary variables (for user preview)
    $draft_title = trim((string)($state['title'] ?? ''));
    $draft_questions = $state['questions'] ?? [];
    $draft_q_count = is_array($draft_questions) ? count($draft_questions) : 0;
    $draft_first_q = '';
    $draft_first_opt = '';
    $draft_first_opt_pts = '';
    if ($draft_q_count > 0 && is_array($draft_questions[0])) {
        $draft_first_q = trim((string)($draft_questions[0]['question_text'] ?? ''));
        $first_opts = $draft_questions[0]['options'] ?? [];
        if (!empty($first_opts) && is_array($first_opts[0])) {
            $draft_first_opt = trim((string)($first_opts[0]['label'] ?? ''));
            $draft_first_opt_pts = trim((string)($first_opts[0]['points'] ?? ''));
            if ($draft_first_opt !== '' && $draft_first_opt_pts !== '') {
                $draft_first_opt .= ' (' . htmlspecialchars($draft_first_opt_pts) . ')';
            }
        }
    }
    ?>
    <div class="main-container post-new">
        <aside class="sidebar sidebar-left">
            <a href="<?= BASE_PATH ?>/anasayfa" class="btn btn-primary">← Geri Dön</a>
        </aside>
        <main class="content-area narrow">
            <h1 class="page-title">Tahlil Taslağı</h1>
            <div class="card-box padded">
                <p>Bu tarayıcıda daha önce oluşturduğunuz bir Tahlil taslağı mevcut. Dilerseniz kaldığınız yerden devam edebilir ya da yeni bir Tahlil oluşturabilirsiniz.</p>
                <div class="form-row" style="margin-top:12px;">
                    <div><strong>Taslak Özeti:</strong></div>
                    <?php if ($draft_title !== ''): ?><div>• Başlık: <?= htmlspecialchars($draft_title) ?></div><?php endif; ?>
                    <div>• Soru sayısı: <?= (int)$draft_q_count ?></div>
                    <?php if ($draft_first_q !== ''): ?><div>• İlk soru: <?= htmlspecialchars($draft_first_q) ?></div><?php endif; ?>
                    <?php if ($draft_first_opt !== ''): ?><div>• İlk seçenek: <?= htmlspecialchars($draft_first_opt) ?></div><?php endif; ?>
                </div>
                <form method="POST" style="display:inline; margin-right:12px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="resume" value="1">
                    <?php if ($slug !== ''): ?><input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>"><?php endif; ?>
                    <button type="submit" class="btn-post">Taslağa Devam Et</button>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="start_new" value="1">
                    <?php if ($slug !== ''): ?><input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>"><?php endif; ?>
                    <button type="submit" class="btn-outline">Yeni Başlat</button>
                </form>
            </div>
        </main>
    </div>
    <?php
    exit;
}

// Edit existing test: load into $state if owner
$editing_id = null;
if (!empty($_GET['edit']) && is_numeric($_GET['edit']) && !empty($user_id)) {
    $eid = (int)$_GET['edit'];
    $test = get_test_by_id($eid);
    if ($test && isset($test['user_id']) && (int)$test['user_id'] === (int)$user_id) {
        // map DB test to state format
        $state = [
            'title' => $test['title'] ?? '',
            'questions' => [],
            'thresholds' => []
        ];
        foreach ($test['questions'] as $q) {
            $opts = [];
            foreach ($q['options'] as $o) {
                $opts[] = ['points' => (int)$o['points'], 'label' => $o['label']];
            }
            $state['questions'][] = ['question_text' => $q['question_text'], 'options' => $opts];
        }
        foreach ($test['thresholds'] as $th) {
            $state['thresholds'][] = ['value' => $th['value'], 'out' => ($th['out'] ?? $th['out_text'] ?? '')];
        }
        $_SESSION['test_anket'] = $state;
        $editing_id = $eid;
    } else {
        $errors[] = 'Düzenleme izniniz yok veya test bulunamadı.';
    }
}

// Prefill group slug if provided (useful when launched from group composer)
if (!empty($_GET['slug'])) {
    $state['group_slug'] = trim($_GET['slug']);
    // When slug provided from group link, default attach to group
    $state['attach_default'] = 'group';
}



// Render form
?>
<div class="main-container post-new">
    <aside class="sidebar sidebar-left">
        <a href="<?= BASE_PATH ?>/anasayfa" class="btn btn-primary">← Geri Dön</a>
        <div class="sidebar-help" style="margin-top:12px;">
            <p class="sidebar-help-title">İpuçları:</p>
            <ul class="sidebar-help-list">
                <li>Eklemeleri sunucuda "➕ Ekle" ile yapıyoruz (JS yok).</li>
                <li>Eşikler artan biçimde olmalı; hesaplama: ilk bulunan eşik ile toplam ≤ eşik değeri eşleşir.</li>
                <li>Oluşturduktan sonra Tahlil SEO dostu bir URL alır ve paylaşılabilir.</li>
            </ul>
        </div>
    </aside>
    <main class="content-area narrow">
        <h1 class="page-title">Tahlil</h1>

        <?php if (!empty($errors)): ?>
            <div class="form-alert form-alert-error">
                <?php foreach ($errors as $err): ?>
                    <div class="form-alert-item"><?= htmlspecialchars($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash'])) { echo '<div class="flash flash-success">' . htmlspecialchars($_SESSION['flash']) . '</div>'; unset($_SESSION['flash']); } ?>

        <div class="test-advanced">
        <?php if ($rookie_restricted): ?>
            <div class="card-box padded admin-note-error">
                <strong>Yeni kullanıcılar Tahlil oluşturamaz</strong>
                <p class="muted">Hesabınız <strong>rookie</strong> olduğu için Tahlil (çok sorulu test) oluşturma özelliği devre dışı. <a href="<?= BASE_PATH ?>/premium.php">Premium</a> olarak erişim kazanabilirsiniz.</p>
            </div>
        <?php else: ?>
        <form method="POST" class="card-box padded">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"> 
        <?php endif; ?>

            <!-- Section 1: Açıklamalar -->
            <div class="form-row test-desc">
                <label><strong>Açıklama</strong></label>
                <input type="text" name="title" value="<?= htmlspecialchars($state['title']) ?>" placeholder="Anket başlığı veya açıklama">
            </div>

            <!-- Section 2: Questions and Options (points + explanation) -->
            <div class="form-row">
                <label><strong>Sorular ve Seçenekler (Puan) + Açıklama</strong></label>
                <?php foreach ($state['questions'] as $qi => $q): $qidx = $qi+1; ?>
                    <div class="question-block">
                        <div class="question-header"><label><strong>Soru <?= $qidx ?></strong> <input class="question-text" type="text" name="question_text_<?= $qidx ?>" value="<?= htmlspecialchars($q['question_text']) ?>" placeholder="Soru metni"></label></div>
                        <div class="question-options">
                        <?php foreach ($q['options'] as $oi => $opt): $oidx = $oi+1; ?>
                            <div class="opt">
                                <input class="opt-points" type="text" name="opt_points_<?= $qidx ?>_<?= $oidx ?>" value="<?= htmlspecialchars($opt['points']) ?>" placeholder="Puan">
                                <input class="opt-label" type="text" name="opt_label_<?= $qidx ?>_<?= $oidx ?>" value="<?= htmlspecialchars($opt['label']) ?>" placeholder="Seçenek açıklaması">
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <div class="question-actions">
                            <button type="submit" name="add_option" value="<?= $qidx ?>" class="btn-small">➕ Seçenek Ekle</button>
                            <button type="submit" name="delete_question" value="<?= $qidx ?>" class="btn-small btn-danger">Sil</button>
                        </div>
                        <?php if (isset($pending_delete) && $pending_delete === $qi): ?>
                            <div class="form-alert form-alert-warning" style="margin-top:8px;">
                                <p><strong>Bu soruyu silmek istediğinize emin misiniz?</strong></p>
                                <button type="submit" name="confirm_delete_question" value="<?= $qidx ?>" class="btn-small btn-danger">Onayla</button>
                                <button type="submit" name="cancel_delete_question" class="btn-small btn-outline">İptal</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div style="margin-top:8px;">
                    <button type="submit" name="add_question" class="btn-small">➕ Soru Ekle</button>
                </div>
            </div>

            <!-- Section 3: Thresholds (if sum <= value -> output) -->
            <div class="form-row">
                <label><strong>Eşikler (Eğer değerin altındaysa veya eşitse)</strong></label>
                <?php foreach ($state['thresholds'] as $i => $t): $idx = $i+1; ?>
                    <div style="display:flex; gap:8px; margin-bottom:8px;">
                        <input type="text" name="th_value_<?= $idx ?>" value="<?= htmlspecialchars($t['value']) ?>" placeholder="Değer" style="width:100px;">
                        <input type="text" name="th_out_<?= $idx ?>" value="<?= htmlspecialchars($t['out']) ?>" placeholder="Çıktı metni" style="flex:1;">
                    </div>
                <?php endforeach; ?>
                <div style="margin-top:8px;">
                    <button type="submit" name="add_threshold" class="btn-small">➕ Ekle</button>
                </div>
            </div>

            <div class="form-row">
                <!-- Sharing is automatic: profile post (or group+profile when opened from a group) -->
                <?php if (!empty($state['group_slug'])): ?>
                    <input type="hidden" name="group_slug" value="<?= htmlspecialchars($state['group_slug']) ?>">
                    <div class="muted small">Bu Tahlil <strong>grup</strong> olarak paylaşılacak: <em><?= htmlspecialchars($state['group_slug']) ?></em> (otomatik)</div>
                <?php endif; ?>

                <?php if (!empty($editing_id)): ?>
                <input type="hidden" name="edit_test_id" value="<?= (int)$editing_id ?>">
                <button type="submit" name="create_test" class="btn-post">Tahlili Güncelle</button>
                <?php $cancel_url = BASE_PATH . '/tahlil/olustur'; if (!empty($editing_id)) $cancel_url = BASE_PATH . '/tahlil/duzenle/' . (int)$editing_id; ?>
                <a href="<?= $cancel_url ?>" class="btn-outline">İptal</a>
            <?php else: ?>
                <button type="submit" name="create_test" class="btn-post">Tahlil Oluştur</button>
            <?php endif; ?>
            </div>
        </form>

        <?php if (isset($_SESSION['test_anket_final'])): $final = $_SESSION['test_anket_final']; ?>
            <div class="card-box padded" style="margin-top:18px;">
                <h3>Tahlili Dene</h3>
                <p><strong><?= htmlspecialchars($final['title'] ?? '') ?></strong></p>
                <form method="POST">
                    <?php foreach (($final['questions'] ?? []) as $qi => $q): ?>
                        <div style="margin-bottom:12px;">
                            <div><strong><?= htmlspecialchars($q['question_text'] ?? '') ?></strong></div>
                            <?php foreach (($q['options'] ?? []) as $oi => $opt): ?>
                                <?php $val = isset($opt['id']) ? $opt['id'] : $oi; ?>
                                <div style="margin-bottom:6px;">
                                    <label><input type="radio" name="q_<?= $qi ?>" value="<?= $val ?>" required> <?= htmlspecialchars($opt['label'] ?? '') ?> <span class="muted">(<?= (int)($opt['points'] ?? 0) ?> Puan)</span></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div style="margin-top:8px;">
                        <button type="submit" name="take_test" class="btn-post">Tahlili Çalıştır</button>
                    </div>
                </form>

                <?php if (!empty($_SESSION['test_result'])): $r = $_SESSION['test_result']; ?>
                    <div style="margin-top:12px;" class="form-alert form-alert-success">Toplam Puan: <strong><?= intval($r['sum']) ?></strong> — <?= htmlspecialchars($r['out']) ?></div>
                    <?php unset($_SESSION['test_result']); endif; ?>
            </div>
        <?php endif; ?>
        </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php';
