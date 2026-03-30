package com.example.webviewwrapper

import android.app.Activity
import android.content.Context
import com.android.billingclient.api.*
import timber.log.Timber

/**
 * Minimal BillingManager example to show where to integrate the Play Billing library.
 * This is a sample/integration hint only and is not a production-ready implementation.
 */
class BillingManager(private val context: Context, private val activity: Activity) : PurchasesUpdatedListener {
    private var billingClient: BillingClient = BillingClient.newBuilder(context)
        .enablePendingPurchases()
        .setListener(this)
        .build()

    fun startConnection() {
        if (!billingClient.isReady) {
            billingClient.startConnection(object : BillingClientStateListener {
                override fun onBillingServiceDisconnected() {
                    Timber.i("Billing service disconnected")
                }

                override fun onBillingSetupFinished(billingResult: BillingResult) {
                    if (billingResult.responseCode == BillingClient.BillingResponseCode.OK) {
                        Timber.i("Billing setup OK")
                        // query available SKUs or existing purchases here
                    } else {
                        Timber.w("Billing setup failed: %s", billingResult.debugMessage)
                    }
                }
            })
        }
    }

    /**
     * Launch purchase flow for a subscription SKU. Provide the SKU id and the plan name
     * (eg: "monthly", "yearly"). After purchase completes, the purchase token will
     * be acknowledged and sent to the server for validation.
     */
    fun launchPurchaseFlow(skuId: String, plan: String) {
        val params = SkuDetailsParams.newBuilder().setSkusList(listOf(skuId)).setType(BillingClient.SkuType.SUBS).build()
        billingClient.querySkuDetailsAsync(params) { billingResult, skuDetailsList ->
            if (billingResult.responseCode == BillingClient.BillingResponseCode.OK && !skuDetailsList.isNullOrEmpty()) {
                val flowParams = BillingFlowParams.newBuilder().setSkuDetails(skuDetailsList[0]).build()
                // store plan info in purchase flow via extra (we'll keep mapping on client side)
                billingClient.launchBillingFlow(activity, flowParams)
            } else {
                Timber.w("Failed to query SKU details: %s", billingResult.debugMessage)
            }
        }
    }

    override fun onPurchasesUpdated(billingResult: BillingResult, purchases: MutableList<Purchase>?) {
        if (billingResult.responseCode == BillingClient.BillingResponseCode.OK && purchases != null) {
            for (p in purchases) {
                Timber.i("Purchase succeeded: %s", p.orderId)
                // Acknowledge purchase (if required) and then call server for validation
                if (!p.isAcknowledged) {
                    val ackParams = AcknowledgePurchaseParams.newBuilder().setPurchaseToken(p.purchaseToken).build()
                    billingClient.acknowledgePurchase(ackParams) { ackResult ->
                        if (ackResult.responseCode == BillingClient.BillingResponseCode.OK) {
                            Timber.i("Purchase acknowledged: %s", p.orderId)
                        } else {
                            Timber.w("Acknowledge failed: %s", ackResult.debugMessage)
                        }
                        // Send to server for validation regardless of ack result (server must validate token)
                        sendPurchaseToServer(p)
                    }
                } else {
                    sendPurchaseToServer(p)
                }
            }
        } else if (billingResult.responseCode == BillingClient.BillingResponseCode.USER_CANCELED) {
            Timber.i("User canceled purchase")
        } else {
            Timber.w("Purchase failed: %s", billingResult.debugMessage)
        }
    }

    private fun sendPurchaseToServer(purchase: Purchase) {
        // Map SKU to plan client-side or send plan via metadata; for demo we infer plan via SKU naming
        val sku = purchase.skus.firstOrNull() ?: ""
        val plan = when {
            sku.contains("monthly") -> "monthly"
            sku.contains("year") || sku.contains("annual") -> "yearly"
            sku.contains("lifetime") -> "lifetime"
            else -> "monthly"
        }

        Thread {
            try {
                val url = java.net.URL("http://10.0.2.2/textsocialmedia/api/validate_iap.php")
                val conn = (url.openConnection() as java.net.HttpURLConnection).apply {
                    requestMethod = "POST"
                    setRequestProperty("Content-Type", "application/json")
                    setRequestProperty("Authorization", "Bearer testtoken")
                    doOutput = true
                    connectTimeout = 10000
                    readTimeout = 10000
                }
                val metadata = mapOf("package_name" to context.packageName, "product_id" to sku)
                val payloadObj = mapOf("platform" to "android", "user_id" to 1, "plan" to plan, "purchase_token" to purchase.purchaseToken, "metadata" to metadata)
                val payload = org.json.JSONObject(payloadObj).toString()
                conn.outputStream.use { it.write(payload.toByteArray(Charsets.UTF_8)) }
                val code = conn.responseCode
                val resp = conn.inputStream.bufferedReader().readText()
                Timber.i("validate_iap response code=%d resp=%s", code, resp.take(300))
            } catch (e: Exception) {
                Timber.w(e, "sendPurchaseToServer failed")
            }
        }.start()
    }

    fun endConnection() {
        if (billingClient.isReady) billingClient.endConnection()
    }
}
