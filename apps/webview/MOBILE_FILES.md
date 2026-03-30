# Mobile app file map & required server assets

This document maps the repository files and environment variables mobile engineers need when building native Android/iOS apps (WebView + native IAP bridge). Keep this file as the canonical reference when creating native apps.

## Server endpoints used by mobile apps
- `POST /api/validate_iap.php` — Verify IAP purchases from Android (purchase token) and iOS (base64 receipt). Required for granting premium. Accepts bearer token (`IAP_API_TOKEN`) or logged-in session.
- `POST /api/admin_reverify_iap.php` — Token-protected admin/CI endpoint to re-run verification for a saved subscription. Requires `ADMIN_API_TOKEN`.
- `POST /api/stripe_create_session.php` — Create Stripe Checkout sessions (only if using Stripe).
- Webhooks (for vendor notifications):
  - `webhook/google_play.php` — (stub/receiver) Google Play publisher notifications
  - `webhook/apple.php` — App Store Server Notifications endpoint (stub)
  - `webhook/stripe.php` — Stripe webhook receiver for checkout/subscription events

## Server libraries/helpers to keep / maintain
- `includes/google_play.php` — Play service account helper & subscription verification
- `includes/apple_receipt.php` — App Store receipt verification helper (production + sandbox fallback)
- `includes/stripe.php` — Stripe integration (session creation, webhook handling)
- `api/validate_iap.php` — Main mobile validation endpoint (handles test-mode shortcut)
- `api/admin_reverify_iap.php` — Admin reverify CI helper

## Admin & reconciliation UIs
- `admin/premium_reconcile.php` — Admin UI to inspect & reconcile subscriptions
- `admin/premium_subscriptions.php` — List of premium subscriptions and actions
- `api/admin_export_premium_subscriptions.php` — CSV export for reporting/CI

## Mobile sample apps & scripts
- `apps/webview/android/MainActivity.kt` — Android WebView + deep-link sample
- `apps/webview/ios/ViewController.swift` — iOS WKWebView + deep-link sample
- `apps/webview/test_mobile_purchase.sh` — Android test helper script (invokes server validate)
- `scripts/test_ios_validation.sh` — iOS test helper (posts a test receipt)
- `api/iap_status.php` — server-side readiness check for IAP credentials and tokens
- `fastlane/Fastfile` — example Fastlane lanes for iOS & Android publish flows
- `apps/webview/BUILD.md` — Build & publish checklist (expanded)
- `apps/webview/README.md` — Quick start & notes for WebView builds

## Tests & CI
- `tests/ios_handler_test.php` — Unit test for Apple helper (test-mode)
- `tests/stripe_handler_test.php` — Stripe handler test
- `scripts/run_integration_tests.sh` — Orchestrates integration tests (runs mobile scripts + reverify)
- `.github/workflows/integration.yml` — CI workflow props for running integration tests in test-mode

## Database / migrations (important fields)
- `migrations/20260206_add_stripe_columns.sql` — adds `stripe_customer_id`, `stripe_subscription_id` and vendor columns used by subscriptions
- Table: `premium_subscriptions` (key columns used by mobile flows)
  - `user_id`, `plan_type`, `status`, `start_date`, `end_date`, `payment_method`, `payment_proof`, `vendor_purchase_token`, `vendor_transaction_id`, `vendor_status`, `vendor_payload`, `validated_at`

## Required environment variables
- Test & dev:
  - `IAP_TEST_MODE=1` — enable test-mode shortcuts
  - `IAP_TEST_SUCCESS_TOKEN` — test token accepted by server
  - `IAP_API_TOKEN` — bearer token used by native apps to call `validate_iap.php` (recommended)
  - `ADMIN_API_TOKEN` — token for admin/CI reverify
- Production verification (set when ready):
  - `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` — path or JSON blob for Play Console service account
  - `APPLE_SHARED_SECRET` — shared secret for App Store receipt verification (or configure App Store Connect API keys)
  - `STRIPE_*` — Stripe keys if using Stripe Checkout

## Best practices & notes
- Keep service credentials (service account JSON, App Store secrets, Stripe keys) out of git; store them in CI secret manager or environment variables. 🚨
- Use `IAP_TEST_MODE=1` for early mobile QA and enable production credentials only after staging verification completes. 🔁
- Always verify server responses before granting premium features in the app (server is authoritative).

## Quick checklist for native teams
1. Build WebView and deep link handling (e.g., `myapp://buy?plan=monthly`).
2. Implement native IAP (StoreKit/Play Billing) and post purchase token/receipt to `api/validate_iap.php`.
3. Use `IAP_API_TOKEN` as a bearer header for native calls (avoid embedding admin tokens in the app).
4. Test end-to-end with `IAP_TEST_MODE=1` and `IAP_TEST_SUCCESS_TOKEN`.
5. Provision production credentials and run CI reverify using `api/admin_reverify_iap.php`.

---

File created: `apps/webview/MOBILE_FILES.md` — keep this updated whenever mobile-related server files or env vars change.