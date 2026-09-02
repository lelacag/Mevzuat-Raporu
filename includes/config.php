<?php /* EN + TR comments used. */
// Load environment file if present.
// Prefer an explicit external file path via ENV_FILE.
// In production, use .env.production by default so live secrets are not kept in the shared repo root.
$dotenvPath = getenv('ENV_FILE');
if (!$dotenvPath) {
    if (getenv('APP_ENV') === 'production') {
        $prodEnvPath = __DIR__ . '/../.env.production';
        $fallbackEnvPath = __DIR__ . '/../.env';
        if (is_readable($prodEnvPath)) {
            $dotenvPath = $prodEnvPath;
        } elseif (is_readable($fallbackEnvPath)) {
            error_log('[ENV] .env.production not found; falling back to .env');
            $dotenvPath = $fallbackEnvPath;
        } else {
            $dotenvPath = $prodEnvPath;
        }
    } else {
        $dotenvPath = __DIR__ . '/../.env';
    }
}
if (is_readable($dotenvPath)) {
    foreach (file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/', $line, $m)) {
            $key = $m[1];
            $value = trim($m[2]);
            // strip optional quotes
            if ((substr($value,0,1)==='"' && substr($value,-1)==='"') || (substr($value,0,1)==="'" && substr($value,-1)==="'")) {
                $value = substr($value, 1, -1);
            }
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
} else {
    error_log('[ENV] No environment file loaded. Expected: ' . $dotenvPath);
}

// Environment detection (set to 'production' on live server)
// environment is controlled via APP_ENV; default to development for safety if not set
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
define('DB_PASS', getenv('DB_PASS') ?: ''); // MUST be set via .env or environment

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
// The built-in PHP web server uses manual router rules; enable clean URLs there too.
if (php_sapi_name() === 'cli-server') {
    $use_clean = true;  // allows /g/{slug} link generation in dev-router mode
}
define('USE_CLEAN_URLS', $use_clean ? true : false);


define('SITE_NAME', getenv('SITE_NAME') ?: 'Mevzuat Raporu');
// Base site URL used for generating absolute URLs in email/embed content.
// Set via env (e.g. SITE_URL=https://www.mevzuatraporu.com). If not set, default to the production host.
// NOTE: We prefer the www host so invitation links include www consistently.
define('SITE_URL', getenv('SITE_URL') ?: 'https://www.mevzuatraporu.com');
// Bad words are managed via the `bad_words` DB table + admin UI (/admin/badwords.php).
// The hardcoded constant below is kept only as a last-resort fallback if the DB is unreachable.
define('BAD_WORDS', []);  // Küfür filtresi — see bad_words table

// Security settings
define('SESSION_LIFETIME', 3600 * 24 * 7); // 7 days
define('SESSION_INACTIVITY_TIMEOUT', 3600 * 24 * 3); // 3 days inactivity logout
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
// Email sending must be explicitly enabled in the environment.
define('MAIL_ENABLED', getenv('MAIL_ENABLED') === 'true');
// sending address; use a verified address on the sending domain
// ideally this is set to a mailbox like no-reply@mevzuatraporu.com or admin@mevzuatraporu.com
define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: 'no-reply@mevzuatraporu.com');
define('MAIL_FROM_NAME', SITE_NAME);

// SMS Module (Optional) - Load if available
if (file_exists(__DIR__ . '/../modules/sms/config.php')) {
    require_once __DIR__ . '/../modules/sms/config.php';
}

// SMTP delivery settings
// Only use SMTP if host+username+password are configured.
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', intval(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: ''); // MUST be set via .env
define('SMTP_PASS', getenv('SMTP_PASS') ?: ''); // MUST be set via .env
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');

// URL session secret used to HMAC URL tokens. In production set via env var.
define('URL_SESSION_SECRET', getenv('URL_SESSION_SECRET') ?: ''); // MUST be set via .env
// Application encryption key used by AES-256-GCM. Base64 of 32 bytes.
define('APP_ENC_KEY', getenv('APP_ENC_KEY') ?: '');
// Application signing key for token signing. Base64 of 32 bytes, or fallback to URL_SESSION_SECRET.
define('APP_SIGN_KEY', getenv('APP_SIGN_KEY') ?: '');
// URL session TTL in seconds (default 1 hour)
define('URL_SESSION_TTL', intval(getenv('URL_SESSION_TTL') ?: 3600));

if (ENVIRONMENT === 'production') {
    if (empty(getenv('URL_SESSION_SECRET'))) {
        error_log('Configuration error: URL_SESSION_SECRET must be set in production.');
        die('Server misconfigured. Missing URL_SESSION_SECRET.');
    }
    if (getenv('DB_PASS') === false || getenv('DB_PASS') === '') {
        error_log('Configuration error: DB_PASS must be set in production.');
        die('Server misconfigured. Missing DB_PASS.');
    }
}

// TPU quota configuration
// Treat TPU request volume as a separate quota so admins can monitor it independently.
define('TPU_REQUEST_LIMIT', intval(getenv('TPU_REQUEST_LIMIT') ?: 100000));
define('TPU_REQUEST_INITIAL_COUNT', intval(getenv('TPU_REQUEST_INITIAL_COUNT') ?: 0));
define('TPU_REQUEST_COUNT_FILE', getenv('TPU_REQUEST_COUNT_FILE') ?: __DIR__ . '/../tmp/TPU_REQUESTS');

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