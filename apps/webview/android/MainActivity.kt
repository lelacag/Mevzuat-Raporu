// Sample MainActivity.kt (snippet) - place in a minimal Android project
// Note: This is a snippet to illustrate the flow. For production, implement Google Play Billing.

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
import java.net.HttpURLConnection
import java.net.URL

class MainActivity : AppCompatActivity() {
    private lateinit var webView: WebView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        webView = WebView(this)
        setContentView(webView)

        webView.settings.javaScriptEnabled = true
        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(v: WebView?, request: WebResourceRequest?): Boolean {
                val uri = request?.url ?: return false
                if (uri.scheme == "myapp" && uri.host == "buy") {
                    val plan = uri.getQueryParameter("plan") ?: "monthly"
                    promptSimulatedPurchase(plan)
                    return true
                }
                return false
            }
        }

        // Load your site
        webView.loadUrl("https://your-domain/")
    }

    private fun promptSimulatedPurchase(plan: String) {
        AlertDialog.Builder(this)
            .setTitle("Simulate Purchase")
            .setMessage("Simulate a $plan purchase using test token?")
            .setPositiveButton("Yes") { _, _ -> simulatePurchase(plan) }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun simulatePurchase(plan: String) {
        CoroutineScope(Dispatchers.IO).launch {
            val json = "{\"platform\":\"android\",\"user_id\":1,\"plan\":\"$plan\",\"purchase_token\":\"TEST_SUCCESS\"}"
            val url = URL("https://localhost/textsocialmedia/api/validate_iap.php")
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
            val msg = conn.inputStream.bufferedReader().readText()
            runOnUiThread {
                AlertDialog.Builder(this@MainActivity)
                    .setTitle("Server response")
                    .setMessage("HTTP $code:\n$msg")
                    .setPositiveButton("OK", null)
                    .show()
            }
        }
    }
}
