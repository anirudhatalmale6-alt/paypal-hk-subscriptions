<?php

/**
 * PayPal configuration. Nothing is hardcoded — every value comes from `.env` so
 * the same code runs against sandbox and live by swapping the base URL + keys.
 *
 * .env keys:
 *   PAYPAL_API_BASE        https://api-m.sandbox.paypal.com  (live: https://api-m.paypal.com)
 *   PAYPAL_CLIENT_ID       REST app client id
 *   PAYPAL_CLIENT_SECRET   REST app secret        (keep out of version control)
 *   PAYPAL_WEBHOOK_ID      webhook id from the dashboard (enables signature verify)
 *   PAYPAL_PLAN_ID         only used by the legacy native-subscription path (unused for HK card flow)
 *   PAYPAL_SDK_DOMAINS     comma-separated checkout domain(s) to scope the browser client token
 *   PAYPAL_DEFAULT_SEGMENT default market/product/brand segment (fr-vehicle-history-report)
 *   PAYPAL_CURRENCY        fallback currency (EUR)
 */
return [
    'api_base'        => env('PAYPAL_API_BASE', 'https://api-m.sandbox.paypal.com'),
    'client_id'       => env('PAYPAL_CLIENT_ID', ''),
    'client_secret'   => env('PAYPAL_CLIENT_SECRET', ''),
    'webhook_id'      => env('PAYPAL_WEBHOOK_ID', ''),
    'plan_id'         => env('PAYPAL_PLAN_ID', ''),
    'sdk_domains'     => env('PAYPAL_SDK_DOMAINS', ''),
    'default_segment' => env('PAYPAL_DEFAULT_SEGMENT', 'fr-vehicle-history-report'),
    'currency'        => env('PAYPAL_CURRENCY', 'EUR'),

    // Anti-abuse: a single card/funding source may start at most this many trials.
    'max_trials_per_card' => (int) env('PAYPAL_MAX_TRIALS_PER_CARD', 2),
];
