<?php /* EN + TR comments used. */
/**
 * Cookie notice POST handler - must run before any output is sent.
 */
if (php_sapi_name() !== 'cli') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Accept the cookie notice (set persistent cookie)
        if (isset($_POST['cookie_notice_accept'])) {
            $secure = is_request_https();
            $cookie_options = [
                'expires' => time() + (365 * 24 * 60 * 60),
                'path' => '/',
                'samesite' => 'Lax',
                'httponly' => true,
                'secure' => $secure,
            ];

            setcookie('cookie_notice_accepted', '1', $cookie_options);

            // Redirect back to avoid re-POST and ensure browser applies cookie
            $redirect = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: ' . $redirect);
            exit;
        }

        // Reject the cookie notice: prefer to persist the user's choice when possible
        if (isset($_POST['cookie_notice_reject'])) {
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            // If the user is logged in or cookies appear available, set a small persistent cookie to suppress the banner
            $secure = is_request_https();
            $cookie_options = [
                'expires' => time() + (365 * 24 * 60 * 60),
                'path' => '/',
                'samesite' => 'Lax',
                'httponly' => true,
                'secure' => $secure,
            ];

            $logged_in = function_exists('get_current_user_id') && get_current_user_id();
            // If we can set cookies (user logged in or cookies present), set a value to indicate the notice was dismissed
            if ($logged_in || !empty($_COOKIE)) {
                // store a 'dismissed' marker
                setcookie('cookie_notice_accepted', '0', $cookie_options);
            }

            // Remove any existing reject_cookies param then append
            $uri = preg_replace('/([?&])reject_cookies=[^&]*(&?)/', '\1', $uri);
            // Clean up possible trailing ? or &
            $uri = rtrim($uri, '?&');
            $redirect = $uri . (strpos($uri, '?') === false ? '?reject_cookies=1' : '&reject_cookies=1');
            header('Location: ' . $redirect);
            exit;
        }
    }
}
