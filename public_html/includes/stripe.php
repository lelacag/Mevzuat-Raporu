<?php /* EN + TR comments used. */
// Lightweight Stripe helper wrapper. Functions are no-ops if Stripe library or credentials are missing.

// Return true when running in a test/dev environment and test keys are available
function stripe_is_test_mode() {
    if (getenv('STRIPE_SECRET_KEY') || getenv('STRIPE_PUBLISHABLE_KEY')) return false;
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') return false;
    $t_secret = getenv('STRIPE_TEST_SECRET_KEY') ?: '';
    $t_pub = getenv('STRIPE_TEST_PUBLISHABLE_KEY') ?: '';
    return $t_secret !== '' && $t_pub !== '';
}

function is_stripe_configured() {
    // Prefer live keys first, fall back to test keys in non-production
    $key = getenv('STRIPE_SECRET_KEY') ?: getenv('STRIPE_TEST_SECRET_KEY') ?: '';
    $pub = getenv('STRIPE_PUBLISHABLE_KEY') ?: getenv('STRIPE_TEST_PUBLISHABLE_KEY') ?: '';
    return $key !== '' && $pub !== '' && class_exists('Stripe\\StripeClient');
}

function stripe_get_secret_key() {
    return getenv('STRIPE_SECRET_KEY') ?: getenv('STRIPE_TEST_SECRET_KEY') ?: '';
}

/* UNUSED_START stripe_helpers
function stripe_get_publishable_key() {
    return getenv('STRIPE_PUBLISHABLE_KEY') ?: getenv('STRIPE_TEST_PUBLISHABLE_KEY') ?: '';
}

function stripe_get_webhook_secret() {
    return getenv('STRIPE_WEBHOOK_SECRET') ?: getenv('STRIPE_TEST_WEBHOOK_SECRET') ?: '';
}
UNUSED_END stripe_helpers */

function get_stripe_client() {
    if (!is_stripe_configured()) return null;
    $secret = stripe_get_secret_key();
    $cls = '\\Stripe\\StripeClient';
    return new $cls($secret);
}

/**
 * Create a Checkout Session for the given user and plan.
 * Returns array with ['success' => true, 'url' => 'https://...'] or ['success'=>false, 'error' => '...']
 */
function stripe_create_checkout_session($user_id, $plan_type, $return_url = '', $extra_metadata = []) {
    // Basic guard: don't create sessions if Stripe not configured
    if (!is_stripe_configured()) return ['success' => false, 'error' => 'Stripe not configured'];

    $client = get_stripe_client();
    if (!$client) return ['success' => false, 'error' => 'Stripe client not available'];

    // Pricing from settings (stored as dollars or main currency). Convert to cents
    $monthly = (float) (get_premium_setting('monthly_price', '5.00'));
    $yearly = (float) (get_premium_setting('yearly_price', '50.00'));
    $prices = [
        'monthly' => $monthly,
        'yearly' => $yearly,
        'lifetime' => (float) get_premium_setting('lifetime_price', '150.00')
    ];
    if (!array_key_exists($plan_type, $prices)) $plan_type = 'yearly';

    $amount = round($prices[$plan_type] * 100); // cents
    $currency = strtolower(get_premium_setting('currency', 'usd')) ?: 'usd';

    // Build metadata: ensure all values are strings
    $base_meta = ['user_id' => (string)$user_id, 'plan_type' => (string)$plan_type];
    $extra_meta = [];
    foreach ($extra_metadata as $k => $v) {
        $extra_meta[$k] = is_scalar($v) ? (string)$v : base64_encode(serialize($v));
    }
    $metadata = array_merge($base_meta, $extra_meta);

    // Checkout Session creation with inline price data (no saved Price ID required)
    try {
        $session_args = [
            'payment_method_types' => ['card'],
            'mode' => $plan_type === 'lifetime' ? 'payment' : 'subscription',
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => ['name' => SITE_NAME . " - " . ucfirst($plan_type) . " Premium"],
                    'unit_amount' => $amount,
                ],
                'quantity' => 1
            ]],
            'success_url' => $return_url ?: (BASE_PATH . '/premium.php?session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => BASE_PATH . '/premium.php',
            'metadata' => $metadata
        ];

        // Set recurring only when needed
        if ($plan_type !== 'lifetime') {
            $session_args['line_items'][0]['price_data']['recurring'] = ['interval' => $plan_type === 'monthly' ? 'month' : 'year'];
        }

        // Prefill email if provided
        if (!empty($extra_meta['email'])) {
            $session_args['customer_email'] = $extra_meta['email'];
        }

        $session = $client->checkout->sessions->create($session_args);
        return ['success' => true, 'url' => $session->url];
    } catch (Exception $e) {
        error_log('stripe_create_checkout_session error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Could not create Stripe Checkout Session'];
    }
}

/**
 * Handle a verified Stripe webhook payload (expects $event object from Stripe)
 * This will create/update `premium_subscriptions` rows based on subscription events.
 */
function stripe_handle_event($event) {
    // Only process certain event types
    $type = $event->type ?? '';
    try {
        if ($type === 'checkout.session.completed') {
            $session = $event->data->object;
            // Only do something when metadata contains user_id
            $user_id = intval($session->metadata->user_id ?? 0);
            $plan_type = $session->metadata->plan_type ?? 'monthly';
            $customer = $session->customer ?? null;
            $subscription_id = $session->subscription ?? null; // may be present for subscription mode

            if ($user_id) {
                // If subscription id available, fetch details to compute period
                $start = date('Y-m-d H:i:s');
                $end = null;
                $status = 'active';
                if ($subscription_id && is_stripe_configured()) {
                    $client = get_stripe_client();
                    try {
                        $sub = $client->subscriptions->retrieve($subscription_id, []);
                        if ($sub && isset($sub->current_period_end)) {
                            $end = date('Y-m-d H:i:s', $sub->current_period_end);
                        }
                        $status = $sub->status ?? $status;
                    } catch (Exception $e) {
                        error_log('stripe_handle_event: failed to retrieve subscription ' . $e->getMessage());
                    }
                }

                // Insert or update subscription row, persist Stripe ids separately for easier reconciliation
                $stmt = query("SELECT id FROM premium_subscriptions WHERE user_id = ? LIMIT 1", [$user_id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                $metadata_arr = (array)($session->metadata ?? []);
                $payment_proof = base64_encode(serialize(['customer' => $customer, 'subscription' => $subscription_id, 'metadata' => $metadata_arr]));
                if ($existing) {
                    // update
                    query("UPDATE premium_subscriptions SET plan_type = ?, status = ?, start_date = ?, end_date = ?, payment_method = ?, payment_proof = ?, stripe_customer_id = ?, stripe_subscription_id = ? WHERE id = ?", [$plan_type, $status, $start, $end, 'stripe', $payment_proof, $customer, $subscription_id, $existing['id']]);
                } else {
                    query("INSERT INTO premium_subscriptions (user_id, plan_type, status, start_date, end_date, payment_method, payment_proof, stripe_customer_id, stripe_subscription_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())", [$user_id, $plan_type, $status, $start, $end, 'stripe', $payment_proof, $customer, $subscription_id]);
                }

                // Auto-promote user to 'member' role if subscription active
                if ($status === 'active') {
                    try {
                        query("UPDATE users SET role = 'member', is_approved = 1 WHERE id = ?", [$user_id]);
                        // Remove the rookie badge on premium activation
                        query("DELETE ub FROM user_badges ub JOIN badges b ON ub.badge_id = b.id WHERE ub.user_id = ? AND b.slug = 'yeni-gelen'", [$user_id]);
                        // Grant any tier badges earned while the user was a rookie
                        sync_user_badges_by_likes($user_id);
                        // Send a confirmation email to the user (best-effort)
                        $u = get_user($user_id);
                        if ($u && !empty($u['email'])) {
                            $subj = SITE_NAME . ' - Premium Üyelik Etkinleştirildi';
                            $msg = "Merhaba " . $u['username'] . ",\n\nPremium üyeliğiniz aktifleştirildi. Teşekkürler!\n\nSaygılarımızla,\n" . SITE_NAME;
                            if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
                                send_email($u['email'], $subj, $msg);
                            }
                        }
                    } catch (Exception $e) {
                        error_log('stripe_handle_event: auto-promote failed: ' . $e->getMessage());
                    }
                }
            }
        } elseif ($type === 'invoice.payment_succeeded') {
            // Payment for recurring invoice succeeded — ensure subscription record is active
            $inv = $event->data->object;
            $sub_id = $inv->subscription ?? null;
            if ($sub_id) {
                // Mark matching premium_subscriptions active
                query("UPDATE premium_subscriptions SET status = 'active' WHERE stripe_subscription_id = ?", [$sub_id]);
                // Optionally auto-promote associated user(s)
                $row = query("SELECT user_id FROM premium_subscriptions WHERE stripe_subscription_id = ? LIMIT 1", [$sub_id])->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    query("UPDATE users SET role = 'member', is_approved = 1 WHERE id = ?", [$row['user_id']]);
                    // Remove the rookie badge on premium renewal
                    query("DELETE ub FROM user_badges ub JOIN badges b ON ub.badge_id = b.id WHERE ub.user_id = ? AND b.slug = 'yeni-gelen'", [$row['user_id']]);
                    // Grant any tier badges earned while the user was a rookie
                    sync_user_badges_by_likes($row['user_id']);
                }
            }
        } elseif ($type === 'customer.subscription.deleted' || $type === 'customer.subscription.expired') {
            $sub = $event->data->object;
            $stripe_sub_id = $sub->id ?? null;
            if ($stripe_sub_id) {
                // mark premium_subscriptions with matching subscription id as cancelled
                query("UPDATE premium_subscriptions SET status = 'cancelled' WHERE stripe_subscription_id = ?", [$stripe_sub_id]);
                // Demote user(s) if necessary (optional - we leave role but could demote)
            }
        }
    } catch (Exception $e) {
        error_log('stripe_handle_event error: ' . $e->getMessage());
    }
}
