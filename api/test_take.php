<?php
/**
 * Handle taking a test (server-side, no JS)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$test_id = isset($_POST['test_id']) ? (int)$_POST['test_id'] : 0;
$referer = $_POST['referer'] ?? $_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/index.php';
$referer = validate_referer($referer, BASE_PATH . '/index.php', false);

if (!$test_id) {
    $_SESSION['flash'] = 'Geçersiz test.';
    header('Location: ' . $referer);
    exit;
}

$test = get_test_by_id($test_id);
if (!$test) {
    $_SESSION['flash'] = 'Test bulunamadı.';
    header('Location: ' . $referer);
    exit;
}

// Gather answers: inputs named q_{question_id}
$answers = [];
foreach ($_POST as $k => $v) {
    if (strpos($k, 'q_') === 0) {
        $qid = (int)substr($k, 2);
        $oid = (int)$v;
        if ($qid > 0 && $oid > 0) $answers[$qid] = $oid;
    }
}

// Require all questions answered for now
$question_count = count($test['questions']);
if ($question_count > 0 && count($answers) !== $question_count) {
    $_SESSION['flash'] = 'Lütfen tüm soruları cevaplayın.';
    header('Location: ' . $referer);
    exit;
}

// Validate option IDs belong to their questions
foreach ($test['questions'] as $q) {
    $qid = (int)$q['id'];
    $valid_opts = array_map(function($o){ return (int)$o['id']; }, $q['options']);
    if (!isset($answers[$qid]) || !in_array((int)$answers[$qid], $valid_opts, true)) {
        $_SESSION['flash'] = 'Geçersiz seçenek gönderildi.';
        header('Location: ' . $referer);
        exit;
    }
}

$res = record_test_attempt($user_id, $test_id, $answers, true);
if (isset($res['error'])) {
    $_SESSION['flash'] = 'Teste katılım başarısız: ' . htmlspecialchars($res['message'] ?? $res['error']);
} else {
    // Store a short result in session so UI can display it inline
    if (!isset($_SESSION['test_results'])) $_SESSION['test_results'] = [];
    $_SESSION['test_results'][$test_id] = ['sum' => $res['sum'], 'out' => $res['out'], 'attempt_id' => $res['attempt_id']];
    $_SESSION['flash'] = 'Test tamamlandı. Sonucunuz: ' . htmlspecialchars($res['out']);
}

header('Location: ' . $referer);
exit;
?>