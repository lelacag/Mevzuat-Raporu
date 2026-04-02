<?php
namespace App\Service;

class SecurityService
{
    public function generateCsrfToken(): string { return generate_csrf_token(); }
    public function verifyCsrfToken(string $token): bool { return verify_csrf_token($token); }
    public function requireCsrf(): void { require_csrf(); }
    public function validateReferer(string $ref, ?string $default = null, bool $admin = false): string { return validate_referer($ref, $default, $admin); }
    public function sanitize(string $input): string { return sanitize_input($input); }
    public function isIpBlocked(string $ip): bool { return (bool) is_ip_blocked($ip); }
}