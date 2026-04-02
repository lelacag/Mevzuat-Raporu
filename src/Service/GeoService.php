<?php
namespace App\Service;

class GeoService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function getCountryByIp(string $ip): ?string { return get_country_by_ip($ip) ?: null; }
    public function createSignupRequest(string $email, string $ip, string $cc, string $ua = ''): array|false { return create_signup_request($email, $ip, $cc, $ua); }
    public function verifySignupRequest(string $token): array|false { return verify_signup_request($token); }
    public function isCountryOpen(string $cc): bool { return (bool) is_country_open($cc); }
    public function openCountry(string $cc, ?int $adminId = null, bool $auto = false): bool { return (bool) open_country($cc, $adminId, $auto); }
    public function closeCountry(string $cc, ?int $adminId = null): bool { return (bool) close_country($cc, $adminId); }
    public function notifyCountryOpened(string $cc): void { notify_country_opened($cc); }
    public function notifyAdmins(string $subject, string $body): void { notify_platform_admins($subject, $body); }
    public function autoOpenCheck(): void { auto_open_countries_check(); }
    public function getRequestCountsByCountry(int $limit = 100): array { return function_exists('get_request_counts_by_country') ? get_request_counts_by_country($limit) : []; }
}