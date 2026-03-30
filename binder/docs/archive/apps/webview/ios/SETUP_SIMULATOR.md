iOS Simulator quick start

Follow these steps to run the WebView wrapper in the iOS Simulator:

1. Create a new Xcode project
   - Open Xcode → Create a new project → App (iOS) → Single View App
   - Set Product Name: WebViewWrapper
   - Set Organization Identifier: com.example
   - Choose Swift as language and Storyboard or SwiftUI as you prefer.

2. Add `ViewController.swift`
   - Replace the generated `ViewController.swift` with the provided sample (`apps/webview/ios/ViewController.swift`).

3. Info.plist: Enable App Transport Settings for local testing (optional)
   - If you need to connect to `http://10.0.2.2` or local http endpoints, add App Transport Settings exceptions or use `https` with a valid cert.

4. Run in Simulator
   - Select a Simulator (e.g., iPhone 14) and press Run (Cmd+R).
   - The app will load your site in the WKWebView. Use a deep link like `myapp://buy?plan=monthly` inside the web page to trigger the native flow.

5. Testing with local server
   - For local dev, host the server on your machine and use `https://<your-host>` reachable by the simulator (Simulator sees `localhost` as host mac's localhost, but it's easiest to use a loopback or local tunnel).
   - Alternatively, use `ngrok` to expose local server over HTTPS and update the `webView.load(URLRequest(url: URL(string: "https://<your-tunnel>")!))` in `ViewController.swift`.

Notes
- For real StoreKit tests, configure sandbox test users in App Store Connect and use TestFlight. For quick end-to-end emulator testing, prefer Android emulator (10.0.2.2) because it maps to host's localhost.
