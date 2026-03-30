# Clean URLs Implementation

## Overview

The social media platform now uses clean, SEO-friendly URLs instead of query parameters.

## URL Structure

### User Profiles
**Clean URL:** `domain.com/username`  
**Old URL:** `domain.com/profile.php?username=username`  
**Example:** `domain.com/john` → Shows John's profile

### Posts
**Clean URL:** `domain.com/post/123`  
**Old URL:** `domain.com/post.php?id=123`  
**Example:** `domain.com/post/456` → Shows post #456

### Edit Post
**Clean URL:** `domain.com/post/123/edit`  
**Old URL:** `domain.com/edit_post.php?id=123`  
**Example:** `domain.com/post/456/edit` → Edit post #456

### Followers
**Clean URL:** `domain.com/username/followers`  
**Old URL:** `domain.com/followers.php?username=username`  
**Example:** `domain.com/john/followers` → John's followers

### Following
**Clean URL:** `domain.com/username/following`  
**Old URL:** `domain.com/following.php?username=username`  
**Example:** `domain.com/john/following` → Users John follows

## Implementation Details

### Apache Rewrite Rules

The `.htaccess` file contains rewrite rules that convert clean URLs to their PHP equivalents:

```apache
# Username profile
RewriteRule ^([a-zA-Z0-9_]+)$ profile.php?username=$1 [L,QSA]

# Post detail
RewriteRule ^post/([0-9]+)$ post.php?id=$1 [L,QSA]

# Edit post
RewriteRule ^post/([0-9]+)/edit$ edit_post.php?id=$1 [L,QSA]

# Followers
RewriteRule ^([a-zA-Z0-9_]+)/followers$ followers.php?username=$1 [L,QSA]

# Following
RewriteRule ^([a-zA-Z0-9_]+)/following$ following.php?username=$1 [L,QSA]
```

### Helper Functions

Clean URL helper functions in `includes/functions.php`:

```php
// Generate clean profile URL
function profile_url($username) {
    return BASE_PATH . '/' . urlencode($username);
}

// Generate clean post URL
function post_url($post_id) {
    return BASE_PATH . '/post/' . intval($post_id);
}

// Generate clean edit post URL
function edit_post_url($post_id) {
    return BASE_PATH . '/post/' . intval($post_id) . '/edit';
}

// Generate followers URL
function followers_url($username) {
    return BASE_PATH . '/' . urlencode($username) . '/followers';
}

// Generate following URL
function following_url($username) {
    return BASE_PATH . '/' . urlencode($username) . '/following';
}
```

## Usage in Code

### Before (Query Parameters)
```php
<a href="<?= BASE_PATH ?>/profile.php?username=<?= urlencode($username) ?>">
    @<?= htmlspecialchars($username) ?>
</a>
```

### After (Clean URLs)
```php
<a href="<?= profile_url($username) ?>">
    @<?= htmlspecialchars($username) ?>
</a>
```

## Benefits

1. **SEO Friendly:** Search engines prefer clean URLs
2. **User Friendly:** Easier to remember and share
3. **Professional:** Looks more polished
4. **Readable:** Users can understand the URL structure
5. **Social Media:** Better appearance when shared

## Backward Compatibility

Old URLs still work! The system accepts both formats:
- `domain.com/profile.php?username=john` ✓ Works
- `domain.com/john` ✓ Works (preferred)

## Files Updated

All profile links updated in:
- `templates/post-card.php`
- `templates/reply-card.php`
- `templates/notification-item.php`
- `includes/functions.php` (link_usernames function)
- `followers.php`
- `following.php`
- `profile_ban.php`
- `admin/users.php`
- `admin/premium_users.php`
- `admin/reports.php`

## Requirements

- Apache with mod_rewrite enabled
- .htaccess file in root directory
- AllowOverride All in Apache configuration

## Testing

Test clean URLs:

1. **Profile:**
   ```
   Visit: domain.com/username
   Should show: User profile page
   ```

2. **Post:**
   ```
   Visit: domain.com/post/1
   Should show: Post detail page
   ```

3. **Followers:**
   ```
   Visit: domain.com/username/followers
   Should show: Follower list
   ```

## Troubleshooting

### Clean URLs Not Working

1. **Check mod_rewrite:**
   ```bash
   apache2ctl -M | grep rewrite
   ```
   Should show: `rewrite_module (shared)`

2. **Enable mod_rewrite:**
   ```bash
   sudo a2enmod rewrite
   sudo systemctl restart apache2
   ```

3. **Check .htaccess:**
   - File exists in root directory
   - File permissions: 644
   - Contains RewriteEngine On

4. **Check Apache config:**
   ```apache
   <Directory /var/www/html>
       AllowOverride All
   </Directory>
   ```

### 404 Errors

If you get 404 errors:
1. Check .htaccess is in the correct directory
2. Verify RewriteBase matches your installation path
3. Check Apache error logs: `tail -f /var/log/apache2/error.log`

### Wrong Redirects

If URLs redirect incorrectly:
1. Check BASE_PATH in `includes/config.php`
2. Verify RewriteBase in .htaccess
3. Clear browser cache

## Examples

### Homepage to Profile
```html
<!-- Timeline shows username links -->
<a href="<?= profile_url('john') ?>">@john</a>
<!-- Renders as: <a href="/john">@john</a> -->
```

### Post Card
```html
<!-- Post shows author link -->
<a href="<?= profile_url($username) ?>">@<?= $username ?></a>
<!-- Renders as: <a href="/alice">@alice</a> -->
```

### Notification
```html
<!-- Notification shows who performed action -->
<a href="<?= profile_url($from_user) ?>">@<?= $from_user ?></a>
<!-- Renders as: <a href="/bob">@bob</a> -->
```

## Future Enhancements

Potential additional clean URLs:
- `domain.com/search/query` → Search results
- `domain.com/hashtag/trending` → Hashtag pages
- `domain.com/notifications` → Notifications page
- `domain.com/@username` → Alternative profile format
- `domain.com/u/username` → Alternative profile format

## Notes

- Usernames must contain only: letters, numbers, underscores
- Username URLs are case-sensitive
- Reserved paths: admin, api, assets, includes, etc. are excluded from username routing
- Files with extensions (.php, .css, .js, etc.) bypass clean URL routing
