<?php
declare(strict_types=1);

/**
 * One-time setup: creates the catalog product + billing plan and prints the
 * ids to paste into .env. Idempotent-ish: re-running creates new ids, so run
 * once per environment (sandbox, then live).
 *
 *   php scripts/setup_plan.php
 *
 * Plan: 48h paid trial @ EUR 2.90  ->  EUR 49.50 / month (unlimited).
 * Retries: payment_failure_threshold = 4 so PayPal keeps retrying across
 * several spaced attempts (captures late bank approvals) before suspending.
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../src/PayPalClient.php';

use PayPalHK\PayPalClient;

$cfg    = pp_config();
$client = new PayPalClient($cfg);

// 1. Product
$p = $client->request('POST', '/v1/catalogs/products', [
    'name'        => 'Subscription Membership',
    'description' => 'Monthly subscription with 48h paid trial',
    'type'        => 'SERVICE',
    'category'    => 'SOFTWARE',
]);
$productId = $p['body']['id'] ?? null;
echo "PRODUCT: {$productId} (http {$p['status']})\n";

// 2. Plan
$plan = $client->request('POST', '/v1/billing/plans', [
    'product_id'   => $productId,
    'name'         => 'Monthly Membership (48h trial)',
    'description'  => '48h paid trial at EUR 2.90, then EUR 49.50 per month',
    'status'       => 'ACTIVE',
    'billing_cycles' => [
        [
            'frequency'      => ['interval_unit' => 'DAY', 'interval_count' => 2],
            'tenure_type'    => 'TRIAL',
            'sequence'       => 1,
            'total_cycles'   => 1,
            'pricing_scheme' => ['fixed_price' => ['value' => '2.90', 'currency_code' => 'EUR']],
        ],
        [
            'frequency'      => ['interval_unit' => 'MONTH', 'interval_count' => 1],
            'tenure_type'    => 'REGULAR',
            'sequence'       => 2,
            'total_cycles'   => 0,
            'pricing_scheme' => ['fixed_price' => ['value' => '49.50', 'currency_code' => 'EUR']],
        ],
    ],
    'payment_preferences' => [
        'auto_bill_outstanding'   => true,
        'setup_fee'               => ['value' => '0', 'currency_code' => 'EUR'],
        'setup_fee_failure_action'=> 'CONTINUE',
        'payment_failure_threshold' => 4,
    ],
], ['Prefer: return=representation']);

echo "PLAN:    {$plan['body']['id']} (http {$plan['status']})\n\n";
echo "Add to .env:\n";
echo "PAYPAL_PRODUCT_ID={$productId}\n";
echo "PAYPAL_PLAN_ID={$plan['body']['id']}\n";
