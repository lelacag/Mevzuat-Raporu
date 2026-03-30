# Pages reference — TextSocialMedia

This document describes the main pages and their primary functions. Use it as a quick guide for deployment and verification.

Root pages
- `index.php` — Main feed / home page (authenticated feed listing).
- `landing.php`, `compact_landing.php` — Marketing or public landing pages for unauthenticated users.
- `login.php` — User login form and auth handling.
- `logout.php` — Ends the session.
- `register.php`, `signup_request.php`, `signup_request_verify.php` — User signup flows and verification.
- `forgot_password.php`, `one_time_landing.php`, `one_time_confirm.php`, `one_time_consume.php` — Password reset and one-time token handling.
- `post.php`, `user_post.php`, `reply.php` — Create and view posts and replies.
- `profile.php`, `profile_edit.php`, `profile_ban.php` — User profiles and profile management.
- `search.php`, `suggestions.php` — Search and suggestion endpoints/pages.
- `group.php`, `groups_create.php`, `groups_join.php`, `groups_leave.php` — Group features.
- `notification.php` — User notification center.
- `premium.php`, `premium_payment.php` — Premium subscription flows.

Admin
- `admin/` — Administration area (announcements, audit logs, badwords, blocked ip lists, premium management, modules management). Only accessible to admin users.

APIs & webhooks
- `api/` — API endpoints for client/mobile interaction (token issuance, login, subscription handling, etc.).
- `webhook/` — Webhook handlers (Stripe, Google Play, Apple).

Includes & scripts
- `includes/config.php` — Core configuration file (DB credentials, app settings). The installer writes this file.
- `includes/db.php` — Database connection helper using PDO.
- `scripts/` — Utility scripts (cron, migrations helper, maintenance tasks).
- `database_schema.sql` — Full DB schema used by the installer to create the database.

Development & deployment docs
- `README.md`, `DEPLOYMENT.md`, `DEPLOYMENT_CHECKLIST.md`, `FINAL_DEPLOYMENT_GUIDE.md` — Existing deployment docs; review these after running the installer for server-specific tasks.

Notes
- Many pages require `includes/config.php` to be present and correctly configured.
- After installation, verify that `BASE_PATH` in `includes/config.php` matches where you host the app (e.g., `/textsocialmedia` or `/`).
- Refer to `SECURITY_AUDIT.md` and `PRODUCTION_SECURITY.md` for recommended production hardening steps.
