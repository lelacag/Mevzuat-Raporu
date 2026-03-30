# User Approval System Implementation Summary

## Overview
Implemented a complete user approval system where new users:
- Get "Yeni Gelen" (Rookie) badge
- Can post max 10 times before approval
- Their posts only visible to themselves until approved
- Receive notification when approved
- Admin can approve/reject users

## Database Changes Required

**Run this migration:**
```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root yourdb < migrations/add_user_approval.sql
```

This adds:
- `is_approved` column to `users` table (TINYINT, default 0)
- "Yeni Gelen" badge to badges table
- Sets existing users as approved (is_approved = 1)

## Features Implemented

### 1. **New User Registration** (register.php)
- Users register with `is_approved = 0`
- Automatically assigned "Yeni Gelen" (🌱) badge
- Badge color: #95a5a6 (gray)

### 2. **Post Creation Limits** (includes/functions.php - create_post())
- Unapproved users can post max 10 times
- After 10 posts, gets error: "Henüz onaylanmadınız. Onaylanmadan en fazla 10 gönderi paylaşabilirsiniz."
- Error type: `unapproved_limit`

### 3. **Timeline Filtering** (includes/functions.php - get_posts())
- Main timeline: Only shows posts from approved users
- Exception: Users see their own posts even if unapproved
- Admins see all posts
- Public visitors only see approved users' posts

### 4. **Approval Warning Banner** (index.php)
- Shows warning to unapproved users:
  - "🌱 Hoş Geldiniz! Hesabınız henüz onaylanmadı..."
  - Shows remaining post count: "Kalan gönderi hakkınız: X/10"
  - Only visible to user themselves

### 5. **Admin Approval Page** (admin/pending_users.php)
- Lists all unapproved users with:
  - Username, display name, email
  - Email verification status
  - Post count, follower count
  - Registration date
  - "Kullanıcıyı Onayla" button
  - "Reddet ve Sil" button

### 6. **Approval API** (api/admin_approve_user.php)
- POST endpoint to approve users
- Actions on approval:
  1. Sets `is_approved = 1`
  2. Removes "Yeni Gelen" badge
  3. Sends notification: "🎉 Hesabınız onaylandı! Gönderileriniz artık ana zaman çizelgesinde görünüyor."
  4. Returns success JSON

### 7. **Admin Navigation Update** (admin/_nav.php)
- Added "🌱 Onay Bekleyenler" link
- Shows badge with count of pending approvals
- Real-time count display

### 8. **Admin Dashboard Stats** (admin/index.php)
- Shows count of pending approvals
- Links to pending_users.php when count > 0

## User Experience Flow

### New User:
1. **Registers** → Gets "🌱 Yeni Gelen" badge
2. **Posts** → Can post up to 10 times
3. **Sees own posts** → Only visible to themselves
4. **Gets warning** → Banner shows "Henüz onaylanmadınız"
5. **Waits for approval** → Admin reviews account

### Admin:
1. **Gets notification** → Badge shows pending count
2. **Views pending users** → admin/pending_users.php
3. **Reviews user** → Check posts, followers, email verification
4. **Approves** → Click "Kullanıcıyı Onayla"
5. **System actions**:
   - User's `is_approved` set to 1
   - "Yeni Gelen" badge removed
   - Notification sent to user
   - Posts now visible on timeline

### Approved User:
1. **Receives notification** → "🎉 Hesabınız onaylandı!"
2. **Posts visible** → All posts now show in main timeline
3. **No post limit** → Can post unlimited times
4. **Full member** → Complete platform access

## Files Modified

1. **migrations/add_user_approval.sql** (NEW)
   - Database schema changes

2. **includes/functions.php**
   - `create_post()`: Added unapproved user check and 10-post limit
   - `get_posts()`: Added approval filtering

3. **register.php**
   - Set `is_approved = 0` for new users
   - Auto-assign "Yeni Gelen" badge

4. **index.php**
   - Added approval warning banner
   - Handle `unapproved_limit` error

5. **post.php**
   - Handle `unapproved_limit` error in replies

6. **admin/pending_users.php** (NEW)
   - Full approval interface

7. **api/admin_approve_user.php** (NEW)
   - Approval endpoint

8. **admin/_nav.php**
   - Added "Onay Bekleyenler" link with badge

9. **admin/index.php**
   - Added pending approval stats

## Testing Checklist

After running migration:

- [ ] New user registration creates unapproved account
- [ ] "Yeni Gelen" badge assigned on registration
- [ ] Unapproved user sees own posts
- [ ] Unapproved user posts hidden from timeline
- [ ] Warning banner shows for unapproved users
- [ ] Post limit enforced at 10 posts
- [ ] Error message shows when limit reached
- [ ] Admin sees pending users count
- [ ] Admin can view pending users list
- [ ] Approve button works
- [ ] Badge removed on approval
- [ ] Notification sent on approval
- [ ] Posts visible after approval
- [ ] Approved user can post unlimited

## Database Schema

```sql
-- users table
ALTER TABLE users ADD COLUMN is_approved TINYINT(1) DEFAULT 0;
CREATE INDEX idx_is_approved ON users(is_approved);

-- badges table
INSERT INTO badges (name, description, badge_color, badge_text, is_system)
VALUES ('Yeni Gelen', 'Platformda yeni olan kullanıcılar için başlangıç rozeti', 
        '#95a5a6', '🌱 Yeni Gelen', 1);
```

## Configuration

No additional configuration needed. System works with existing setup.

## Next Steps

1. **Run migration** (REQUIRED):
   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root yourdb < migrations/add_user_approval.sql
   ```

2. **Test new registration** - Create test account and verify badge

3. **Test post limit** - Post 10 times, verify limit enforced

4. **Test admin approval** - Login as admin, approve test user

5. **Verify notifications** - Check user receives approval notification

## Production Notes

- Existing users automatically approved (migration sets is_approved = 1)
- No disruption to current users
- Only new registrations affected
- Admin approval required for all new users
- Consider auto-approval criteria in future (e.g., email verified + 3 days)
