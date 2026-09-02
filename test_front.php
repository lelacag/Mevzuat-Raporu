<?php
/**
 * Substack-Style Landing Page - NO JS CAPTCHA (Server-Side Only)
 * Save as: substack_demo.php
 */

// Start Session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- 1. SERVER-SIDE CAPTCHA GENERATION (GD Library) ---
function generate_captcha_image($length = 5) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed confusing chars (0,O,1,I)
    $captcha_code = '';
    for ($i = 0; $i < $length; $i++) {
        $captcha_code .= $chars[mt_rand(0, strlen($chars) - 1)];
    }
    
    $_SESSION['captcha_code'] = $captcha_code;
    
    // Create image
    $width = 120;
    $height = 40;
    $image = imagecreatetruecolor($width, $height);
    
    // Background color (light)
    $bg_color = imagecolorallocate($image, 240, 248, 240);
    imagefill($image, 0, 0, $bg_color);
    
    // Text color (dark green)
    $text_color = imagecolorallocate($image, 20, 80, 20);
    
    // Add noise lines for security
    for ($i = 0; $i < 5; $i++) {
        $line_color = imagecolorallocate($image, mt_rand(100, 200), mt_rand(150, 220), mt_rand(100, 200));
        imageline($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), $line_color);
    }
    
    // Add dots for extra noise
    for ($i = 0; $i < 50; $i++) {
        $dot_color = imagecolorallocate($image, mt_rand(150, 230), mt_rand(200, 250), mt_rand(150, 230));
        imagesetpixel($image, mt_rand(0, $width), mt_rand(0, $height), $dot_color);
    }
    
    // Add text
    $x = 10;
    for ($i = 0; $i < $length; $i++) {
        imagechar($image, 5, $x, 10, $captcha_code[$i], $text_color);
        $x += 20;
    }
    
    // Output header and image
    header('Content-type: image/png');
    imagepng($image);
    imagedestroy($image);
    exit;
}

// Handle captcha image request
if (isset($_GET['get_captcha'])) {
    generate_captcha_image();
}

// --- 2. CONFIGURATION & MOCKS ---
define('SITE_NAME', 'Mevzuat Raporu');
define('BASE_PATH', ''); 
define('MIN_PASSWORD_LENGTH', 8);

// Generate CSRF token
function generate_csrf_token() { 
    if(empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf']; 
}
function verify_csrf_token($token) { return isset($_SESSION['csrf']) && $token === $_SESSION['csrf']; }
function sanitize_input($data) { return trim(htmlspecialchars($data)); }
function t($key) { return $key; } 
function privacy_url() { return '#privacy'; }
function rules_url() { return '#rules'; }
function kvkk_url() { return '#kvkk'; }
function profile_url($user) { return '#' . $user; }
function render_rich_text($text) { return nl2br(htmlspecialchars($text)); }
function check_rate_limit() { return true; }
function get_client_ip() { return '192.168.1.1'; }
function get_country_by_ip($ip) { return 'TR'; }
function is_country_open($c) { return true; }

// Mock Posts
$landing_posts = [
    ['username' => 'Ahmet_Y', 'content' => 'Neden artık her şeyi izlemiyoruz? Mevzuat Raporu veri satışı yapmaz.', 'likes' => 142, 'comments' => 28],
    ['username' => 'Ayse_K', 'content' => 'Yerli usulü bir deney. JavaScript olmadan bile çalışan basitlik.', 'likes' => 89, 'comments' => 12],
    ['username' => 'Mehmet_D', 'content' => 'Gürültüden arınmış bir gün. Sadece önemli olan kalacak.', 'likes' => 215, 'comments' => 56]
];

// --- 3. LOGIC HANDLING ---
$errors = [];
$success = false;
$success_message = '';

// Handle Success Flash
if (!empty($_SESSION['register_success'])) {
    $success = true;
    $success_message = $_SESSION['register_success_message'] ?? 'Kayıt başarılı! E-posta adresinizi kontrol edin.';
    unset($_SESSION['register_success']);
}

// Always regenerate captcha after POST (prevents replay attacks)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Generate new captcha regardless of outcome
    generate_new_captcha();
}

function generate_new_captcha() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $captcha_code = '';
    for ($i = 0; $i < 5; $i++) {
        $captcha_code .= $chars[mt_rand(0, strlen($chars) - 1)];
    }
    $_SESSION['captcha_code'] = $captcha_code;
}

// Generate initial captcha
if (empty($_SESSION['captcha_code'])) {
    generate_new_captcha();
}

// Handle POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* CSRF Check */
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = 'Geçersiz istek. Lütfen tekrar deneyin.';
    } else {
        $username = sanitize_input($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $email = mb_strtolower(sanitize_input($_POST['email'] ?? ''));
        $captcha_input = strtoupper(trim($_POST['captcha_input'] ?? '')); // User must enter captcha
        
        // CAPTCHA VALIDATION (Server-Side)
        if (!isset($_SESSION['captcha_code']) || $captcha_input !== $_SESSION['captcha_code']) {
            $errors[] = 'Doğrulama kodu yanlış. Lütfen resimdeki kodu doğru girdiğinizden emin olun.';
        } 
        else {
            // Proceed with other validation
            if (empty($username) || empty($password) || empty($email)) {
                $errors[] = 'Tüm alanlar gereklidir.';
            } elseif (mb_strlen(trim($username), 'UTF-8') < 3) {
                $errors[] = 'Kullanıcı adı en az 3 karakter olmalıdır.';
            } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
                $errors[] = 'Şifre en az ' . MIN_PASSWORD_LENGTH . ' karakter olmalıdır.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Geçersiz e-posta formatı.';
            } else {
                // SUCCESS SIMULATION
                $_SESSION['register_success'] = 1;
                $_SESSION['register_success_message'] = 'Hesabınız oluşturuldu (Demo Modu). Lütfen e-postayı kontrol edin.';
                // Regenerate captcha before redirect
                generate_new_captcha();
                header('Location: ' . BASE_PATH . '/substack_demo.php?registered=1');
                exit;
            }
        }
        // Regenerate captcha after failed attempt too
        generate_new_captcha();
    }
}

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?> - Hoş Geldiniz</title>
    <style>
        :root {
            --brand-green: #2e7d32;
            --brand-light-green: #e8f5e9;
            --text-dark: #1a1a1a;
            --text-grey: #555;
            --bg-white: #ffffff;
            --bg-offwhite: #f9f9f9;
            --border-color: #e0e0e0;
        }

        body {
            font-family: "New York", Georgia, Cambria, "Times New Roman", Times, serif;
            color: var(--text-dark);
            background: var(--bg-white);
            margin: 0;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        a { text-decoration: none; color: inherit; transition: opacity 0.2s; }
        a:hover { opacity: 0.7; }
        
        .container { max-width: 680px; margin: 0 auto; padding: 0 24px; }
        
        /* Header */
        header { padding: 40px 0 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--brand-green); margin-bottom: 60px; }
        .brand { font-size: 28px; font-weight: 700; letter-spacing: -0.02em; color: var(--brand-green); }
        .nav-link { font-size: 15px; color: var(--text-grey); font-weight: 500; }

        /* Signup Hero Box */
        .signup-hero {
            background-color: var(--b