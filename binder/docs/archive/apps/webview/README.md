# WebView App (WebView + Native IAP Bridge)

This folder contains a lightweight skeleton and integration notes for shipping a WebView-based Android and iOS wrapper that uses the platform's native In-App Purchase (IAP) systems (Google Play Billing / Apple StoreKit) and validates purchases server-side.

Overview
- Purpose: fast MVP to deliver an app using your existing web UI and implement purchases via native IAP (compliant with App Store and Play rules).
- Approach: WebView loads your site; native code intercepts a purchase intent (deep link) and performs IAP; after purchase, native app POSTs receipt/purchase token to server's `/api/validate_iap.php`.

Structure
- `/android/` — Android notes and sample snippets
- `/ios/` — iOS notes and sample snippets
- `integration.md` — server API contract and sample requests

Security & Notes
- Keep service account / App Store keys secret (env vars on server). Do not embed private keys in the app.
- Use HTTPS for all server endpoints.
- Use token-based API auth (Bearer token) for native apps. Avoid reusing session cookies unless intentionally using WebView session sharing.

Next steps
1. Implement server-side endpoints (validate_iap, webhooks) and DB columns. 2. Implement native purchase flow in Android and iOS wrappers. 3. Test with Play sandbox and App Store sandbox accounts.

Emulator quick start
- Android: open `apps/webview/android-stub` in Android Studio and run on an AVD; or use `scripts/run_android_emulator.sh` to build & install to a running emulator.
- iOS: follow `apps/webview/ios/SETUP_SIMULATOR.md` to create a simulator build in Xcode and run the WebView wrapper.

