Premium feature policy changes implemented on Feb 6, 2026

Summary:
- Enforced that only Premium users may post longer than the site max (DEFAULT: 500 characters). Non-premium users will see a clear error when attempting to post longer than the allowed max.
- Restricted post editing to Premium users (and admins). Editing now requires an active premium flag; non-premium users see a clear error message.
- Events page remains premium-only; premium page now explicitly lists "Özel etkinlik güncellemelerine erişim" as a premium feature.

Files changed:
- `includes/functions.php`
  - `get_user_post_limit()` unchanged behavior (premium -> 0 => unlimited; non-premium -> MAX_POST_LENGTH)
  - `create_post()` now rejects non-premium posts that exceed the limit (returns `['error' => 'limit_exceeded']`).
  - `edit_post()` now requires the user to be premium (or admin). Returns `['error' => 'premium_required']` when not allowed.
  - `can_edit_post()` now respects premium/admin requirement.
- `index.php`, `profile.php`, `api/reply.php`
  - Handle the `limit_exceeded` return value and show friendly messages to users.
- `api/edit_post.php` and language files
  - Use localized messages for the premium edit error and related edit errors.
- `premium.php`
  - Add "Özel etkinlik güncellemelerine erişim" to the minimal feature list.

Notes for QA / follow-up:
- Ensure UI displays errors in the target language; the server returns localized messages for `post_length_error`.
- If you'd rather split long posts into multiple entries for non-premium but only allow premium to post long single posts, we can change the policy (current policy: reject if too long).
- If you want a short banner for non-premium users on the post box that encourages upgrading when they hit the limit, I can add a small inline helper.

If you want, I can now also add an admin-only setting that toggles whether long posts are split or rejected for non-premium users (to make policy configurable).