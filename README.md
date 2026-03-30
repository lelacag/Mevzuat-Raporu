Mevzuat Raporu — Platform Overview

This document is a concise README you can upload to the repository manually.

Project: Mevzuat Raporu

Short description
- Mevzuat Raporu is a lightweight, server-rendered PHP social platform supporting posts, groups, events, followers, likes and notifications.

Tech stack
- PHP (7.4 or later) running under PHP-FPM
- MySQL / MariaDB
- Apache for production; nginx often used for staging
- Minimal client-side JavaScript; most rendering is server-side

Key structure
- `includes/`: bootstrap, configuration, helpers
- `api/`: HTTP endpoints used by the app and admin actions
- `modules/`: optional extensions (site-specific behavior)
- `templates/`: HTML partials and card templates
- `assets/`: CSS, images, fonts

Recent v1.1 changes
- Adds `modules/mevzuat_triggers.php` to enable a persistent system user, auto-follow and auto-like triggers.
- `api/admin_approve_user.php` now uses a persistent system user (`Sistem`) to send account approval notifications and greetings.
- Notification rendering improved to show system messages and saved greeting texts.

Quick setup notes (dev/staging)
1. Create a DB and user (example): `textsocialmedia_staging` / `staginguser`.
2. Edit `includes/config.php` to set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
3. Import `database_schema.sql` into the database.
4. Ensure PHP-FPM is reachable (commonly `127.0.0.1:9000`) and your webserver vhost points to the project root.

Deployment and releases
- Create a branch (e.g. `release/v1.1`) and open a PR to `master`/`main`.
- Keep secrets out of the repository; use server-side config files or environment variables.

Contributing
- Add optional features under `modules/` to keep them easily removable.
- Run `php -l` to lint files before committing.

Contact
- For pull requests or deployment questions, open an issue or contact the repo owner.
