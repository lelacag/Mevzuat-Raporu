Android emulator quick start (stub project)

This folder contains a minimal Android project skeleton to test the WebView + native flow on an emulator.

Steps to run in emulator:
1. Open this folder in Android Studio (File → Open) and let Gradle sync.
2. Create an AVD (Android Virtual Device) from the AVD Manager.
3. In `MainActivity.kt`, `webView.loadUrl("https://10.0.2.2/textsocialmedia/")` points to your local web server (Android emulator `10.0.2.2` maps to host `localhost`).
4. Run the app on the emulator. Click a `myapp://buy?plan=monthly` deep link in the WebView (or navigate the UI) to trigger the purchase dialog.

Purchase options
- The dialog offers two flows:
  - `Simulate (test token)`: posts a test purchase (`purchase_token` = `TEST_SUCCESS`) to `api/validate_iap.php` (useful when Play Billing / Play Store are not available).
  - `Use Billing`: attempts to use the Play Billing Library (`BillingManager`) and will launch a real Play purchase flow if the emulator has Play Store and test accounts configured. After purchase, the app acknowledges the purchase and sends the purchase token to `api/validate_iap.php` (with `package_name` and `product_id` metadata).

Notes on testing with Play Billing
- Configure product IDs in the Play Console and add them to your app; the sample uses `test_monthly` as an example SKU name. Replace it with a real SKU when testing against Play.
- On emulators without Play Store or when Play Billing is unavailable, use the `Simulate (test token)` path for end-to-end server validation.

CLI build & install (optional):
- If you have the Android SDK and `gradle` or `./gradlew` available, you can build and install:

  ./gradlew :app:assembleDebug
  adb install -r app/build/outputs/apk/debug/app-debug.apk

Gradle wrapper notes
- This repo contains a small `gradlew` shim to prefer an existing wrapper and fall back to a system `gradle` if available. If you don't have `gradle` installed, you can generate a proper wrapper on a machine that has Gradle:

  cd apps/webview/android-stub
  gradle wrapper

- Or run the helper from project root (requires system Gradle):

  ./scripts/setup_gradle_wrapper.sh

CI
- A GitHub Actions workflow `.github/workflows/android-build.yml` will attempt to build `assembleDebug` and upload the debug APK as an artifact on push/PR to `main`. Use that artifact to download a built APK if local build is not available.

Notes
- Replace `applicationId` in `app/build.gradle` with your package name when preparing for a real build.
- Use `10.0.2.2` in the emulator to reach your host machine's `localhost` web server.
