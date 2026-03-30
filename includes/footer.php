<?php /* EN + TR comments used. */ ?>
    <!-- Cookie Notice -->
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/cookie-notice.css">
    <?php include __DIR__ . '/cookie-notice.php'; ?>

    <!-- Footer -->
    <footer class="footer-container">
        <div class="footer-content">
            <div class="footer-links">
                <a href="<?= rules_url() ?>"><?= t('rules_and_terms') ?></a>
                <a href="<?= privacy_url() ?>">Gizlilik</a>
                <a href="<?= kvkk_url() ?>">KVKK</a>
                <a href="<?= cookie_policy_url() ?>">Çerezler</a>
                <a href="<?= BASE_PATH ?>/rss.php">RSS</a>
                <?php if (is_admin()): ?>
                    <a href="<?= BASE_PATH ?>/admin/">Yönetim</a>
                <?php endif; ?>
            </div>
            <div class="footer-text">
                &copy; <?= date('Y') ?> <?= SITE_NAME ?> Tüm hakları saklıdır. Bulana çerek altın veriyoruz.🟡
            </div>
        </div>
    </footer>
</body>
</html>

    <?php
    // If we started output buffering in header because of a URL session, rewrite links/forms
    if (!empty($CURRENT_SID) && ob_get_length() !== false) {
        $html = ob_get_clean();
        $sid = htmlspecialchars($CURRENT_SID, ENT_QUOTES, 'UTF-8');

        // Append sid to internal hrefs (skip absolute URLs, mailto, tel, anchors)
        $html = preg_replace_callback('/href="(?!https?:|mailto:|tel:|#)([^"]*)"/i', function($m) use ($sid) {
            $url = $m[1];
            // Preserve fragment
            $frag = '';
            if (strpos($url, '#') !== false) {
                list($url, $frag) = explode('#', $url, 2) + ['', ''];
                $frag = '#' . $frag;
            }
            if (strpos($url, '?') !== false) {
                $new = $url . '&sid=' . $sid . $frag;
            } else {
                $new = $url . '?sid=' . $sid . $frag;
            }
            return 'href="' . $new . '"';
        }, $html);

        // Inject hidden sid input into forms (if not already present) and append sid to internal action URLs
        $html = preg_replace_callback('/<form\b([^>]*)>/i', function($m) use ($sid) {
            $attrs = $m[1];
            // If sid already present as a named input in attributes, skip
            if (stripos($attrs, 'name="sid"') !== false || stripos($attrs, "name='sid'") !== false) {
                return '<form' . $attrs . '>';
            }
            // Rewrite action attribute to include sid for internal actions
            if (preg_match('/action\s*=\s*(["\'])(.*?)\1/i', $attrs, $am)) {
                $quote = $am[1];
                $action = $am[2];
                if (!preg_match('/^(https?:|mailto:|tel:|#)/i', $action)) {
                    $sep = (strpos($action, '?') !== false) ? '&' : '?';
                    $newAction = $action . $sep . 'sid=' . $sid;
                    $attrs = str_replace($am[0], 'action=' . $quote . $newAction . $quote, $attrs);
                }
            } else {
                // No action attribute; add one that preserves sid on submit by using the current path
                $req_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
                $attrs .= ' action="' . $req_path . '?sid=' . $sid . '"';
            }
            return '<form' . $attrs . '><input type="hidden" name="sid" value="' . $sid . '">';
        }, $html);

        // debug_js support removed - server will not list or inject script debug panes in production

        echo $html;
    }

    // debug_js block removed — was dead code (guarded by `if (false)`)
    ?>
