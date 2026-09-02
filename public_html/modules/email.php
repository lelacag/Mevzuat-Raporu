<?php
/**
 * Module: email.php — Email sending via SMTP/PHPMailer or mail() fallback
 */

if (!function_exists('is_html_email_body')) {
function is_html_email_body(string $body): bool {
    return preg_match('/<\s*(html|body|div|table|tr|td|span|p|a|strong|em|img|style|br|h1|h2|h3)[\s>]/i', $body) === 1;
}
}

if (!function_exists('get_email_public_base_url')) {
function get_email_public_base_url(): string {
    $site_url = '';
    if (defined('SITE_URL') && SITE_URL !== '') {
        $site_url = rtrim((string) SITE_URL, '/');
    } elseif (($env_site_url = getenv('SITE_URL')) !== false && $env_site_url !== '') {
        $site_url = rtrim($env_site_url, '/');
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http';
        $site_url = $scheme . '://' . $_SERVER['HTTP_HOST'];
    }

    $base_path = '';
    if (defined('BASE_PATH') && BASE_PATH !== '') {
        $base_path = trim((string) BASE_PATH, '/');
    } elseif (($env_base_path = getenv('BASE_PATH')) !== false && $env_base_path !== '') {
        $base_path = trim($env_base_path, '/');
    }

    if ($site_url !== '' && $base_path !== '') {
        $site_url .= '/' . $base_path;
    }

    return $site_url;
}
}

if (!function_exists('get_email_logo_path')) {
function get_email_logo_path(): string {
    $png_logo_path = __DIR__ . '/../assets/logo-green2.png';
    if (is_file($png_logo_path) && is_readable($png_logo_path)) {
        return $png_logo_path;
    }
    return '';
}
}

if (!function_exists('get_email_logo_url')) {
/**
 * Absolute HTTPS URL for the logo.
 * Prefer this over data: URIs — Gmail/Outlook/Hotmail strip data: images.
 */
function get_email_logo_url(): string {
    $base = get_email_public_base_url();
    if ($base !== '') {
        return $base . '/assets/logo-green2.png';
    }
    return 'https://www.mevzuatraporu.com/assets/logo-green2.png';
}
}

if (!function_exists('get_email_logo_data_url')) {
/**
 * Backward-compatible name used by templates as {{logo_data_url}}.
 * Returns a public absolute URL (not a data: URI).
 */
function get_email_logo_data_url(): string {
    return get_email_logo_url();
}
}

if (!function_exists('get_email_logo_cid')) {
function get_email_logo_cid(): string {
    return 'mevzuat-logo';
}
}

if (!function_exists('embed_email_logo')) {
/**
 * Swap logo src to a CID reference and attach the PNG inline.
 * Works reliably in Gmail, Outlook, and Hotmail (data: URIs do not).
 *
 * @param object $mail PHPMailer instance
 */
function embed_email_logo($mail, string $html): string {
    $logo_path = get_email_logo_path();
    if ($logo_path === '' || !is_object($mail) || !method_exists($mail, 'addEmbeddedImage')) {
        return $html;
    }

    $cid = get_email_logo_cid();
    $cid_src = 'cid:' . $cid;
    $logo_url = get_email_logo_url();

    // Replace absolute logo URLs (raw + HTML-escaped).
    $html = str_replace(
        [$logo_url, htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8')],
        [$cid_src, $cid_src],
        $html
    );

    // Rewrite legacy data:image/...;base64,... logos (blocked by Gmail/Hotmail).
    $html = preg_replace(
        '/src=(["\'])data:image\/(?:png|svg\+xml|jpeg|gif);base64,[A-Za-z0-9+\/=]+\\1/i',
        'src=$1' . $cid_src . '$1',
        $html
    ) ?? $html;

    // Catch any remaining logo-green2.png references.
    if (strpos($html, $cid_src) === false && strpos($html, 'logo-green2.png') !== false) {
        $html = preg_replace(
            '/src=(["\'])[^"\']*logo-green2\.png[^"\']*\\1/i',
            'src=$1' . $cid_src . '$1',
            $html
        ) ?? $html;
    }

    // Attach once per PHPMailer instance.
    static $attached_for = [];
    $object_id = spl_object_id($mail);
    if (empty($attached_for[$object_id])) {
        $mail->addEmbeddedImage(
            $logo_path,
            $cid,
            'logo-green2.png',
            'base64',
            'image/png'
        );
        $attached_for[$object_id] = true;
    }

    return $html;
}
}

if (!function_exists('render_email_template')) {
function render_email_template(string $template_name, array $vars = []): string {
    if (!isset($vars['logo_data_url'])) {
        // Public absolute URL for non-SMTP/mail() path; PHPMailer rewrites to cid.
        $vars['logo_data_url'] = get_email_logo_url();
    }
    if (!isset($vars['logo_url'])) {
        $vars['logo_url'] = get_email_logo_url();
    }
    $template_path = __DIR__ . '/../templates/email/' . $template_name;
    if (!is_file($template_path)) {
        return '';
    }
    $content = file_get_contents($template_path);
    foreach ($vars as $key => $value) {
        $content = str_replace('{{' . $key . '}}', (string) $value, $content);
    }
    return $content;
}
}

if (!function_exists('render_standard_email')) {
function render_standard_email(string $subject, string $body_text): string {
    $site_url = defined('SITE_URL') ? rtrim(SITE_URL, '/') . BASE_PATH : BASE_PATH;
    $body_html = htmlspecialchars($body_text, ENT_QUOTES, 'UTF-8');
    $body_html = nl2br($body_html);
    return render_email_template('standard_email.html', [
        'subject' => htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'),
        'site_name' => htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'),
        'site_url' => htmlspecialchars($site_url, ENT_QUOTES, 'UTF-8'),
        'support_email' => defined('MAIL_FROM_EMAIL') ? htmlspecialchars(MAIL_FROM_EMAIL, ENT_QUOTES, 'UTF-8') : 'support@mevzuatraporu.com',
        'body_html' => $body_html,
    ]);
}
}

if (!function_exists('send_email')) {
function send_email($to, $subject, $body) {
    $from_email = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'no-reply@example.com';
    $from_name = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : SITE_NAME;

    $smtp_host = defined('SMTP_HOST') ? SMTP_HOST : getenv('SMTP_HOST');
    $smtp_port = defined('SMTP_PORT') ? SMTP_PORT : intval(getenv('SMTP_PORT') ?: 587);
    $smtp_user = defined('SMTP_USER') ? SMTP_USER : getenv('SMTP_USER');
    $smtp_pass = defined('SMTP_PASS') ? SMTP_PASS : getenv('SMTP_PASS');
    $smtp_secure = defined('SMTP_SECURE') ? SMTP_SECURE : (getenv('SMTP_SECURE') ?: 'tls');

    if (empty($smtp_host) && !empty($smtp_user) && !empty($smtp_pass)) {
        $smtp_host = 'smtp.gmail.com';
    }

    $smtpConfigured = !empty($smtp_host) && !empty($smtp_user) && !empty($smtp_pass);
    error_log(sprintf('[MAIL] send_email(%s) smtp_host=%s smtp_user=%s smtp_pass=%s phpmailer=%s',
        $to, $smtp_host ?: 'NONE', $smtp_user ? 'SET' : 'NONE', $smtp_pass ? 'SET' : 'NONE',
        class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'yes' : 'no'));

    // Load PHPMailer if available
    $phpmailer_dir = __DIR__ . '/../vendor/phpmailer/src';
    if (is_dir($phpmailer_dir)) {
        require_once $phpmailer_dir . '/Exception.php';
        require_once $phpmailer_dir . '/PHPMailer.php';
        require_once $phpmailer_dir . '/SMTP.php';
    }

    if ($smtpConfigured) {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log('[MAIL] SMTP configured but PHPMailer not available; cannot send via SMTP for ' . $to);
            @file_put_contents('/tmp/mail_debug.log', date('c') . " SMTP configured but PHPMailer missing for $to\n", FILE_APPEND);
            return false;
        }

        error_log('[MAIL] Using PHPMailer via SMTP host ' . $smtp_host . ' for ' . $to);
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->Port = $smtp_port;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
            $mail->SMTPSecure = $smtp_secure;
            $mail->CharSet = 'UTF-8';
            $from_address = !empty($smtp_user) ? $smtp_user : $from_email;
            $mail->setFrom($from_address, $from_name);
            if (!empty($from_email) && $from_address !== $from_email) {
                $mail->addReplyTo($from_email, $from_name);
            }
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            if (is_html_email_body($body)) {
                $html_body = $body;
                $mail->AltBody = strip_tags(preg_replace('/<a[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/i', '$2 ($1)', $body));
            } else {
                $html_body = render_standard_email($subject, $body);
                $mail->AltBody = $body;
            }
            // Gmail/Hotmail/Outlook strip data: URIs; embed logo as inline CID attachment.
            $mail->Body = embed_email_logo($mail, $html_body);
            if ($mail->send()) {
                error_log('[MAIL] PHPMailer send ok for ' . $to);
                @file_put_contents('/tmp/mail_debug.log', date('c') . " PHPMailer ok to $to\n", FILE_APPEND);
                return true;
            }
            error_log('[MAIL] PHPMailer send failed for ' . $to);
            @file_put_contents('/tmp/mail_debug.log', date('c') . " PHPMailer failed to $to\n", FILE_APPEND);
        } catch (Exception $e) {
            error_log('[MAIL] PHPMailer error: ' . $e->getMessage());
            @file_put_contents('/tmp/mail_debug.log', date('c') . " PHPMailer error to $to: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        return false;
    }

    error_log('[MAIL] Falling back to mail() for ' . $to);
    @file_put_contents('/tmp/mail_debug.log', date('c') . " mail() fallback for $to\n", FILE_APPEND);
    $headers = "From: " . $from_name . " <" . $from_email . ">\r\n";
    if (!empty($from_email) && $from_email !== $from_address) {
        $headers .= "Reply-To: " . $from_email . "\r\n";
    }
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . " (Mevzuat Raporu)\r\n";
    $headers .= "Date: " . date(DATE_RFC2822) . "\r\n";
    $headers .= "Message-ID: <" . substr(bin2hex(random_bytes(16)), 0, 16) . "@" . preg_replace('/[^A-Za-z0-9\.\-]/', 'mail', parse_url($from_email, PHP_URL_HOST) ?: 'mevzuatraporu.com') . ">\r\n";
    if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
        $mail_body = is_html_email_body($body) ? $body : render_standard_email($subject, $body);
        $additional_params = '';
        if (!empty($from_email)) {
            $additional_params = '-f' . escapeshellarg($from_email);
        }
        $sent = mail($to, $subject, $mail_body, $headers, $additional_params);
        @file_put_contents('/tmp/mail_debug.log', date('c') . " mail() to $to " . ($sent ? 'OK' : 'FAILED') . "\n", FILE_APPEND);
        return $sent;
    } else {
        error_log('[MAIL] Mail disabled (MAIL_ENABLED=false) - not sending to ' . $to);
        @file_put_contents('/tmp/mail_debug.log', date('c') . " mail disabled for $to\n", FILE_APPEND);
        return false;
    }
}
} // end guard

/**
 * Convert a plain-text email body to safe HTML with clickable links.
 * Escapes all HTML, converts URLs to <a> tags, then applies nl2br.
 */
if (!function_exists('email_body_to_html')) {
function email_body_to_html(string $body): string {
    if (is_html_email_body($body)) {
        return $body;
    }
    if (function_exists('render_rich_text')) {
        return render_rich_text($body);
    }

    // First escape everything
    $safe = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
    // Then convert URLs into clickable <a> tags (already-escaped text)
    $safe = preg_replace(
        '~(https?://[^\s<>&"]+(?:&amp;[^\s<>&"]+)*)~i',
        '<a href="$1" style="color:#1e88e5;text-decoration:underline;">$1</a>',
        $safe
    );
    // Fix &amp; back to & inside href attributes only
    $safe = preg_replace_callback(
        '~href="([^"]*)"~',
        function ($m) { return 'href="' . str_replace('&amp;', '&', $m[1]) . '"'; },
        $safe
    );
    return nl2br($safe);
}
} // end guard: email_body_to_html
