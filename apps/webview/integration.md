Integration guide — Server API (WebView wrapper + native IAP bridge)

Purpose
- Describe the server endpoints and expected payloads that the native wrappers will call after a successful in-app purchase.

API: POST /api/validate_iap.php
- Auth: Bearer token recommended (Authorization: Bearer <app_api_token>). Alternatively accept session cookie for WebView-authenticated users.
- Defaults and test-mode: By default we use the `IAP_API_TOKEN` env var as the bearer token (set this on your server; we use `testtoken` in examples). For local dev you can set `IAP_TEST_MODE=1` and `IAP_TEST_SUCCESS_TOKEN="TEST_SUCCESS"` so mobile apps can simulate success without store credentials.
- Payload (application/json):
  {
    "platform": "android" | "ios",
    "user_id": 123,
    "plan": "monthly|yearly|lifetime",
    "purchase_token": "<play_purchase_token>" (android) OR "receipt_base64": "<base64_receipt>" (ios),
    "metadata": { optional invoice/company fields }
  }

- Defaults used by the mobile skeleton samples:
  - URL scheme: `myapp://` (example deep link: `myapp://buy?plan=monthly`)
  - Example bearer token for tests: `testtoken` (set env `IAP_API_TOKEN=testtoken`)
  - Example purchase_token for test-mode: `TEST_SUCCESS`
- Response: { "success": true, "subscription_id": 42, "status": "active" }

Server responsibilities
- Validate the token/receipt against Google Play / App Store.
- Verify that purchase corresponds to expected product/plan and is not already used.
- Persist vendor fields: platform, purchase_token/receipt, transaction_id, vendor_status, vendor_payload, validated_at in `premium_subscriptions` (or related table).
- Promote user to `member` (or set `is_premium`, `premium_until`) according to purchase period.
- Return consistent JSON and helpful error codes.

Webhooks
- Google: Real-Time Developer Notifications (RTDN) recommended — endpoint `/webhook/google_play.php` to update subscription lifecycle.
- Apple: App Store Server Notifications — endpoint `/webhook/apple.php`.
- Webhook handlers must be idempotent and log events for reconciliation.

Reconciliation
- Admin UI should show vendor purchase id and raw payload, and allow manual reconcile/cancel if vendor and DB disagree.

Testing
- Use Play sandbox accounts and App Store sandbox to test purchase/renewal/expiry/refund paths.
- Log all validation calls and responses to help debug failed validations.

Example cURL (Android):

curl -X POST 'https://yourdomain/api/validate_iap.php' \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{"platform":"android","user_id":123,"plan":"monthly","purchase_token":"<token_here>"}'

Example cURL (iOS):

curl -X POST 'https://yourdomain/api/validate_iap.php' \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{"platform":"ios","user_id":123,"plan":"monthly","receipt_base64":"$(base64 receipt)`"}'


Notes on product IDs
- Keep consistent product ids across stores if possible (e.g., `premium_monthly`, `premium_yearly`, `premium_lifetime`). Validate that the purchase product matches the expected product.

Security reminder
- Validate signatures where applicable, use idempotency keys, and store vendor payload for audit.
