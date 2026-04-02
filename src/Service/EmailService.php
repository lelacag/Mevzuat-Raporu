<?php
namespace App\Service;

class EmailService
{
    public function send(string $to, string $subject, string $body): bool { return (bool) send_email($to, $subject, $body); }
    public function sendBatch(array $recipients, string $subject, string $body): int { $s = 0; foreach ($recipients as $to) { if ($this->send($to, $subject, $body)) $s++; } return $s; }
}