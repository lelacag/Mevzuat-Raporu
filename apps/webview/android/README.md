Android WebView wrapper (quick start)

Approach
- Create a simple Android app that loads your site in a WebView.
- Detect purchase intent by intercepting navigation to a custom URL scheme (e.g., `myapp://buy?plan=monthly`) or use the WebView `shouldOverrideUrlLoading()` hook.
- Trigger Google Play Billing flow from native code.
- After purchase, POST the purchase token to `/api/validate_iap.php` (see integration.md).

Key snippets

1) AndroidManifest (intent filter / deep link sample):

<intent-filter>
    <action android:name="android.intent.action.VIEW" />
    <category android:name="android.intent.category.DEFAULT" />
    <category android:name="android.intent.category.BROWSABLE" />
    <data android:scheme="myapp" android:host="buy" />
</intent-filter>

2) Intercept in WebViewClient:

@Override
public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
    Uri u = request.getUrl();
    if ("myapp".equals(u.getScheme()) && "buy".equals(u.getHost())) {
        String plan = u.getQueryParameter("plan");
        startPurchaseFlow(plan);
        return true;
    }
    return false; // let WebView load other urls
}

3) After successful purchase (Google Play Billing), call server:

POST /api/validate_iap.php
Headers: Authorization: Bearer <app-token>, Content-Type: application/json
Body: { "platform": "android", "user_id": <id>, "plan": "monthly", "purchase_token": "<token>" }

Notes
- Use Play Billing Library (current recommended version). Handle purchase acknowledgements and consumption according to Google docs; add `com.android.billingclient:billing:<latest>` to your `build.gradle`.
- Use HTTPS and verify server response before granting app-level premium features.
- Use Play sandbox accounts for testing.

Fastlane & Play Console
- Use `fastlane` to automate builds and uploads; `fastlane/Fastfile` has an example lane using `upload_to_play_store` which expects `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` to be configured in CI.
- Configure app signing, upload keys, and internal test tracks before promoting to closed/open beta and production.

