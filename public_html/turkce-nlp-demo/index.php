<?php /* EN + TR comments used. */
// Simple standalone page - no dependencies on site framework
// This avoids database connection issues and routing conflicts

require_once __DIR__ . '/grammar_helper.php';

// Set up BASE_PATH
if (!defined('BASE_PATH')) {
    define('BASE_PATH', '');
}

$META_TITLE = 'Türkçe NLP Demo';
$META_DESCRIPTION = 'Türkçe metin düzeltme önerileri sunan bir demo. Zemberek tabanlı NLP ile yazım ve biçim hatalarını düzeltin.';

$content = '';
$suggestion = '';
$error = '';
$highlightedOriginal = '';
$highlightedSuggestion = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('ini_set')) {
        @ini_set('memory_limit', '256M');
        @ini_set('max_execution_time', '180');
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }

    $content = trim((string)($_POST['content'] ?? ''));
    if ($content === '') {
        $error = 'Lütfen önce düzeltilecek bir gönderi girin.';
    } else {
        try {
            $result = suggest_grammar_suggestion($content);
            if (isset($result['error'])) {
                $error = $result['error'];
            } else {
                $suggestion = $result['suggestion'];
                try {
                    $highlighted = highlight_grammar_changes($content, $suggestion);
                    $highlightedOriginal = $highlighted['original'];
                    $highlightedSuggestion = $highlighted['suggestion'];
                } catch (Throwable $highlightError) {
                    error_log('grammar highlight failed: ' . $highlightError->getMessage());
                    $highlightedOriginal = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
                    $highlightedSuggestion = htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8');
                }
            }
        } catch (Throwable $e) {
            error_log('grammar demo failed: ' . $e->getMessage());
            $error = 'İşlem sırasında bir hata oluştu. Lütfen daha kısa bir metin deneyin veya tekrar deneyin.';
        }
    }
}

$csp_nonce = base64_encode(random_bytes(16));
?><!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($META_DESCRIPTION, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($META_TITLE, ENT_QUOTES, 'UTF-8') ?></title>
    <style nonce="<?= $csp_nonce ?>">
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; background: #f5f5f5; color: #222; margin: 0; padding: 0; }
        .nlp-header { background: #fff; border-bottom: 1px solid #ddd; padding: 16px 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .nlp-header .logo { font-size: 24px; font-weight: 700; color: #21572d; text-decoration: none; }
        .nlp-header .logo:hover { color: #5a9a3c; }
        .nlp-container { max-width: 760px; margin: 20px auto; background: #fff; padding: 24px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
        .nlp-container h1 { margin-top: 0; }
        .nlp-container label { display: block; margin-bottom: 8px; font-weight: bold; }
        .nlp-container textarea { width: 100%; min-height: 140px; resize: vertical; padding: 12px; font-size: 1rem; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box; font-family: inherit; }
        .nlp-container button { background: #5a9a3c; border: none; color: #fff; padding: 12px 20px; border-radius: 8px; font-size: 1rem; cursor: pointer; }
        .nlp-container button:hover { background: #1f57d7; }
        .nlp-message { margin: 16px 0; padding: 14px 16px; border-radius: 8px; }
        .nlp-error { background: #ffe6e6; color: #9b1e1e; border: 1px solid #f4b0b0; }
        .nlp-result { background: #eef7ff; color: #12355b; border: 1px solid #b0d3ff; }
        .nlp-side-by-side { display: grid; gap: 16px; grid-template-columns: 1fr; }
        .nlp-box { padding: 16px; background: #fafafa; border: 1px solid #ddd; border-radius: 10px; }
        .nlp-box-title { margin: 0 0 8px; font-size: 0.95rem; color: #444; font-weight: bold; }
        .nlp-box-content { margin: 0; white-space: pre-wrap; word-wrap: break-word; overflow-wrap: anywhere; line-height: 1.55; }
        .nlp-mark { background: #ffe566; color: inherit; padding: 0 0.12em; border-radius: 3px; box-decoration-break: clone; -webkit-box-decoration-break: clone; }
        .nlp-legend { margin: 10px 0 0; color: #555; font-size: 0.92rem; }
        .nlp-legend .nlp-mark { padding: 0 0.35em; }
        @media (min-width: 720px) { .nlp-side-by-side { grid-template-columns: 1fr 1fr; } }
        .nlp-footnote { margin-top: 20px; color: #666; font-size: 0.95rem; }
        .nlp-link { color: #2a6df7; text-decoration: none; }
        .nlp-footer { text-align: center; margin-top: 40px; padding: 20px; color: #666; }
        .nlp-footer a { color: #2a6df7; text-decoration: none; margin: 0 12px; }
        .nlp-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <header class="nlp-header">
        <a href="<?= BASE_PATH ?>/" class="logo">Mevzuat Raporu</a>
    </header>

    <div class="nlp-container">
        <h1>Türkçe NLP Demo</h1>
        <p>Bu demo, gönderi metnini alır ve bir düzeltme önerisi sunar. Önerilen metinde değişen kısımlar sarı ile işaretlenir.</p>

        <?php if ($error !== ''): ?>
            <div class="nlp-message nlp-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST">
            <label for="content">Gönderi metni</label>
            <textarea id="content" name="content" placeholder="Gönderinizi buraya yazın..."><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
            <div style="margin-top:16px;"><button type="submit">Öneri Al</button></div>
        </form>

        <?php if ($suggestion !== ''): ?>
            <div class="nlp-message nlp-result">
                <p><strong>Düzeltme önerisi hazır.</strong></p>
                <p class="nlp-legend">Önerilen bölümde <mark class="nlp-mark">sarı</mark> işaretli metin değişen / düzeltilen kısımlardır.</p>
            </div>
            <div class="nlp-side-by-side">
                <div class="nlp-box">
                    <div class="nlp-box-title">Orijinal</div>
                    <div class="nlp-box-content"><?= $highlightedOriginal ?></div>
                </div>
                <div class="nlp-box">
                    <div class="nlp-box-title">Önerilen</div>
                    <div class="nlp-box-content"><?= $highlightedSuggestion ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="nlp-footnote">
            <p><a href="<?= BASE_PATH ?>/" class="nlp-link">Ana sayfaya dön</a></p>
        </div>
    </div>

    <footer class="nlp-footer">
        <p>&copy; <?= date('Y') ?> Mevzuat Raporu | <a href="<?= BASE_PATH ?>/gizlilik">Gizlilik</a> | <a href="<?= BASE_PATH ?>/kurallar-sartlar">Kurallar</a></p>
    </footer>
</body>
</html>
