<?php
namespace App\Service;

class NotificationService
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function create(int $userId, string $message, ?string $link = null): int { create_notification($userId, $message, $link); return (int) $this->pdo->lastInsertId(); }
    public function markRead(int $notificationId): bool { return (bool) mark_notification_read($notificationId); }
    public function getForUser(int $userId, int $limit = 50): array { return get_notifications_for_user($userId, $limit); }
    public function getUnreadCount(int $userId): int { return function_exists('get_unread_notification_count') ? (int) get_unread_notification_count($userId) : 0; }
}