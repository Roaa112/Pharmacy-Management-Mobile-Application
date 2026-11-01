<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    // Push notifications for health reminders. driver: log | fcm (Firebase HTTP v1).
    'push' => [
        'driver' => env('PUSH_DRIVER', 'log'),
        'fcm_credentials' => env('FCM_CREDENTIALS'),
    ],

    // Paymob online payments. Credentials come only from the environment (never the database or
    // the API). Each environment has its own set; PAYMOB_ENV picks one. See IMPLEMENTATION_REPORT.md.
    'paymob' => [
        'environment' => env('PAYMOB_ENV', 'sandbox'), // sandbox | production
        // intention = Intention API + Unified Checkout (current); legacy = auth -> order -> payment key -> iframe.
        'flow' => env('PAYMOB_FLOW', 'intention'),
        // Regional host, e.g. https://accept.paymob.com (Egypt), https://ksa.paymob.com, https://uae.paymob.com, https://oman.paymob.com.
        'base_url' => env('PAYMOB_BASE_URL', 'https://accept.paymob.com'),
        // Unified Checkout page (intention flow), e.g. https://eg.checkout.paymob.com/ or https://ksa.checkout.paymob.com/.
        'checkout_url' => env('PAYMOB_CHECKOUT_URL', 'https://eg.checkout.paymob.com/'),
        // Paymob currency; must match the integration. The store sells in EGP only.
        'currency' => env('PAYMOB_CURRENCY', 'EGP'),
        // amount_cents = amount in minor units (1/100) x factor. 1 for 2-decimal currencies, 10 for OMR.
        'amount_factor' => (int) env('PAYMOB_AMOUNT_FACTOR', 1),
        // An unpaid online order is canceled (stock released) this long after checkout.
        'payment_ttl_minutes' => (int) env('PAYMOB_PAYMENT_TTL_MINUTES', 60),
        // Confirm every callback with Paymob's transaction API (needs the API key) before acting on it.
        'verify_with_api' => (bool) env('PAYMOB_VERIFY_WITH_API', true),
        // Where the customer lands after paying (app deep link); the result is appended as query parameters.
        'return_url' => env('PAYMOB_RETURN_URL', 'elkawther://payment-result'),
        'timeout' => (int) env('PAYMOB_HTTP_TIMEOUT', 20),
        'sandbox' => [
            'api_key' => env('PAYMOB_SANDBOX_API_KEY'),
            'secret_key' => env('PAYMOB_SANDBOX_SECRET_KEY'),
            'public_key' => env('PAYMOB_SANDBOX_PUBLIC_KEY'),
            'hmac_secret' => env('PAYMOB_SANDBOX_HMAC_SECRET'),
            // Comma-separated integration ids (card, wallet, ...); their test/live mode must match the keys.
            'integration_ids' => env('PAYMOB_SANDBOX_INTEGRATION_IDS'),
            'iframe_id' => env('PAYMOB_SANDBOX_IFRAME_ID'),
            'merchant_id' => env('PAYMOB_SANDBOX_MERCHANT_ID'),
        ],
        'production' => [
            'api_key' => env('PAYMOB_LIVE_API_KEY'),
            'secret_key' => env('PAYMOB_LIVE_SECRET_KEY'),
            'public_key' => env('PAYMOB_LIVE_PUBLIC_KEY'),
            'hmac_secret' => env('PAYMOB_LIVE_HMAC_SECRET'),
            // Comma-separated integration ids (card, wallet, ...); their test/live mode must match the keys.
            'integration_ids' => env('PAYMOB_LIVE_INTEGRATION_IDS'),
            'iframe_id' => env('PAYMOB_LIVE_IFRAME_ID'),
            'merchant_id' => env('PAYMOB_LIVE_MERCHANT_ID'),
        ],
    ],

];
