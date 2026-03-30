// Sample ViewController.swift for WKWebView intercept
// Use in a simple iOS project for testing WebView + native IAP bridge

import UIKit
import WebKit

class ViewController: UIViewController, WKNavigationDelegate {
    var webView: WKWebView!

    override func loadView() {
        webView = WKWebView()
        webView.navigationDelegate = self
        view = webView
    }

    override func viewDidLoad() {
        super.viewDidLoad()
        let url = URL(string: "https://your-domain/")!
        webView.load(URLRequest(url: url))
    }

    func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction,
                 decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
        if let url = navigationAction.request.url, url.scheme == "myapp", url.host == "buy" {
            let components = URLComponents(url: url, resolvingAgainstBaseURL: false)
            let plan = components?.queryItems?.first(where: { $0.name == "plan" })?.value ?? "monthly"
            promptSimulatedPurchase(plan: plan)
            decisionHandler(.cancel)
            return
        }
        decisionHandler(.allow)
    }

    func promptSimulatedPurchase(plan: String) {
        let ac = UIAlertController(title: "Simulate Purchase", message: "Simulate a \(plan) purchase using test token?", preferredStyle: .alert)
        ac.addAction(UIAlertAction(title: "Yes", style: .default) { _ in self.simulatePurchase(plan: plan) })
        ac.addAction(UIAlertAction(title: "Cancel", style: .cancel))
        present(ac, animated: true)
    }

    func simulatePurchase(plan: String) {
        // Send simulated purchase to server (test-mode)
        guard let url = URL(string: "https://localhost/textsocialmedia/api/validate_iap.php") else { return }
        var req = URLRequest(url: url)
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.setValue("Bearer testtoken", forHTTPHeaderField: "Authorization")
        let payload: [String:Any] = ["platform":"ios","user_id":1,"plan":plan,"receipt_base64":"TEST_SUCCESS"]
        req.httpBody = try? JSONSerialization.data(withJSONObject: payload)

        let task = URLSession.shared.dataTask(with: req) { data, res, err in
            let msg = String(data: data ?? Data(), encoding: .utf8) ?? "no response"
            DispatchQueue.main.async {
                let ac = UIAlertController(title: "Server Response", message: msg, preferredStyle: .alert)
                ac.addAction(UIAlertAction(title: "OK", style: .default))
                self.present(ac, animated: true)
            }
        }
        task.resume()
    }
}
