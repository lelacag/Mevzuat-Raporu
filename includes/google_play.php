<?php /* EN + TR comments used. */
/**
 * Minimal Google Play verification helper using service account JWT exchange.
 * Requires env var GOOGLE_PLAY_SERVICE_ACCOUNT_JSON which can be either:
 * - Path to a JSON keyfile (recommended), or
 * - The JSON blob itself (careful with env var quoting)
 *
 * Permissions needed: service account must have "Play Console" access for the app (View financial data or appropriate role).
 */

function google_play_get_service_account() {
    $raw = getenv('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON') ?: '';
    if (empty($raw)) return null;

    // If it looks like a path and file exists, read it
    if (file_exists($raw)) {
        $json = @file_get_contents($raw);
    } else {
        $json = $raw;
    }

    if (!$json) return null;
    $obj = json_decode($json, true);
    if (!$obj) return null;
    return $obj;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function google_play_get_access_token() {
    static $cache = null;
    if ($cache && isset($cache['expires_at']) && $cache['expires_at'] > time() + 30) {
        return $cache['access_token'];
    }

    $sa = google_play_get_service_account();
    if (!$sa) return null;

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $scope = 'https://www.googleapis.com/auth/androidpublisher';
    $payload = [
        'iss' => $sa['client_email'],
        'scope' => $scope,
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now,
    ];

    $jwt = base64url_encode(json_encode($header)) . '.' . base64url_encode(json_encode($payload));

    $private_key = $sa['private_key'] ?? null;
    if (!$private_key) return null;

    // Sign the JWT
    $signature = null;
    if (!openssl_sign($jwt, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
        error_log('google_play: openssl_sign failed');
        return null;
    }
    $jwt_assertion = $jwt . '.' . base64url_encode($signature);

    // Exchange for access token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    $post = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt_assertion,
    ]);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        error_log('google_play token exchange failed: ' . $err);
        return null;
    }
    $obj = json_decode($resp, true);
    if (empty($obj['access_token'])) {
        error_log('google_play token response missing access_token: ' . $resp);
        return null;
    }

    $cache = [
        'access_token' => $obj['access_token'],
        'expires_at' => time() + intval($obj['expires_in'] ?? 3600)
    ];
    return $cache['access_token'];
}

function google_play_verify_subscription($packageName, $productId, $purchaseToken) {
    // Call Google Play subscriptions API with retries and backoff for robustness
    $token = google_play_get_access_token();
    if (!$token) return ['success' => false, 'error' => 'no_service_account_or_token'];

    $url = sprintf('https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptions/%s/tokens/%s', rawurlencode($packageName), rawurlencode($productId), rawurlencode($purchaseToken));

    $maxAttempts = 3;
    $attempt = 0;
    $lastErr = null;
    while ($attempt < $maxAttempts) {
        $attempt++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            $lastErr = 'curl_error: ' . $err;
            error_log('google_play verify curl error (attempt ' . $attempt . '): ' . $err);
        } else {
            // Network call succeeded; inspect HTTP code
            if ($code === 200) {
                $obj = json_decode($resp, true);
                if (!$obj) return ['success' => false, 'error' => 'invalid_json', 'body' => $resp];
                return ['success' => true, 'payload' => $obj, 'attempts' => $attempt];
            }

            // For transient errors allow retry on 5xx or 429
            if (in_array($code, [429]) || ($code >= 500 && $code < 600)) {
                $lastErr = "http_$code";
                error_log("google_play verify http $code (attempt $attempt): $resp");
            } else {
                // Non-retriable error; return it
                return ['success' => false, 'error' => 'invalid_response', 'http_code' => $code, 'body' => $resp, 'attempts' => $attempt];
            }
        }

        // Backoff before next attempt
        $sleep = pow(2, $attempt - 1);
        sleep($sleep);
    }

    return ['success' => false, 'error' => 'network_or_server_error', 'details' => $lastErr, 'attempts' => $attempt];
}
