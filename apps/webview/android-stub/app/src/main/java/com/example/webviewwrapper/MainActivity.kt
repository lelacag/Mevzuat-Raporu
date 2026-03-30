package com.example.webviewwrapper

import android.os.Bundle
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import java.io.OutputStreamWriter
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import android.graphics.Bitmap
import android.net.http.SslError
import android.webkit.SslErrorHandler
import android.webkit.WebResourceError
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.widget.EditText
import android.os.Build
import android.content.SharedPreferences
import android.net.Uri
import android.content.Intent
import android.content.ActivityNotFoundException
import timber.log.Timber

class MainActivity : AppCompatActivity() {
    private lateinit var webView: WebView
    private lateinit var billingManager: BillingManager

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // init logging
        Timber.plant(Timber.DebugTree())

        // Enable WebView remote debugging for chrome://inspect
        WebView.setWebContentsDebuggingEnabled(true)

        // Global uncaught exception handler: write crash details to external files dir for inspection
        val defaultHandler = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            try {
                writeCrashToFile(throwable)
            } catch (e: Exception) {
                Timber.w(e, "writeCrashToFile failed")
            }
            // Delegate to default handler to preserve crash behavior
            defaultHandler?.uncaughtException(thread, throwable)
        }

        webView = WebView(this)
        // Ensure an AppCompat-compatible theme is present; fall back to a device default no-action-bar theme
        try {
            setTheme(android.R.style.Theme_DeviceDefault_NoActionBar)
        } catch (e: Exception) {
            Timber.w(e, "setTheme fallback failed")
        }
        setContentView(webView)

        billingManager = BillingManager(this, this)
        try {
            billingManager.startConnection()
        } catch (e: Exception) {
            Timber.e(e, "BillingManager.startConnection failed")
            writeCrashToFile(e)
        }

        webView.settings.javaScriptEnabled = true
        // Allow mixed content for local testing (emulator/host); be careful in production
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.LOLLIPOP) {
            webView.settings.mixedContentMode = WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
        }

        webView.webViewClient = object : WebViewClient() {
            // Legacy override for API < 21 (handles clicks that provide a raw URL string)
            override fun shouldOverrideUrlLoading(view: WebView?, url: String?): Boolean {
                if (url == null) return false
                try {
                    val uri = Uri.parse(url)
                    // Handle custom in-app schemes ourselves
                    if (uri.scheme == "myapp") {
                        // Delegate to the request-based handler logic by constructing a fake request behavior
                        // myapp://buy, myapp://open and myapp://create_dev_token are supported
                        if (uri.host == "buy") {
                            val plan = uri.getQueryParameter("plan") ?: "monthly"
                            promptSimulatedPurchase(plan)
                            return true
                        }
                        if (uri.host == "open") {
                            // Reuse existing logic: call the request-based handler by wrapping into a WebResourceRequest-like flow
                            val path = uri.getQueryParameter("path") ?: "premium"
                            val sidFromLink = uri.getQueryParameter("sid")
                            val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
                            val savedSid = prefs.getString("sid", null)
                            val sidToUse = sidFromLink ?: savedSid
                            if (sidToUse == null) {
                                runOnUiThread {
                                    AlertDialog.Builder(this@MainActivity)
                                        .setTitle("No session token")
                                        .setMessage("No test session token found. Create one on the server for user=1?")
                                        .setPositiveButton("Create & Load") { _, _ -> createTestSessionAndLoad(path) }
                                        .setNegativeButton("Cancel", null)
                                        .show()
                                }
                                return true
                            }
                            var target = path
                            if (!target.startsWith("/")) target = "/" + target
                            if (!target.endsWith(".php")) {
                                if (target == "/premium") target = "/premium.php"
                            }
                            // Prefer saved base_url from prefs, fallback to current webview URL, then intent extra
                            val prefsBase = prefs.getString("base_url", null)
                            val cur = prefsBase ?: webView.url ?: intent.getStringExtra("base_url")
                            val root = cur?.let {
                                if (it.contains("/textsocialmedia")) {
                                    val idx = it.indexOf("/textsocialmedia")
                                    it.substring(0, idx + "/textsocialmedia".length)
                                } else {
                                    try { Uri.parse(it).scheme + "://" + Uri.parse(it).host } catch (e: Exception) { null }
                                }
                            } ?: ""
                            val finalRoot = if (root.isNullOrEmpty()) (prefsBase ?: (intent.getStringExtra("base_url") ?: "https://192.168.1.102/textsocialmedia")) else root
                            val finalUrl = finalRoot + target + "?sid=" + sidToUse
                            Timber.i("Deep-link navigate: %s", finalUrl)
                            runOnUiThread { webView.loadUrl(finalUrl, mapOf("X-In-App" to "1")) }
                            return true
                        }

                        // If the URL is an internal Events link without a sid, append saved sid (legacy string-based handler)
                        try {
                            val path = uri.path ?: ""
                            val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
                            val savedSid = prefs.getString("sid", null)
                            if ((path.endsWith("/events.php") || path.endsWith("/events") || path.endsWith("/etkinlikler")) && uri.getQueryParameter("sid") == null && !savedSid.isNullOrEmpty()) {
                                var newUrl = url
                                newUrl += if (newUrl!!.contains("?")) "&sid=$savedSid" else "?sid=$savedSid"
                                Timber.i("Appending saved sid to legacy navigation: %s", newUrl)
                                runOnUiThread { webView.loadUrl(newUrl, mapOf("X-In-App" to "1")) }
                                return true
                            }
                        } catch (e: Exception) {
                            Timber.w(e, "legacy append sid intercept failed")
                        }
                        if (uri.host == "create_dev_token") {
                            // Explicit in-app action to create a dev premium token and reload current page
                            val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
                            val cur = webView.url ?: (intent.getStringExtra("base_url") ?: prefs.getString("base_url", null))
                            createPremiumTokenAndReload(cur)
                        }
                    }

                    // If not handled and scheme is not http/https, try to open an external app
                    if (uri.scheme != "http" && uri.scheme != "https") {
                        try {
                            val intent = Intent(Intent.ACTION_VIEW, uri)
                            startActivity(intent)
                            return true
                        } catch (ae: ActivityNotFoundException) {
                            runOnUiThread { AlertDialog.Builder(this@MainActivity).setTitle("Cannot open link").setMessage("No application is available to open this link scheme: ${uri.scheme}").setPositiveButton("OK", null).show() }
                            return true
                        }
                    }
                } catch (e: Exception) {
                    Timber.w(e, "shouldOverrideUrlLoading string parse failed")
                    return false
                }
                return false
            }

            override fun shouldOverrideUrlLoading(v: WebView?, request: WebResourceRequest?): Boolean {
                val uri = request?.url ?: return false

                // If navigating directly to an internal events page without a sid, append saved sid and reload (dev convenience)
                try {
                    val path = uri.path ?: ""
                    if ((path.endsWith("/events.php") || path.endsWith("/events") || path.endsWith("/etkinlikler")) && uri.getQueryParameter("sid") == null) {
                        val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
                        val savedSid = prefs.getString("sid", null)
                        if (!savedSid.isNullOrEmpty()) {
                            var newUrl = uri.toString()
                            newUrl += if (newUrl.contains("?")) "&sid=$savedSid" else "?sid=$savedSid"
                            Timber.i("Appending saved sid to internal navigation: %s", newUrl)
                            runOnUiThread { webView.loadUrl(newUrl, mapOf("X-In-App" to "1")) }
                            return true
                        }
                    }
                } catch (e: Exception) {
                    Timber.w(e, "append sid intercept failed")
                }

                // Deep link: trigger purchase flow
                if (uri.scheme == "myapp" && uri.host == "buy") {
                    val plan = uri.getQueryParameter("plan") ?: "monthly"
                    promptSimulatedPurchase(plan)
                    return true
                }

                // Deep link: open an app route or arbitrary path in the WebView
                // Example: myapp://open?path=premium
                if (uri.scheme == "myapp" && uri.host == "open") {
                    val path = uri.getQueryParameter("path") ?: "premium"
                    val sidFromLink = uri.getQueryParameter("sid")
                    val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
                    val savedSid = prefs.getString("sid", null)
                    val sidToUse = sidFromLink ?: savedSid

                    if (sidToUse == null) {
                        // No sid available: offer to create a test session token (dev-only)
                        runOnUiThread {
                            AlertDialog.Builder(this@MainActivity)
                                .setTitle("No session token")
                                .setMessage("No test session token found. Create one on the server for user=1?")
                                .setPositiveButton("Create & Load") { _, _ ->
                                    createTestSessionAndLoad(path)
                                }
                                .setNegativeButton("Cancel", null)
                                .show()
                        }
                        return true
                    }

                    // Normalize path
                    var target = path
                    if (!target.startsWith("/")) target = "/" + target
                    if (!target.endsWith(".php")) {
                        if (target == "/premium") target = "/premium.php"
                    }

                    // Prefer saved base_url from prefs, fallback to current webview URL, then intent extra
                    val prefsBase = prefs.getString("base_url", null)
                    val cur = prefsBase ?: webView.url ?: intent.getStringExtra("base_url")
                    val root = cur?.let {
                        if (it.contains("/textsocialmedia")) {
                            val idx = it.indexOf("/textsocialmedia")
                            it.substring(0, idx + "/textsocialmedia".length)
                        } else {
                            try { Uri.parse(it).scheme + "://" + Uri.parse(it).host } catch (e: Exception) { null }
                        }
                    } ?: ""
                    val finalRoot = if (root.isNullOrEmpty()) (prefsBase ?: (intent.getStringExtra("base_url") ?: "https://192.168.1.102/textsocialmedia")) else root
                    val finalUrl = finalRoot + target + "?sid=" + sidToUse
                    Timber.i("Deep-link navigate (request): %s", finalUrl)
                    runOnUiThread { webView.loadUrl(finalUrl, mapOf("X-In-App" to "1")) }
                    return true
                }

                return false
            }

            override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
                Timber.i("WebView.onPageStarted url=%s", url)
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                Timber.i("WebView.onPageFinished url=%s", url)
                // Persist any sid query parameter we encounter so subsequent deep-links can reuse it
                try {
                    if (url != null) {
                        val u = Uri.parse(url)
                        val sid = u.getQueryParameter("sid")
                        if (!sid.isNullOrEmpty()) {
                            val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
                            prefs.edit().putString("sid", sid).apply()
                            Timber.i("Saved sid from page: %s", sid)
                        }

                        // Detect server-side premium prompt (dev only) and auto-create a premium test session
                        // Look for the premium-prompt element in the loaded DOM; if present, auto-create token and reload.
                        webView.evaluateJavascript("(function(){return document.querySelector('.premium-prompt') !== null;})();") { value ->
                            try {
                                if (value == "true") {
                                    // If we already have a saved sid in prefs, silently reload with it to avoid prompting the user
                                    val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
                                    val savedSid = prefs.getString("sid", null)
                                    try {
                                        if (!savedSid.isNullOrEmpty()) {
                                            val u = url?.let { Uri.parse(it) }
                                            if (u != null && u.getQueryParameter("sid") == null && url != null) {
                                                val reloadUrl = if (url.contains("?")) "$url&sid=$savedSid" else "$url?sid=$savedSid"
                                                runOnUiThread { webView.loadUrl(reloadUrl, mapOf("X-In-App" to "1")) }
                                                return@evaluateJavascript
                                            }
                                        }
                                    } catch (e: Exception) {
                                        Timber.w(e, "silent sid reload failed")
                                    }

                                    // Show a one-tap dialog to create a premium test token (explicit action)
                                    runOnUiThread {
                                        AlertDialog.Builder(this@MainActivity)
                                            .setTitle("Premium required")
                                            .setMessage("This page requires Premium. Create a dev-only premium test token and reload the page?")
                                            .setPositiveButton("Create & Reload") { _, _ ->
                                                createPremiumTokenAndReload(url)
                                            }
                                            .setNegativeButton("Cancel", null)
                                            .show()
                                    }
                                }
                            } catch (e: Exception) {
                                Timber.w(e, "premium prompt check failed")
                            }
                        }

                        // Also check for an embedded dev token meta tag and save it automatically
                        webView.evaluateJavascript("(function(){var m=document.querySelector('meta[name=\\\"dev-token\\\"]'); return m ? m.getAttribute('content') : null; })();") { tokenValue ->
                            try {
                                if (tokenValue != null && tokenValue != "null" && tokenValue.length > 0) {
                                    val token = tokenValue.trim().replace("\"", "")
                                    if (!token.isNullOrEmpty()) {
                                        val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
                                        prefs.edit().putString("sid", token).apply()
                                        Timber.i("Saved sid from meta dev-token: %s", token)
                                        if (url != null) {
                                            val u = Uri.parse(url)
                                            if (u.getQueryParameter("sid") == null) {
                                                val currentUrl = url ?: ""
                                                val finalUrl = if (Regex("([?&])sid=[A-Za-z0-9._-]*").containsMatchIn(currentUrl)) {
                                                    currentUrl.replace(Regex("([?&])sid=[A-Za-z0-9._-]*")) { match ->
                                                        val prefix = match.groupValues[1]
                                                        "$prefix" + "sid=" + token
                                                    }
                                                } else {
                                                    if (currentUrl.contains("?")) "$currentUrl&sid=$token" else "$currentUrl?sid=$token"
                                                }
                                                runOnUiThread { webView.loadUrl(finalUrl, mapOf("X-In-App" to "1")) }
                                            }
                                        }
                                    }
                                }
                            } catch (e: Exception) {
                                Timber.w(e, "dev-token meta check failed")
                            }
                        }
                    }
                } catch (e: Exception) {
                    Timber.w(e, "Failed to parse sid from onPageFinished")
                }
            }

            // Create premium token via JSON API and reload (dev-only)
            private fun createPremiumTokenAndReload(currentUrl: String?) {
                CoroutineScope(Dispatchers.IO).launch {
                    try {
                        val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
                        val debugUrl = URL("http://192.168.1.102/textsocialmedia/api/debug_create_url_session.php?user=1&make_premium=1&ttl=86400")
                        val conn = (debugUrl.openConnection() as HttpURLConnection).apply {
                            requestMethod = "GET"
                            connectTimeout = 5000
                            readTimeout = 5000
                            setRequestProperty("Accept", "application/json")
                        }
                        val body = conn.inputStream.bufferedReader().readText()
                        // Parse JSON response
                        val json = org.json.JSONObject(body)
                        if (json.optBoolean("success", false)) {
                            val token = json.optString("token", null)
                            if (!token.isNullOrEmpty()) {
                                prefs.edit().putString("sid", token).apply()
                                if (!currentUrl.isNullOrEmpty()) {
                                    val finalUrl = if (Regex("([?&])sid=[A-Za-z0-9._-]*").containsMatchIn(currentUrl)) {
                                        currentUrl.replace(Regex("([?&])sid=[A-Za-z0-9._-]*")) { match ->
                                            val prefix = match.groupValues[1]
                                            "$prefix" + "sid=" + token
                                        }
                                    } else {
                                        if (currentUrl.contains("?")) "$currentUrl&sid=$token" else "$currentUrl?sid=$token"
                                    }
                                    runOnUiThread { webView.loadUrl(finalUrl, mapOf("X-In-App" to "1")) }
                                }
                                runOnUiThread { AlertDialog.Builder(this@MainActivity).setTitle("Token created").setMessage("Created test token and reloaded page.").setPositiveButton("OK", null).show() }
                                return@launch
                            }
                        }
                        // Fallback error
                        runOnUiThread { AlertDialog.Builder(this@MainActivity).setTitle("Dev token failed").setMessage("Server response: " + body.take(200)).setPositiveButton("OK", null).show() }
                    } catch (e: Exception) {
                        Timber.w(e, "createPremiumTokenAndReload failed")
                        runOnUiThread { AlertDialog.Builder(this@MainActivity).setTitle("Error").setMessage("Network error while creating test token: " + (e.message ?: "")).setPositiveButton("OK", null).show() }
                    }
                }
            }

            override fun onReceivedError(view: WebView?, request: WebResourceRequest?, error: WebResourceError?) {
                val msg = "WebView error code=${error?.errorCode} msg=${error?.description} url=${request?.url}"
                Timber.w(msg)
                writeCrashToFile(Exception(msg))
                runOnUiThread {
                    AlertDialog.Builder(this@MainActivity)
                        .setTitle("Page load error")
                        .setMessage("Failed to load ${request?.url}:\n${error?.description}")
            .setPositiveButton("Open fallback") { _, _ -> webView.loadUrl("https://www.google.com") }
                        .setNegativeButton("OK", null)
                        .show()
                }
            }

            override fun onReceivedHttpError(view: WebView?, request: WebResourceRequest?, errorResponse: WebResourceResponse?) {
                val msg = "WebView HTTP error status=${errorResponse?.statusCode} url=${request?.url}"
                Timber.w(msg)
                writeCrashToFile(Exception(msg))
            }

            override fun onReceivedSslError(view: WebView?, handler: SslErrorHandler?, error: SslError?) {
                val msg = "WebView SSL error: $error"
                Timber.w(msg)
                writeCrashToFile(Exception(msg))
                // For local testing only: proceed past SSL errors. Remove in production!
                handler?.proceed()
            }
        }

        // Decide base URL depending on whether we're running on an emulator or a physical device.
        val baseUrlFromIntent = intent.getStringExtra("base_url")
        val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
        val baseUrl = baseUrlFromIntent ?: run {
            if (isProbablyEmulator()) {
                "https://10.0.2.2/textsocialmedia/landing.php"
            } else {
                val saved = prefs.getString("base_url", null)
                if (saved != null) {
                    saved
                } else {
                    // Prompt the user to enter the host URL (useful on physical devices). This dialog saves the URL to prefs.
                    runOnUiThread {
                        val input = EditText(this@MainActivity).apply {
                            setText("https://192.168.1.102/textsocialmedia/landing.php")
                        }
                        AlertDialog.Builder(this@MainActivity)
                            .setTitle("Enter server URL")
                            .setMessage("10.0.2.2 only works on emulators. Enter the host URL for your machine:")
                            .setView(input)
                            .setPositiveButton("Save & Load") { _, _ ->
                                val url = input.text.toString().trim()
                                prefs.edit().putString("base_url", url).apply()
                                try {
                                    webView.loadUrl(url)
                                } catch (e: Exception) {
                                    Timber.e(e, "Failed to load saved URL")
                                    writeCrashToFile(e)
                                }
                            }
                            .setNeutralButton("Load Temporary") { _, _ ->
                                val url = input.text.toString().trim()
                                try {
                                    webView.loadUrl(url)
                                } catch (e: Exception) {
                                    Timber.e(e, "Failed to load temporary URL")
                                    writeCrashToFile(e)
                                }
                            }
                            .setNegativeButton("Open Google") { _, _ -> webView.loadUrl("https://www.google.com") }
                            .show()
                    }
                    // Return a blank placeholder since we will load from the dialog callbacks
                    "about:blank"
                }
            }
        }

        try {
            if (baseUrl != "about:blank") {
                webView.loadUrl(baseUrl)
            }
        } catch (e: Exception) {
            Timber.e(e, "WebView.loadUrl failed")
            writeCrashToFile(e)
        }

        // Auto-trigger simulated purchase for CI if requested via intent extras
        val autoSim = intent.getBooleanExtra("simulate_purchase", false)
        val autoPlan = intent.getStringExtra("simulate_plan") ?: "monthly"
        if (autoSim) {
            simulatePurchase(autoPlan)
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        billingManager.endConnection()
    }

    private fun promptSimulatedPurchase(plan: String) {
        AlertDialog.Builder(this)
            .setTitle("Purchase")
            .setMessage("Choose a purchase option for $plan")
            .setPositiveButton("Simulate (test token)") { _, _ -> simulatePurchase(plan) }
            .setNeutralButton("Use Billing") { _, _ -> billingManager.launchPurchaseFlow("test_monthly", plan) }
            .setNegativeButton("Cancel", null)
            .show()
    }

    // Create a test URL session on the server (dev endpoint) and then load the requested path
    private fun createTestSessionAndLoad(path: String) {
        CoroutineScope(Dispatchers.IO).launch {
            try {
                // This dev helper returns a plaintext token line: "Created token: <token>"
                // Use HTTP to avoid dev SSL cert issues on devices
                val debugUrl = URL("http://192.168.1.102/textsocialmedia/debug_create_url_session.php?user=1&make_premium=1&ttl=86400")
                val conn = (debugUrl.openConnection() as HttpURLConnection).apply {
                    requestMethod = "GET"
                    connectTimeout = 5000
                    readTimeout = 5000
                }
                val body = conn.inputStream.bufferedReader().readText()
                val m = Regex("""Created token:\s*([a-f0-9]+)""", RegexOption.IGNORE_CASE).find(body)
                val token = m?.groupValues?.get(1)
                if (!token.isNullOrEmpty()) {
                    val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
                    prefs.edit().putString("sid", token).apply()
                    // After storing sid, issue an open for premium. If no base URL is known, prompt the user.
                    runOnUiThread {
                        val cur = webView.url ?: (intent.getStringExtra("base_url") ?: prefs.getString("base_url", null))
                        if (cur.isNullOrEmpty()) {
                            val input = EditText(this@MainActivity).apply {
                                setText("https://192.168.1.102/textsocialmedia/")
                            }
                            AlertDialog.Builder(this@MainActivity)
                                .setTitle("Enter server URL")
                                .setMessage("No server configured. Enter your server URL so the app can load pages:")
                                .setView(input)
                                .setPositiveButton("Save & Load") { _, _ ->
                                    val url = input.text.toString().trim()
                                    prefs.edit().putString("base_url", url).apply()
                                    loadPathWithSid(path, token)
                                }
                                .setNegativeButton("Cancel", null)
                                .show()
                        } else {
                            loadPathWithSid(path, token)
                            AlertDialog.Builder(this@MainActivity).setTitle("Loaded").setMessage("The premium page should now load in the app.").setPositiveButton("OK", null).show()
                        }
                    }
                } else {
                    runOnUiThread { AlertDialog.Builder(this@MainActivity).setTitle("Failed").setMessage("Could not create test token on server").setPositiveButton("OK", null).show() }
                }
            } catch (e: Exception) {
                Timber.w(e, "createTestSessionAndLoad failed")
                runOnUiThread { AlertDialog.Builder(this@MainActivity).setTitle("Failed").setMessage("Network or server error: ${e.message}").setPositiveButton("OK", null).show() }
            }
        }
    }

    // Helper: load a path (e.g. "premium") with a sid appended; computes base root from current URL or saved prefs
    private fun loadPathWithSid(path: String, sid: String) {
        try {
            val prefs = getSharedPreferences("webview_prefs", MODE_PRIVATE)
            val cur = webView.url ?: (intent.getStringExtra("base_url") ?: prefs.getString("base_url", null))
            val root = cur?.let {
                val idx = it.indexOf("/textsocialmedia")
                if (idx != -1) it.substring(0, idx + "/textsocialmedia".length) else Uri.parse(it).scheme + "://" + Uri.parse(it).host
            } ?: ""
            var target = path
            if (!target.startsWith("/")) target = "/" + target
            if (!target.endsWith(".php")) {
                if (target == "/premium") target = "/premium.php"
            }
            val finalUrl = root + target + "?sid=" + sid
            runOnUiThread { webView.loadUrl(finalUrl, mapOf("X-In-App" to "1")) }
        } catch (e: Exception) {
            Timber.w(e, "loadPathWithSid failed")
        }
    }

    private fun simulatePurchase(plan: String) {
        Timber.i("SIMULATE_PURCHASE_STARTED plan=%s", plan)
        CoroutineScope(Dispatchers.IO).launch {
            val json = "{\"platform\":\"android\",\"user_id\":1,\"plan\":\"$plan\",\"purchase_token\":\"TEST_SUCCESS\"}"
            try {
                val url = URL("http://10.0.2.2/textsocialmedia/api/validate_iap.php")
                val conn = (url.openConnection() as HttpURLConnection).apply {
                    requestMethod = "POST"
                    setRequestProperty("Content-Type", "application/json")
                    setRequestProperty("Authorization", "Bearer testtoken")
                    doOutput = true
                    connectTimeout = 10000
                    readTimeout = 10000
                }
                OutputStreamWriter(conn.outputStream).use { it.write(json) }
                val code = conn.responseCode
                val msg = try { conn.inputStream.bufferedReader().readText() } catch (e: Exception) { conn.errorStream?.bufferedReader()?.readText() ?: "" }
                Timber.i("SIMULATE_PURCHASE_RESULT code=%d msg=%s", code, msg.take(200))

                // Handle specific server responses to show helpful messages in-app
                runOnUiThread {
                    if (code == 403 && msg.contains("server_not_configured")) {
                        AlertDialog.Builder(this@MainActivity)
                            .setTitle("Server not configured")
                            .setMessage("The server does not have IAP API token configured. For app purchases, set IAP_API_TOKEN on the server. For local testing, set IAP_TEST_MODE=1 and IAP_TEST_SUCCESS_TOKEN=TEST_SUCCESS.")
                            .setPositiveButton("OK", null)
                            .show()
                    } else if (code == 401 && msg.contains("invalid_token")) {
                        AlertDialog.Builder(this@MainActivity)
                            .setTitle("Invalid token")
                            .setMessage("The app's bearer token is not accepted by the server. Ensure server IAP_API_TOKEN matches the app token or reconfigure the server.")
                            .setPositiveButton("OK", null)
                            .show()
                    } else {
                        AlertDialog.Builder(this@MainActivity)
                            .setTitle("Server response")
                            .setMessage("HTTP $code:\n${msg.take(400)}")
                            .setPositiveButton("OK", null)
                            .show()
                    }
                }
            } catch (e: Exception) {
                Timber.w(e, "SIMULATE_PURCHASE_FAILED")
            }
        }
    }

    private fun isProbablyEmulator(): Boolean {
        val fingerprint = Build.FINGERPRINT ?: ""
        val model = Build.MODEL ?: ""
        val product = Build.PRODUCT ?: ""
        return fingerprint.contains("generic") || fingerprint.contains("vbox") ||
            model.contains("Emulator") || model.contains("Android SDK built for x86") ||
            product.contains("sdk") || Build.MANUFACTURER.contains("Genymotion") ||
            (Build.BRAND.startsWith("generic") && Build.DEVICE.startsWith("generic"))
    }

    private fun writeCrashToFile(throwable: Throwable) {
        try {
            // getExternalFilesDir(null) returns a File?; fall back to internal filesDir
            val dir = getExternalFilesDir(null) ?: filesDir
            val ts = System.currentTimeMillis()
            val file = File(dir, "crash-$ts.log")
            file.bufferedWriter().use { it.write(throwable.stackTraceToString()) }
            Timber.i("Wrote crash file: %s", file.absolutePath)
        } catch (e: Exception) {
            Timber.w(e, "Failed to write crash to file")
        }
    }
}
