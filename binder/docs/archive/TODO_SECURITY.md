# Security TODO & Status

This file tracks remaining high-priority tasks for CAPTCHA/WAF/Admin UI hardening.

## Completed
- Implemented improved CAPTCHA entropy (random 6-char codes). ✅
- Installed TTF font to properly render UTF-8 characters. ✅
- Increased `CAPTCHA_MIN_SECONDS` to 10s and reduced `CAPTCHA_MAX_ATTEMPTS` to 2. ✅
- Added DB-backed `captcha_generations` and rate-limiting on token creation per IP. ✅
- Added admin UI pages (CAPTCHA Dashboard, Offenders, Blocked IPs) for manual inspection and actions. ✅
- Added `blocked_ips` DB table & middleware to block requests early by IP (admin exception). ✅
- Added automated tests: `scripts/check_captcha_bot.sh`, `scripts/pentest_captcha.sh`. ✅

## In Progress / Next (recommended priority)
1. Auto-blocking actions (automatic insert into `blocked_ips` when thresholds exceeded) — TODO
2. Alerting system (cron job to send email/Slack when thresholds triggered) — TODO
3. Admin UI enhancements: pagination, CSV export, user-friendly timestamps, filtering — TODO
4. fail2ban / ipset integration for OS-level fast blocking (optional) — TODO
5. WAF (Nginx + ModSecurity or managed WAF) evaluation & deployment — TODO
6. Purge old rows for `captcha_generations` and `captcha_store` (cron) — TODO
7. Remove or archive `pentest_*` users created during tests (admin/manual or script) — TODO

## Notes
- Default thresholds are conservative and can be tuned via environment/config.
- Admin UI is server-side rendered: no JavaScript required currently.

---

To assign or mark items complete, edit this file or use the admin UI once it's extended.
