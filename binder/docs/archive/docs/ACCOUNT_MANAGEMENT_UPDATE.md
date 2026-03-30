# Account Management Updates - Implementation Guide

## Changes Made

### 1. Disable Account Functionality (Deactivation)
**What changed:**
- Accounts now use `is_active` flag instead of `suspended_until`
- When disabled: `is_active = 0`
- User is logged out immediately
- Profile is hidden from other users
- **Reactivation:** User simply logs back in, and account is automatically reactivated

**Files modified:**
- `api/disable_account.php` - Sets `is_active = 0`
- `includes/auth.php` - Checks `is_active` on login and reactivates if disabled
- `profile.php` - Hides disabled profiles from others
- `profile_edit.php` - Updated description and confirmation message

### 2. Delete Account Confirmation Flow
**What changed:**
- Confirmation dialogs now appear **after** user clicks "Delete Account" button
- **Step 1:** Shows on modal popup (not on main page):
  - Warning message about permanent deletion
  - "Yes, Delete My Account" and "Cancel" buttons
  
- **Step 2:** Shows only if user has email (after Step 1):
  - Email confirmation token input field
  - "Confirm and Delete" and "Cancel" buttons

**Files already correct:**
- `profile_edit.php` - Modal-based confirmation flow

## Database Migration Required

**IMPORTANT:** Run this SQL before testing:

```bash
mysql -u root -p textsocialmedia < migrations/add_is_active_column.sql
```

Or manually:
```sql
ALTER TABLE users 
ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER email_verified;

CREATE INDEX idx_is_active ON users(is_active);
```

## Testing Checklist

### Disable Account (Deactivation)
- [ ] Go to Profile Edit page
- [ ] Click "Disable Account" button
- [ ] Confirm the dialog
- [ ] Verify: User is logged out
- [ ] Verify: Profile is hidden from other users
- [ ] Log back in with same credentials
- [ ] Verify: Account is reactivated automatically
- [ ] Verify: Profile is visible again

### Delete Account
- [ ] Go to Profile Edit page
- [ ] Click "Delete Account Permanently" button
- [ ] Verify: Modal popup appears (Step 1)
- [ ] Click "Yes, Delete My Account"
- [ ] If email exists: Verify Step 2 shows (token input)
- [ ] Check email for deletion token
- [ ] Enter token and click "Confirm and Delete"
- [ ] Verify: Account is deleted
- [ ] Try to login: Should fail

## User Flow Summary

### Disable/Deactivate Flow:
1. User clicks "Disable Account" button
2. Confirmation dialog appears
3. User confirms
4. Account disabled (`is_active = 0`)
5. User logged out
6. Profile hidden from others
7. **To reactivate:** User logs back in → Account automatically reactivated

### Delete Flow (With Email):
1. User clicks "Delete Account Permanently" button
2. **Modal appears** with Step 1 warning
3. User clicks "Yes, Delete My Account"
4. **Modal switches** to Step 2 (email token)
5. User receives email with token
6. User enters token
7. User clicks "Confirm and Delete"
8. Account permanently deleted

### Delete Flow (Without Email):
1. User clicks "Delete Account Permanently" button
2. **Modal appears** with Step 1 warning
3. User clicks "Yes, Delete My Account"
4. Account immediately deleted (no email confirmation needed)

## Notes

- Disabled accounts are **temporary** - user can reactivate by logging in
- Deleted accounts are **permanent** - cannot be recovered
- Disabled profiles are hidden from all users except the owner
- Delete confirmation uses modal popups, not inline page content
- Email verification is required for delete if user has email address
