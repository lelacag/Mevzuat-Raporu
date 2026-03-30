<?php /* EN + TR comments used. */
require_once __DIR__ . '/../includes/header.php';
if (!is_admin()) {
    http_response_code(403);
    echo "<div class=\"main-container\"><main class=\"content-area\"><div class=\"card-box padded\">Erişim reddedildi.</div></main></div>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$php_tests = [
    __DIR__ . '/../scripts/smoke_test_clean_urls.php',
    __DIR__ . '/../scripts/test_tag_unicode.php',
    __DIR__ . '/../scripts/test_create_url_session.php'
];
$results = [];
foreach ($php_tests as $t) {
    if (!file_exists($t)) { $results[] = [basename($t), false, 'file missing']; continue; }
    ob_start();
    try {
        include $t;
        $out = ob_get_clean();
        $results[] = [basename($t), true, $out];
    } catch (Throwable $e) {
        $out = ob_get_clean();
        error_log('run_smoke_tests: ' . $e->getMessage());
        $results[] = [basename($t), false, $out . '\nException: ' . $e->getMessage()];
    }
}
?>
<div class="main-container">
    <main class="content-area narrow">
        <div class="card-box padded">
            <h1>Smoke Tests (Admin)</h1>
            <?php foreach ($results as $r): ?>
                <h3><?= htmlspecialchars($r[0]) ?> - <?= $r[1] ? '<span style="color:green">OK</span>' : '<span style="color:red">FAIL</span>' ?></h3>
                <pre style="white-space:pre-wrap; background:#f8f8f8; padding:8px; border:1px solid #eee;"><?= htmlspecialchars($r[2]) ?></pre>
            <?php endforeach; ?>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php';
