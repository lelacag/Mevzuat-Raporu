# Text Social Media Platform

A lightweight PHP-based social media platform with text posts, likes, replies, follows, and notifications.

## Features

- 📝 Create text posts (up to 500 characters with emoji support)
- 🔥 Like posts with emoji reactions
- 💬 Reply to posts
- 👤 User profiles with bios
- 👥 Follow/unfollow other users
- 🔔 Notifications for likes, replies, and follows
- 🚫 Bad word filtering
- ✅ Soft delete for KVKK compliance

## Installation

1. **Configure Database**
   Edit `includes/config.php` and update the database credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'yourdb');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

2. **Run Database Setup**
   Open your browser and navigate to:
   ```
   http://localhost/textsocialmedia/setup.php
   ```
   This will create all necessary tables and indexes.

3. **Start Using**
   - Register at `http://localhost/textsocialmedia/register.php`
   - Login at `http://localhost/textsocialmedia/login.php`
   - Create posts at `http://localhost/textsocialmedia/index.php`

## Pages

| Page | Description |
|------|-------------|
| `/index.php` | Home page with post creation and timeline |
| `/profile.php` | User profile (add `?username=john` to view others) |
| `/post.php?id=123` | Single post view with replies |
| `/notification.php` | Notifications center |
| `/login.php` | User login |
| `/register.php` | User registration |
| `/rules.php` | Platform rules |

## Database Schema

- **users** - User accounts with username, email, bio
- **posts** - Posts with content, likes_count, replies_count
- **likes** - Emoji reactions on posts
- **follows** - User follow relationships
- **notifications** - Activity notifications

## Customization

Edit `includes/config.php` to customize:
- `MAX_POST_LENGTH` - Maximum post characters (default: 500)
- `SITE_NAME` - Your platform name
- `BAD_WORDS` - Array of words to filter

## Notes

- All features work without JavaScript
- Session-based authentication
- PDO prepared statements for SQL injection prevention
- UTF-8 emoji support via utf8mb4 charset

## Admin tools

- Admin panel available under `/admin/` with user management, content review, and premium subscription reconciliation (`admin/premium_subscriptions.php`).

## Tests

- Basic test provided for Stripe webhook handler. Run `php scripts/run_tests.sh` to execute the simulated `checkout.session.completed` test (no Stripe credentials required).

- Mobile & IAP integration tests: use the WebView skeletons and test-mode. Set the following env vars locally:
  - `IAP_TEST_MODE=1`
  - `IAP_API_TOKEN=testtoken`
  - `ADMIN_API_TOKEN=admintoken`

Run:

```bash
scripts/run_integration_tests.sh
```

This will simulate a mobile purchase (test token) and then exercise the admin reverify flow.

Server configuration required for production verification:
- `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON`: path or JSON blob for Play Console service account with `androidpublisher` scope.
- `APPLE_SHARED_SECRET`: App Store shared secret (for auto-renewable subscriptions) or configure App Store Connect API keys; leave these empty during local testing and enable them only when ready for production.

