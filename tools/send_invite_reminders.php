<?php
/*
 * Weekly reminder sender for pending invitations.
 * Usage: php tools/send_invite_reminders.php
 */
require_once __DIR__ . '/../includes/functions.php';

try {
    $result = send_weekly_invite_reminders();
    echo "Checked: " . (int)$result['checked'] . " invites; Sent: " . (int)$result['sent'] . " reminders.\n";
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/../logs/php_errors.log', '[' . date('c') . '] ' . $e->getMessage() . "\n", FILE_APPEND);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
