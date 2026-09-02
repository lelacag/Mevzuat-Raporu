<?php
/**
 * AES-256-GCM and SHA-512 helpers for application-level encryption.
 *
 * The application should store APP_ENC_KEY as a base64-encoded 32-byte key
 * in the environment or .env file.
 */

function get_app_aes_key(): string
{
    $encoded = getenv('APP_ENC_KEY');
    if ($encoded === false || $encoded === '') {
        throw new RuntimeException('APP_ENC_KEY is not configured');
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException('APP_ENC_KEY must be base64 of 32 raw bytes');
    }
    return $raw;
}

function encrypt_data_gcm(string $plaintext, ?string $raw_key = null): string
{
    if ($raw_key === null) {
        $raw_key = get_app_aes_key();
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $raw_key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('AES-256-GCM encryption failed');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function decrypt_data_gcm(string $payload, ?string $raw_key = null): string
{
    if ($raw_key === null) {
        $raw_key = get_app_aes_key();
    }
    $decoded = base64_decode($payload, true);
    if ($decoded === false || strlen($decoded) < 28) {
        throw new InvalidArgumentException('Invalid encrypted payload');
    }
    $iv = substr($decoded, 0, 12);
    $tag = substr($decoded, 12, 16);
    $ciphertext = substr($decoded, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $raw_key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        throw new RuntimeException('AES-256-GCM decryption failed');
    }
    return $plaintext;
}

function hash_sha512(string $data): string
{
    return hash('sha512', $data);
}

function get_app_sign_key(): string
{
    $encoded = getenv('APP_SIGN_KEY');
    if ($encoded !== false && $encoded !== '') {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('APP_SIGN_KEY must be base64 of 32 raw bytes');
        }
        return $raw;
    }

    $fallback = getenv('URL_SESSION_SECRET');
    if ($fallback === false || $fallback === '') {
        throw new RuntimeException('Signing key not configured');
    }
    return $fallback;
}

function get_app_ed25519_key_raw(string $envName, int $expectedLength): ?string
{
    $encoded = getenv($envName);
    if ($encoded === false || $encoded === '') {
        return null;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) !== $expectedLength) {
        throw new RuntimeException(sprintf('%s must be base64 of %d raw bytes', $envName, $expectedLength));
    }
    return $raw;
}

function has_app_ed25519_support(): bool
{
    return function_exists('sodium_crypto_sign_detached') && function_exists('sodium_crypto_sign_verify_detached');
}

function get_app_ed25519_private_key_raw(): ?string
{
    if (!has_app_ed25519_support()) {
        return null;
    }

    $encoded = getenv('APP_ED25519_PRIVATE_KEY_BASE64');
    if ($encoded === false || $encoded === '') {
        return null;
    }

    $raw = base64_decode($encoded, true);
    if ($raw === false) {
        throw new RuntimeException('APP_ED25519_PRIVATE_KEY_BASE64 is not valid base64');
    }

    if (strlen($raw) === 64) {
        return $raw;
    }

    if (strlen($raw) === 32) {
        $keypair = sodium_crypto_sign_seed_keypair($raw);
        return sodium_crypto_sign_secretkey($keypair);
    }

    throw new RuntimeException('APP_ED25519_PRIVATE_KEY_BASE64 must be base64 of 32 seed bytes or 64 secretkey bytes');
}

function get_app_ed25519_public_key_raw(): ?string
{
    if (!has_app_ed25519_support()) {
        return null;
    }
    $publicLen = defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') ? SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES : 32;
    return get_app_ed25519_key_raw('APP_ED25519_PUBLIC_KEY_BASE64', $publicLen);
}

function app_ed25519_sign(string $payload): string
{
    $private = get_app_ed25519_private_key_raw();
    if ($private === null) {
        throw new RuntimeException('Ed25519 signing is not configured or not supported by this PHP build');
    }
    return sodium_crypto_sign_detached($payload, $private);
}

function app_ed25519_verify(string $payload, string $signature): bool
{
    $public = get_app_ed25519_public_key_raw();
    if ($public === null) {
        return false;
    }
    return sodium_crypto_sign_verify_detached($signature, $payload, $public);
}

function has_app_ed25519_signing(): bool
{
    return get_app_ed25519_private_key_raw() !== null;
}

function has_app_ed25519_verification(): bool
{
    return get_app_ed25519_public_key_raw() !== null;
}

function hmac_sha512(string $data, ?string $raw_key = null): string
{
    if ($raw_key === null) {
        $raw_key = get_app_sign_key();
    }
    if ($raw_key === false || $raw_key === '') {
        throw new RuntimeException('Signing key not configured');
    }
    return hash_hmac('sha512', $data, $raw_key, true);
}

function _app_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _app_base64url_decode(string $data): string
{
    $pad = 4 - (strlen($data) % 4);
    if ($pad < 4) {
        $data .= str_repeat('=', $pad);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function app_sign_payload_base64(string $payload): string
{
    if (has_app_ed25519_signing()) {
        return _app_base64url_encode(app_ed25519_sign($payload));
    }
    return _app_base64url_encode(hmac_sha512($payload));
}

function app_verify_payload_base64(string $payload, string $signature_b64): bool
{
    $signature_raw = _app_base64url_decode($signature_b64);
    if ($signature_raw === false) {
        return false;
    }
    if (has_app_ed25519_verification()) {
        return app_ed25519_verify($payload, $signature_raw);
    }
    return hash_equals(hmac_sha512($payload), $signature_raw);
}

function app_sign_data_base64url(string $data): string
{
    return app_sign_payload_base64($data);
}

function app_verify_data_base64url(string $data, string $sig_b64): bool
{
    return app_verify_payload_base64($data, $sig_b64);
}
