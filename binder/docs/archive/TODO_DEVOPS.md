# DevOps / Release TODO

This file tracks devops and release-related tasks that should be completed before a production rollout.

- [x] Add scheduled GitHub Actions workflow to run daily IAP health check and integration tests (cron)
- [ ] Add GitHub Actions workflow to run Fastlane lanes for staging uploads (TestFlight / Play internal track)
- [ ] Securely store Play service account JSON and App Store Connect keys in GitHub secrets
- [x] Add an Action or job that runs `scripts/iap_health_check.php` and posts alerts to Slack/Email when missing
- [x] Add periodic CI job to reverify outstanding subscriptions (use `scripts/reverify_pending_iap.php` via cron or CI)
- [ ] (Optional) Add Play & App Store credential validation job (dry-run verify with test product)

Created: keep this file updated as devops tasks are completed.