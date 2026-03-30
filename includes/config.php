<?php /* EN + TR comments used. */
// Environment detection (set to 'production' on live server)
// environment is controlled via APP_ENV; default to production for safety if not set
// For local development we prefer non-secure cookies and more verbose errors
// set ENVIRONMENT to 'development' manually or via the APP_ENV environment variable.
define('ENVIRONMENT', getenv('APP_ENV') ?: 'development');

// Error reporting based on environment
if (ENVIRONMENT === 'production') {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// Ensure PHP uses a consistent timezone. Can be overridden with APP_TIMEZONE env var.
ini_set('date.timezone', getenv('APP_TIMEZONE') ?: 'Europe/Istanbul');
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/Istanbul');

// Register shutdown handler to catch fatal errors and log them for debugging
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR])) {
        $msg = '[SHUTDOWN] Fatal error: ' . ($err['message'] ?? '') . ' in ' . ($err['file'] ?? '') . ' on line ' . ($err['line'] ?? 0);
        error_log($msg);
    }
});

// Attempt to guarantee a writable temporary directory for file uploads.  In some environments
// php.ini may not set upload_tmp_dir (or the directory may be missing), which previously caused
// "unable to create a temporary file" errors when admins tried to upload bad‑words lists.
//
// Create /srv/www/mevzuatraporu/tmp as root and make sure the web user owns it; then override
// the CLI/ini setting if necessary.  This check runs early so any later file operations can use it.
$phpTmp = ini_get('upload_tmp_dir');
if (empty($phpTmp) || !is_dir($phpTmp) || !is_writable($phpTmp)) {
    $fallbackTmp = __DIR__ . '/../tmp';
    if (!is_dir($fallbackTmp)) {
        @mkdir($fallbackTmp, 0700, true);
        @chown($fallbackTmp, 'wwwrun');
        @chgrp($fallbackTmp, 'www');
    }
    if (is_dir($fallbackTmp) && is_writable($fallbackTmp)) {
        ini_set('upload_tmp_dir', $fallbackTmp);
    }
}

// DB Config
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1'); // use TCP instead of socket
// database created and user/app credentials below
// update these values if you change the database or user
// they also respect environment variables if you prefer

define('DB_NAME', getenv('DB_NAME') ?: 'textsocialmedia');
define('DB_USER', getenv('DB_USER') ?: 'appuser');
define('DB_PASS', getenv('DB_PASS') ?: 'R9eYaf67vZEyIMDQYPyvO3EqniunRqjF');

// Constants
define('MAX_POST_LENGTH', 500);
// BASE_PATH: the URL path where the app is hosted (e.g. '/textsocialmedia').
// On this development box we serve the app at the webserver root
// (no subdirectory), so use an empty string.  You can override via
// the BASE_PATH environment variable if needed.
define('BASE_PATH', getenv('BASE_PATH') ?: '');

// Enable clean URLs by default in production when Apache/Nginx rewrite is available
// can be toggled with USE_CLEAN_URLS env var for testing or legacy installs
$use_clean = (getenv('USE_CLEAN_URLS') === '1');
if (ENVIRONMENT === 'production') {
    $use_clean = true;
}
// the built-in PHP web server doesn't support rewrites
if (php_sapi_name() === 'cli-server') {
    $use_clean = false;
}
define('USE_CLEAN_URLS', $use_clean ? true : false);


define('SITE_NAME', getenv('SITE_NAME') ?: 'Mevzuat Raporu');
// Base site URL used for generating absolute URLs in email/embed content.
// Set via env (e.g. SITE_URL=https://www.mevzuatraporu.com). If not set, default to the production host.
// NOTE: We prefer the www host so invitation links include www consistently.
define('SITE_URL', getenv('SITE_URL') ?: 'https://www.mevzuatraporu.com');
define('BAD_WORDS', ['küfür1', 'küfür2', 'nefret1']);  // Küfür filtresi listesi

// Security settings
define('SESSION_LIFETIME', 3600 * 24 * 7); // 7 days
define('MAX_LOGIN_ATTEMPTS', 5); // Max failed login attempts
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes lockout
define('MAX_REQUESTS_PER_MINUTE', 60); // Rate limiting

// Password policy
define('MIN_PASSWORD_LENGTH', 8);
define('REQUIRE_PASSWORD_COMPLEXITY', true); // At least one number and one letter

// Email settings
// For development we can force mailing on; in production override with env var
// use MAIL_ENABLED=true to enable real sending.
// set MAIL_ENABLED=false in environment to disable email sending in staging/test.
// NOTE: Temporarily forcing MAIL_ENABLED to true to allow registration flow while
// environment variables are configured. Revert this change and enable via env
// (MAIL_ENABLED=true) for production deployments.
define('MAIL_ENABLED', true); // default enabled temporarily
// sending address
define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: 'admin@mevzuatraporu.com');
define('MAIL_FROM_NAME', SITE_NAME);

// SMS Module (Optional) - Load if available
if (file_exists(__DIR__ . '/../modules/sms/config.php')) {
    require_once __DIR__ . '/../modules/sms/config.php';
}

// SMTP delivery via Gmail
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', intval(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: 'mevzuatraporu@gmail.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'cdtz mvor bsgh lkuc');
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');

// URL session secret used to HMAC URL tokens. In production set via env var.
define('URL_SESSION_SECRET', getenv('URL_SESSION_SECRET') ?: 'dev-change-this-secret');
// URL session TTL in seconds (default 1 hour)
define('URL_SESSION_TTL', intval(getenv('URL_SESSION_TTL') ?: 3600));

// Ngrok API token (used for fetching session count)
// Set via environment variable NGROK_API_TOKEN or by editing this file.
define('NGROK_API_TOKEN', getenv('NGROK_API_TOKEN') ?: '3BAFfEGiwLVpN5IO68iTEtKxS45_2rXBoh7r64QCJfUYtFRic');

// CAPTCHA debug flag (disabled by default). Enable only briefly for diagnostics by setting env var or changing this value.
define('CAPTCHA_DEBUG', false);

// CAPTCHA settings
// Minimum seconds a human user should wait before submitting the CAPTCHA (prevent too-quick bots)
// Tuned to 10s for stricter anti-bot protection while keeping UX acceptable for humans.
define('CAPTCHA_MIN_SECONDS', intval(getenv('CAPTCHA_MIN_SECONDS') ?: 10));
// Max incorrect attempts per token
// Reduced to 2 to limit brute-force attempts per token.
define('CAPTCHA_MAX_ATTEMPTS', intval(getenv('CAPTCHA_MAX_ATTEMPTS') ?: 2));
// Time-to-live for DB-backed captcha entries (seconds)
define('CAPTCHA_STORE_TTL', intval(getenv('CAPTCHA_STORE_TTL') ?: 300));// Token generation rate limits (per IP)
define('CAPTCHA_GENERATION_LIMIT', intval(getenv('CAPTCHA_GENERATION_LIMIT') ?: 30));
define('CAPTCHA_GENERATION_WINDOW', intval(getenv('CAPTCHA_GENERATION_WINDOW') ?: 300));

// Rookie post thresholds
// Number of posts a rookie is allowed to publish automatically before moderation
define('ROOKIE_AUTO_APPROVE_POST_COUNT', intval(getenv('ROOKIE_AUTO_APPROVE_POST_COUNT') ?: 10));
// Allow rookies to delete their own posts (non-premium) without restriction
define('ROOKIE_ALLOW_SELF_DELETE', (getenv('ROOKIE_ALLOW_SELF_DELETE') === '1') ? true : true);

// SIGNUP REQUESTS / GEO-OPEN SETTINGS
// Rolling window (days) to count verified requests per country
define('REQUESTS_COUNT_WINDOW_DAYS', intval(getenv('REQUESTS_COUNT_WINDOW_DAYS') ?: 30));
// Number of verified requests in the rolling window required to auto-open a country
define('REQUESTS_AUTO_OPEN_THRESHOLD', intval(getenv('REQUESTS_AUTO_OPEN_THRESHOLD') ?: 50));
// Whether to auto-open countries when threshold is reached (false = admin review)
define('REQUESTS_AUTO_OPEN', (getenv('REQUESTS_AUTO_OPEN') === 'true') ? true : false);
// Rate-limits
define('REQUESTS_MAX_PER_IP_PER_DAY', intval(getenv('REQUESTS_MAX_PER_IP_PER_DAY') ?: 3));
define('REQUESTS_MAX_PER_EMAIL_WINDOW_DAYS', intval(getenv('REQUESTS_MAX_PER_EMAIL_WINDOW_DAYS') ?: 30));
// Request verification token expiry in hours
define('REQUEST_TOKEN_EXPIRY_HOURS', intval(getenv('REQUEST_TOKEN_EXPIRY_HOURS') ?: 72));
// Purge requests older than this many days
define('REQUESTS_PURGE_DAYS', intval(getenv('REQUESTS_PURGE_DAYS') ?: 90));