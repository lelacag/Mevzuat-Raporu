# CAPTCHA Security Checklist 🔐

This file summarizes the CAPTCHA-related security settings and recommendations for the project.

Current settings (auto-generated):

- CAPTCHA_MIN_SECONDS: 10 (minimum seconds before a CAPTCHA can be submitted; prevents quick bots)
- CAPTCHA_MAX_ATTEMPTS: 2 (max incorrect attempts per token)
- CAPTCHA_STORE_TTL: 300 seconds (5 minutes — DB-backed tokens expire)
- CAPTCHA_DEBUG: false (disabled by default; only enable briefly for diagnostics)

Measures implemented:

- Image-based CAPTCHA rendered with GD.
- TTF rendering when `assets/fonts/DejaVuSans.ttf` exists; otherwise transliteration fallback.
- Per-token attempts tracked and enforced
- IP-level failure logging in `captcha_failures` table to detect & block abusive clients
- DB-backed captcha_store to support clients that reject cookies
- Honeypot & CSRF protections in registration forms
- No JavaScript required; all validation server-side

Recommendations:

- Keep `CAPTCHA_DEBUG` disabled in production.
- Monitor `captcha_failures` for suspicious patterns and tune thresholds as needed.
- Consider adding an admin UI to view/clear `captcha_failures` rows.
- If false positives are observed for users with slow input, consider carefully raising `CAPTCHA_MIN_SECONDS` or loosening `CAPTCHA_MAX_ATTEMPTS`.

Audit steps (how to test):

- Use `scripts/check_captcha_bot.sh` to simulate fast submissions and confirm the server returns "CAPTCHA submitted too quickly".
- Use `scripts/check_captcha_db.php` to inspect `captcha_failures` entries for test IPs.

