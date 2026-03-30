Email Notification Setup

1) Basic (PHP mail())
- Edit `includes/config.php` and set `MAIL_ENABLED` to `true` and `MAIL_FROM_EMAIL` to your desired address.
- The app uses `send_email()` which calls PHP `mail()`. On many dev environments/mail servers this is disabled; use SMTP for production.

2) SMTP (recommended)
- Install PHPMailer (composer require phpmailer/phpmailer) or add a lightweight SMTP client.
- Replace `send_email()` in `includes/functions.php` with a proper SMTP implementation using PHPMailer and the `SMTP_*` config values.

3) Per-user preferences
- Run the migration `migrations/20260114_add_email_prefs_migration.php` or apply the SQL `migrations/20260114_add_email_prefs_migration.sql` to add email preference columns to `users` table.
- Users can edit their preferences on `profile_edit.php`.

4) Privacy & opt-in
- Respect user choices: only send emails when `notify_by_email` is enabled and the specific notification type is allowed.

5) Testing
- Use a test email address and enable `MAIL_ENABLED` to verify email sending. Check your mail server's logs if messages don't arrive.