# Building the WebView Wrapper Samples

These are minimal skeletons for Android and iOS to test the WebView + native IAP bridge using the server's test-mode.

Android
- Create a new Android project in Android Studio and add the provided `MainActivity.kt`.
- Add Internet permission to `AndroidManifest.xml`:

  <uses-permission android:name="android.permission.INTERNET" />

- Replace `https://your-domain/` in the sample with your local or staging URL and configure your package name and product IDs.
- Use the Play Billing Library in your app and call the server `api/validate_iap.php` with the purchase token after purchase.

iOS
- Create a new Single View Application in Xcode and replace the `ViewController.swift` with the sample provided.
- Configure In-App Purchase product IDs in App Store Connect and use StoreKit in the app to get the receipt; POST the base64 receipt to `api/validate_iap.php`.

Testing & Test-mode
- Ensure server env for local testing:
  - `IAP_TEST_MODE=1`
  - `IAP_API_TOKEN=testtoken`
  - `ADMIN_API_TOKEN=admintoken`
  - (optional) `IAP_TEST_SUCCESS_TOKEN` for test token overrides.
- Run `scripts/run_integration_tests.sh` to simulate mobile purchase + admin reverify flows.

Publishing Readiness Checklist ✅
- App metadata: package/bundle id, title, short/long descriptions, keywords (App Store), and localized screenshots for required device sizes.  
- Privacy & legal: Point to `https://your-domain/privacy.php` in store listing and ensure `kvkk.php`/`privacy.php` content matches required disclosures.  
- IAP configuration: Create In-App Products in Play Console and App Store Connect; set product IDs to match `product_id` used by your app.  
- Service credentials: Create a **Google Play service account JSON** and put it in `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` (path or JSON blob), and create App Store Connect key(s) / shared secret; set `APPLE_SHARED_SECRET` or point to App Store Connect API keys in env.  

CI & build helpers
- Example `fastlane` configuration is provided in `fastlane/Fastfile`. Use `upload_to_play_store` and `upload_to_testflight` with secrets stored as CI variables.  
- App entitlements & capabilities: Configure App Store capabilities (In-App Purchase), and Android permissions (INTERNET) and billing permission as required by recent Play Billing versions.  
- Build & sign: Prepare keystore / provisioning profiles and set up CI secrets for code signing.  
- Test on devices & review logs: Run the WebView flow on real devices in both Sandbox/Test environments and verify `api/validate_iap.php` treats test flows as expected.  
- Admin & reconciliation: Use `admin/premium_reconcile.php` to inspect subscriptions and `api/admin_reverify_iap.php` for CI rechecks.

Notes & Tips 💡
- Keep the server in test-mode for early mobile QA and only enable production verification after installing the Play service account and App Store credentials.  
- Use `scripts/test_mobile_purchase.sh` and `scripts/test_ios_validation.sh` to exercise the flows locally in test-mode.  
- See `apps/webview/MOBILE_FILES.md` for a file-by-file reference of required server files, scripts, tests, and environment variables for building native mobile apps.  

Security
- Store service account JSON and App Store secrets securely in environment variables or your CI secret manager; do not commit them to source control.
