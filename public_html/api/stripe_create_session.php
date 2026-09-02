<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/stripe.php';

$user_id = get_current_user_id();
if (!$user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

if (!is_stripe_configured()) {
    // Redirect back with flash explaining configuration missing
    $_SESSION['flash'] = 'Stripe is not configured on this server. Contact the admin to enable payments.';
    header('Location: ' . BASE_PATH . '/premium.php');
    exit;
}

if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    $_SESSION['flash'] = 'Form doğrulaması başarısız.';
    header('Location: ' . BASE_PATH . '/premium.php');
    exit;
}

$plan = $_POST['plan_type'] ?? 'yearly';
$valid = ['monthly','yearly','lifetime'];
if (!in_array($plan, $valid)) $plan = 'yearly';

// Optional user-provided details (name / email) and password confirmation
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Optional invoice fields
$company = trim($_POST['company'] ?? '');
$tax_id = trim($_POST['tax_id'] ?? '');
$address_line1 = trim($_POST['address_line1'] ?? '');
$address_city = trim($_POST['address_city'] ?? '');
$country = trim($_POST['country'] ?? 'TR');

// Sanitize tax id to digits only (common for VKN/TC)
$tax_id_sanitized = preg_replace('/[^0-9]/', '', $tax_id);

// Ensure email present and valid
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['flash'] = 'Lütfen geçerli bir email adresi girin.';
    header('Location: ' . BASE_PATH . '/premium_payment.php?plan=' . urlencode($plan));
    exit;
}

// If password provided, verify it
if (!empty($password)) {
    $stmt = query("SELECT password_hash FROM users WHERE id = ? LIMIT 1", [$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($password, $row['password_hash'])) {
        $_SESSION['flash'] = 'Parola doğrulaması başarısız.';
        header('Location: ' . BASE_PATH . '/premium_payment.php?plan=' . urlencode($plan));
        exit;
    }
}

// Prepare metadata for Stripe
$meta = ['name' => $name, 'email' => $email];
if (!empty($company)) $meta['company'] = $company;
if (!empty($tax_id_sanitized)) $meta['tax_id'] = $tax_id_sanitized;
if (!empty($address_line1)) $meta['address_line1'] = $address_line1;
if (!empty($address_city)) $meta['address_city'] = $address_city;
if (!empty($country)) $meta['country'] = $country;

// Create session and redirect
$return = BASE_PATH . '/premium.php';
$res = stripe_create_checkout_session($user_id, $plan, $return, $meta);
if ($res['success']) {
    header('Location: ' . $res['url']);
    exit;
} else {
    $_SESSION['flash'] = 'Stripe error: ' . ($res['error'] ?? 'unknown error');
    header('Location: ' . BASE_PATH . '/premium.php');
    exit;
}
