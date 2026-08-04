<?php
declare(strict_types=1);

/**
 * Recurring billing driver. Run on a schedule (cron, hourly):
 *
 *   0 * * * *  php /path/scripts/run_billing.php
 *
 * For every subscription whose charge is due it charges the vaulted funding
 * source (card / Apple Pay card / PayPal wallet) as a merchant-initiated
 * transaction, then applies the smart-retry / dunning policy in RetryPolicy.
 *
 * Policy summary (see src/RetryPolicy.php for the authoritative spec):
 *   - success                     -> next charge +1 month; if it recovered after
 *                                     failures, log the approval time/hour/attempts
 *   - hard decline / auth required -> cancel immediately
 *   - suspected fraud             -> one 7-day retry, then cancel
 *   - soft decline                -> +2,+3,+4,+5,+5,+6,+7,+7,+7,+7,+7 days then cancel
 *   - insufficient funds          -> soft schedule; after 4 attempts, half price
 *                                     for the rest of the cycle
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../src/PayPalClient.php';
require __DIR__ . '/../src/VaultRecurring.php';
require __DIR__ . '/../src/ResponseCodes.php';
require __DIR__ . '/../src/RetryPolicy.php';
require __DIR__ . '/../src/Store.php';

use PayPalHK\PayPalClient;
use PayPalHK\VaultRecurring;
use PayPalHK\ResponseCodes;
use PayPalHK\RetryPolicy;
use PayPalHK\Store;

$cfg    = pp_config();
$logf   = __DIR__ . '/../storage/billing.log';
$logger = fn(string $lvl, string $m, array $c) => file_put_contents($logf,
    sprintf("[%s] %s %s %s\n", date('c'), strtoupper($lvl), $m, json_encode($c)), FILE_APPEND);

$client = new PayPalClient($cfg, $logger);
$vault  = new VaultRecurring($client);
$store  = new Store(__DIR__ . '/../storage/subscriptions.json');

$now = time();
$due = $store->due($now);
echo "Due now: " . count($due) . "\n";

foreach ($due as $sub) {
    // Amount for this attempt (drops to half once a cycle is in insufficient-funds mode).
    $amount = RetryPolicy::chargeAmount($sub);

    $res = $vault->charge($sub['vault_id'], $amount, $sub['currency'], 'MIT', [
        'custom_id'       => $sub['id'],
        'description'     => ($sub['product_label'] ?? 'Membership') . ' - monthly',
        'segment'         => $sub['segment'] ?? null,
        'soft_descriptor' => $sub['soft_descriptor'] ?? null,
    ], $sub['source_type'] ?? 'card');
    $res['amount'] = $amount;

    $cls = ResponseCodes::classify($res['response_code']);
    $store->appendCharge($sub['id'], [
        'type' => 'recurring', 'amount' => $amount, 'ok' => $res['ok'],
        'capture_id' => $res['capture_id'], 'response_code' => $res['response_code'],
        'response_label' => $cls['label'], 'category' => $cls['category'], 'retryable' => $cls['retryable'],
        'decline' => $res['decline_detail'], 'debug_id' => $res['debug_id'], 'at' => date('c', $now),
    ]);

    $d = RetryPolicy::decide($sub, $res, $now);

    if ($d['action'] === 'renew') {
        $patch = [
            'status'          => 'active',
            'retry_count'     => 0,
            'insf_streak'     => 0,
            'fraud_retries'   => 0,
            'first_fail_at'   => null,
            'next_billing_at' => $d['next_billing_at'],
            'last_charge_at'  => date('c', $now),
        ];
        if (!empty($d['recovery'])) {
            $patch['last_recovery'] = $d['recovery'];
            $store->appendEvent($sub['id'], ['type' => 'recovered'] + $d['recovery']);
            $logger('info', 'Payment recovered after retries', ['sub' => $sub['id']] + $d['recovery']);
        }
        $store->update($sub['id'], $patch);
        echo "  {$sub['id']}: CHARGED {$amount} {$sub['currency']} (cap {$res['capture_id']})"
            . (!empty($d['recovery']) ? " [recovered after {$d['recovery']['after_retries']} retries]" : "") . "\n";

    } elseif ($d['action'] === 'cancel') {
        $store->update($sub['id'], ['status' => 'cancelled', 'cancel_reason' => $d['reason'], 'cancelled_at' => date('c', $now)]);
        $store->appendEvent($sub['id'], ['type' => 'cancelled', 'reason' => $d['reason'], 'at' => date('c', $now)]);
        $logger('warning', 'Subscription cancelled by retry policy', ['sub' => $sub['id'], 'reason' => $d['reason']]);
        echo "  {$sub['id']}: CANCELLED ({$d['reason']})\n";

    } else { // retry
        $store->update($sub['id'], [
            'status'          => 'past_due',
            'retry_count'     => $d['retry_count'],
            'insf_streak'     => $d['insf_streak'],
            'fraud_retries'   => $d['fraud_retries'],
            'first_fail_at'   => $d['first_fail_at'],
            'next_billing_at' => $d['next_billing_at'],
        ]);
        echo "  {$sub['id']}: DECLINED ({$d['reason']}) - next retry {$d['next_billing_at']}\n";
    }
}
echo "Done.\n";
