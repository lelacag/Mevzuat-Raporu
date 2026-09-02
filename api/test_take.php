<?php
/**
 * Handle Tahlil / test submission from no-JS form posts.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions_legacy.php';

$user_id = get_current_user_id();
$test_id = isset($_POST['test_id']) ? (int)$_POST['test_id'] : 0;
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/';
$referer = validate_referer($referer, BASE_PATH . '/', false);

if (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    $_SESSION['flash_error'] = 'Geçersiz istek (CSRF).';
    header('Location: ' . $referer);
    exit;
}

if (!$test_id) {
    $_SESSION['flash_error'] = 'Geçersiz Tahlil IDsi.';
    header('Location: ' . $referer);
    exit;
}

$test = get_test_by_id($test_id);
if (!$test) {
    $_SESSION['flash_error'] = 'Tahlil bulunamadı.';
    header('Location: ' . $referer);
    exit;
}

$answers = [];
foreach ($_POST as $key => $value) {
    if (preg_match('/^q_([0-9]+)$/', $key, $matches)) {
        $question_id = (int)$matches[1];
        $option_id = is_numeric($value) ? (int)$value : 0;
        if ($question_id > 0 && $option_id > 0) {
            $answers[$question_id] = $option_id;
        }
    }
}

if (empty($answers)) {
    $_SESSION['flash_error'] = 'Lütfen tüm soruları cevaplayın.';
    header('Location: ' . $referer);
    exit;
}

// Ensure every question has an answer.
foreach ($test['questions'] as $question) {
    if (!isset($answers[(int)$question['id']])) {
        $_SESSION['flash_error'] = 'Lütfen tüm soruları cevaplayın.';
        header('Location: ' . $referer);
        exit;
    }
}

// Calculate the result and optionally store an authenticated attempt.
$sum = 0;
foreach ($test['questions'] as $question) {
    $qid = (int)$question['id'];
    $selected_option_id = $answers[$qid] ?? 0;
    $matched = false;
    foreach ($question['options'] as $option) {
        if ((int)$option['id'] === $selected_option_id) {
            $sum += (int)$option['points'];
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        $_SESSION['flash_error'] = 'Geçersiz seçenek gönderildi.';
        header('Location: ' . $referer);
        exit;
    }
}

$result_out = null;
$last_out = '';
foreach ($test['thresholds'] as $threshold) {
    $last_out = $threshold['out_text'] ?? '';
    $threshold_value = isset($threshold['value']) ? (int)$threshold['value'] : 0;
    if ($sum <= $threshold_value) {
        $result_out = $threshold['out_text'] ?? '';
        break;
    }
}
if ($result_out === null) {
    $result_out = $last_out ?: 'Sonuç bulunamadı.';
}

if ($user_id) {
    try {
        record_test_attempt($user_id, $test_id, $answers, true);
    } catch (Throwable $e) {
        error_log('api/test_take.php record_test_attempt error: ' . $e->getMessage());
    }
}

$_SESSION['test_results'][$test_id] = ['sum' => $sum, 'out' => $result_out];
$_SESSION['flash'] = 'Tahlil sonucu hesaplandı.';
header('Location: ' . $referer);
exit;
