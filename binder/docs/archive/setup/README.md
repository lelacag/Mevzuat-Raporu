# Setup & Deployment — TextSocialMedia

This directory contains a simple web-based installer and guidance to prepare the app for production.

Files
- `install.php` — A no-JavaScript web installer that will:
  - Validate DB credentials and optionally create the database
  - Import `database_schema.sql` into the chosen database
  - Create an initial admin account
  - Generate `includes/config.php` with secure defaults
  - Create runtime directories (`logs`, `uploads`, `sitemap_cache`, `assets/cache`)

Security Notes
- **Remove** or restrict access to `setup/install.php` after installation (delete the `setup/` directory or block it in your webserver config).
- Set file permissions on `includes/config.php`, e.g. `chmod 640 includes/config.php` and ensure proper ownership (webserver user should own writable directories only).
- Set `ENVIRONMENT` to `production` and ensure `display_errors` is disabled in production.

Post-install Checklist
1. Run `php -f scripts/run_migrations.php` if you have new migrations.
2. Check `DEPLOYMENT_CHECKLIST.md` and `FINAL_DEPLOYMENT_GUIDE.md` for server specific steps (SSL, caching, cron jobs, PHP-FPM settings).
3. Verify `database_schema.sql` was applied correctly and verify admin account login.
4. Configure optional modules (e.g., `modules/sms/config.php`, email settings).

Payments / Stripe
- To enable Stripe Checkout and webhook handling, set environment variables: `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, and `STRIPE_WEBHOOK_SECRET` (for live) or `STRIPE_TEST_*` equivalents for test mode.
- The installer can optionally write these keys into a local `.env` file when you provide them on the setup form. Keep `.env` out of git and secure it with proper file permissions (e.g., `chmod 640 .env`).
- Install the Stripe PHP SDK with `composer require stripe/stripe-php` to enable full Stripe functionality.

Theme testing
- The previous teal color-scheme experiment has been cancelled and related test hooks removed. If you want to reintroduce a theme test, open an issue describing the desired palette and I can implement it as a maintenance-safe change.
If your deployment is automated (CI/CD), prefer running migrations and config templating from your pipeline and do not expose `setup/install.php` on production servers.
