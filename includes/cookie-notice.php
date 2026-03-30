<?php /* EN + TR comments used. */
/**
 * Cookie Compliance Notice
 * Simple GDPR-compliant cookie information banner
 */

// Check if user has already accepted/dismissed the notice
// Also suppress the notice for URL-session users (they intentionally rejected cookies)
$cookie_accepted = isset($_COOKIE['cookie_notice_accepted']) || (!empty($GLOBALS['url_session_user_id']));

if (!$cookie_accepted):
?>
<div id="cookie-notice" class="cookie-notice">
    <div class="cookie-notice-content">
        <p class="cookie-notice-text">
            Bu site, temel işlevsellik için çerezler kullanır. 
            <a href="<?= cookie_policy_url() ?>" class="cookie-notice-link">Daha fazla bilgi</a>
        </p>
        <div class="cookie-notice-actions">
            <form method="POST" class="cookie-notice-action-form">
                <input type="hidden" name="cookie_notice_accept" value="1">
                <button type="submit" class="cookie-notice-btn">Kabul ediyorum</button>
            </form>
            <form method="POST" class="cookie-notice-action-form">
                <input type="hidden" name="cookie_notice_reject" value="1">
                <button type="submit" class="cookie-notice-btn cookie-reject">Çerezleri reddet</button>
            </form>
        </div>
    </div>
</div>
<?php 
endif;
?>
