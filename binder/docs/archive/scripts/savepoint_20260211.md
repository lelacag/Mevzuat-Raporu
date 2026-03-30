Savepoint: 2026-02-11

Summary of current workspace state:

- JS/JSON sweep completed: removed `assets/js/cookie-notice.js` and cleared `scripts/tmp-smoketest-logs/` files.
- No-JS flows in place: cookie notice uses `includes/cookie-notice.php` + `includes/cookie-notice-handler.php`; admin revoke implemented as `admin/revoke_url_session.php`.
- Tahlil / Anket features implemented with SEO slugs and viewer pages (`test_view.php`, `poll_view.php`) and DB helpers (`create_test_db`, `update_test_db`, `create_poll`).
- Defensive checks added: `column_exists()`, slug guards, `generate_slug()`.
- Added "rookie" creation restriction: `is_user_creation_restricted()` enforced for polls/tests and composer buttons hidden for rookies.
- Edit flows: duplicate edit button removed; editing test returns to editor and `updated_at` added for tests (migration `migrations/20260212_add_updated_at_tests.sql`).
- Backfill scripts: `scripts/backfill_slugs.php` exists; migration instructions added to `DEPLOYMENT.md`.
- Cleanup helper and docs: `scripts/cleanup_remove_js.sh` added and run (deletions performed); `DEPLOYMENT.md` updated with cleanup steps.

Files added/modified of note:
- Added: `admin/revoke_url_session.php`, `scripts/cleanup_remove_js.sh`, `scripts/savepoint_20260211.md` (this file).
- Edited: `test_view.php`, `test_advanced_poll.php`, `includes/functions.php`, `templates/post-card.php`, `templates/test-block.php`, `templates/test-compact-block.php`, `poll.php`, `DEPLOYMENT.md`, and more.

Pending / Next actions:
1. Run DB migrations on target environment and backfill slugs/updated_at per `DEPLOYMENT.md`.
2. Commit changes to Git and push to a private repo (`textsocial`) — I can prepare `.gitignore` and README if you want.
3. Add smoke tests for cookie & rookies & clean-js absence (optional; I can add them).
4. Consider adding an admin UI to run backfill safely (optional).

If you want anything committed or pushed to GitHub now, or a patch prepared, tell me and I will prepare it.

Status: paused. Ready to continue when you return.
