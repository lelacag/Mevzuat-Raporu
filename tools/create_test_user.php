<?php
// CLI helper: create a test user quickly. Usage: php tools/create_test_user.php username password email
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (PHP_SAPI !== 'cli') {
    echo "This script is CLI-only.\n";
    exit(1);
}

if ($argc < 4) {
    echo "Usage: php tools/create_test_user.php username password email\n";
    exit(1);
}

$username = $argv[1];
$password = $argv[2];
$email = $argv[3];

try {
    // basic sanitise
    $username = trim($username);
    $email = trim($email);

    // Check existing
    $stmt = query("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $email]);
    if ($stmt->fetch()) {
        echo "User or email already exists.\n";
        exit(1);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $verification_token = bin2hex(random_bytes(16));
    $verification_expiry = date('Y-m-d H:i:s', strtotime('+1 day'));

    query(
        "INSERT INTO users (username, password_hash, email, email_verified, is_approved, verification_token, verification_token_expiry, accepted_terms, accepted_privacy, accepted_kvkk, accepted_terms_at)
         VALUES (?, ?, ?, 1, 1, ?, ?, 1, 1, 1, NOW())",
        [$username, $hash, $email, $verification_token, $verification_expiry]
    );

    $newId = insert_id();
    if ($newId) {
        if (function_exists('ensure_user_slug_column')) {
            ensure_user_slug_column();
        }
        if (function_exists('update_user_slug')) {
            update_user_slug($newId, $username);
        }
        echo "Created user id={$newId} username={$username} email={$email}\n";
        exit(0);
    } else {
        echo "Insert failed.\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
