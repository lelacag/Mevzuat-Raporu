iOS WebView wrapper (quick start)

Approach
- Create a small iOS app that loads your web UI in a `WKWebView`.
- Intercept navigation (use `WKNavigationDelegate`) for a custom deep link like `myapp://buy?plan=monthly` to trigger native StoreKit purchase.
- After successful purchase, send base64-encoded receipt to `/api/validate_iap.php`.

Key snippets

1) Intercept navigation in `WKNavigationDelegate`:

func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
    if let url = navigationAction.request.url, url.scheme == "myapp", url.host == "buy" {
        let plan = url.queryParameters?["plan"]
        startPurchase(plan: plan)
        decisionHandler(.cancel)
        return
    }
    decisionHandler(.allow)
}

2) After purchase, send receipt to server:

let receiptData = Data(contentsOf: Bundle.main.appStoreReceiptURL!)
let receiptBase64 = receiptData.base64EncodedString()

POST /api/validate_iap.php
Headers: Authorization: Bearer <app-token>, Content-Type: application/json
Body: { "platform": "ios", "user_id": <id>, "plan": "monthly", "receipt_base64": "<base64>" }

Notes
- Use StoreKit or StoreKit2 for purchases; test with App Store sandbox users.
- App Store Server Notifications can notify your server directly of renewals/refunds; implement `/webhook/apple.php`.
- Do not embed Apple keys or secrets inside the app.

Fastlane & App Store Connect
- Use `fastlane` to automate builds and TestFlight uploads; `fastlane/Fastfile` has an example `upload_to_testflight` lane. Configure `APPLE_ID` and App Store Connect API keys in CI.
- For production receipt verification you can either use `APPLE_SHARED_SECRET` (auto-renewing subs) or implement App Store Connect API server JWT verification for more advanced cases.

