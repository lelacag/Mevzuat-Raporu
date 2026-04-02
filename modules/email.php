<?php
/**
 * Module: email.php — Email sending via SMTP/PHPMailer or mail() fallback
 */

if (!function_exists('send_email')) {
function send_email($to, $subject, $body) {
    $from_email = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'no-reply@example.com';
    $from_name = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : SITE_NAME;

    $smtp_host = defined('SMTP_HOST') ? SMTP_HOST : getenv('SMTP_HOST');
    $smtp_port = defined('SMTP_PORT') ? SMTP_PORT : intval(getenv('SMTP_PORT') ?: 587);
    $smtp_user = defined('SMTP_USER') ? SMTP_USER : getenv('SMTP_USER');
    $smtp_pass = defined('SMTP_PASS') ? SMTP_PASS : getenv('SMTP_PASS');
    $smtp_secure = defined('SMTP_SECURE') ? SMTP_SECURE : (getenv('SMTP_SECURE') ?: 'tls');

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

    if (!empty($smtp_host) && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
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
            if ($from_address !== $from_email && !empty($from_email)) {
                $mail->addReplyTo($from_email, $from_name);
            }
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = nl2br(htmlspecialchars($body));
            $mail->AltBody = $body;
            if ($mail->send()) {
                error_log('[MAIL] PHPMailer send ok for ' . $to);
                return true;
            }
            error_log('[MAIL] PHPMailer send failed for ' . $to);
        } catch (Exception $e) {
            error_log('[MAIL] PHPMailer error: ' . $e->getMessage());
        }
        return false;
    }

    error_log('[MAIL] Falling back to mail() for ' . $to);
    $headers = "From: " . $from_name . " <" . $from_email . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
        return mail($to, $subject, nl2br(htmlspecialchars($body)), $headers);
    } else {
        error_log('[MAIL] Mail disabled (MAIL_ENABLED=false) - not sending to ' . $to);
        return false;
    }
}
} // end guard
