Portable Project Binder — Installation (macOS / XAMPP)

Quick steps to run locally from the USB or a folder:

1. Copy or unzip `project-binder.zip` to your machine or USB drive.
2. Place the `textsocialmedia` project directory inside your web server document root (e.g. XAMPP `htdocs`) or run with PHP built-in server.

Database (recommended):
- Import `database_schema.sql` using phpMyAdmin or the command line:
  mysql -u root -p < database_schema.sql
- Default XAMPP MySQL user is often `root` with no password.

Configuration:
- Duplicate `includes/config.example.php` -> `includes/config.php` and update DB credentials, site URL, and keys.
- Ensure `.env` or other secret files are NOT included on the USB (they are intentionally excluded).

Permissions:
- Make `logs/`, `sitemap_cache/`, and `uploads/` writable by the web server user. Example:
  chmod -R 775 logs sitemap_cache uploads

Run (XAMPP):
- Start Apache + MySQL via XAMPP control panel.
- Visit `http://localhost/<project-folder>/` in your browser.

Notes:
- This binder contains consolidated docs in `/binder/docs` and an archive of original markdown files at `/binder/docs/archive`.
- Logs, caches and sensitive files were excluded for portability and safety.

If you want, I can also add a small installer script that tries to import the DB and set permissions automatically.
