# Invitations & Premium Module

This optional module enables a simple invitation system and associated email templates.  When enabled:

* Users can send up to 10 invitation emails to new addresses.
* Invitation records are stored in `user_invitations` table.
* The inviter earns premium days when invitees register using a valid link.
* Email subject/body templates can be edited from the admin UI.

## Installation

1. Enable the module in `.env`:
   ```bash
   echo "INVITES_ENABLED=true" >> .env
   ```

2. Run migrations:
   ```bash
   mysql -u root -p my_database < modules/invites/migrations/0001_create_invitations_table.sql
   mysql -u root -p my_database < modules/invites/migrations/0002_email_settings.sql
   ```

3. (Optional) Configure defaults in the database or via the admin panel.

## Configuration & Usage

The module creates a new admin page at `/admin/invite_settings.php` where moderators
can update the e-mail **subject** and **body**.  The following variables may be used
in templates:

* `{site_name}` – the site name constant (`SITE_NAME`).
* `{invite_link}` – full registration URL containing the invite token.
* `{expiry_days}` – number of days before the link expires (from `INVITES_PREMIUM_DAYS`).

When no custom template is provided, sensible defaults are used.

## Database tables

* `user_invitations` – stores pending/accepted invites.
* `invite_settings` – stores customizable templates.

## Testing

Unit tests should verify that:

* The admin page is protected and respects `INVITES_MODULE_ENABLED`.
* Settings persist in `invite_settings` and are applied to outgoing emails.
* Fallback defaults are used when the table is empty or the module is disabled.

A lightweight CLI script (`scripts/check_modules.php`) can be run on staging/CI to
ensure module endpoints respond appropriately when enabled; it will exit non‑zero
if something appears broken.

## Deactivation

To disable the module, set `INVITES_ENABLED=false` or remove the `.env` variable.
Existing invitations remain in the database but the UI will no longer appear.  To
fully remove, drop the `user_invitations` and `invite_settings` tables manually.
