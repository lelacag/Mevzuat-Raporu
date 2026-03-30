# Notification Types

This project uses notification records stored in the `notifications` table. The supported notification types currently are:

- like
- reply
- follow
- account_approved
- report
- suspended
- unsuspended
- mention

Notes:
- Mentions are deduplicated per post per user (a user will receive at most one 'mention' notification for a given post and author).

Where to edit the text shown to users

- Language strings live in `lang/` (e.g. `lang/tr.php` and `lang/en.php`). Edit or add translations for keys prefixed with `notification_`.
  - e.g. `notification_like`, `notification_reply`, `notification_follow`, `notification_account_approved`, `notification_report`, `notification_suspended`, `notification_unsuspended`

- The rendering of each notification is handled by `templates/notification-item.php` which consumes a `$notification` array and uses `t('notification_<type>')` to fetch the localized message.

Database note

If your database was created before the admin migration (2026-01-14), run the migration to add `suspended` and `unsuspended` to the `notifications.type` enum:

    php migrations/20260114_admin_migration.php

This will also add other admin-related schema changes.
