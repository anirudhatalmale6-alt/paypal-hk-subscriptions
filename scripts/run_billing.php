<?php
declare(strict_types=1);

/**
 * Recurring billing driver. Run on a schedule (cron, every hour):
 *
 *   * * * * *  php /path/scripts/run_billing.php   # (hourly in practice)
 *
 * For every subscription whose next charge is due, it charges the vaulted card
 * as a merchant-initiated transaction and applies the retry / dunning policy.
 *
 * Retry policy (per client: keep retrying, banks often approve on attempt 5-6):
 *   - On success: schedule next charge one month out, reset retry counter.
 *   - On failure: keep the subscription in 'past_due' and retry after
 *     RETRY_INTERVAL_HOURS, up to MAX_RETRIES attempts, before suspending.
 *   - Every attempt logs the decline reason + PayPal-Debug-Id for analytics.
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../src/PayPalClient.php';
require __DIR__ . '/../src/VaultRecurring.php';
require __DIR__ . '/../src/ResponseCodes.php';
require __DIR__ . '/../src/Store.php';

use PayPalHK\PayPalClient;
use PayPalHK\VaultRecurring;
use PayPalHK\ResponseCodes;
use PayPalHK\Store;

const MAX_RETRIES          = 8;   // keep chasing late bank approvals
const RETRY_INTERVAL_HOURS = 48;  // spacing between retries

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
    $retries = $sub['retry_count'] ?? 0;
    $res = $vault->charge($sub['vault_id'], $sub['monthly_amount'], $sub['currency'], 'MIT', [
        'custom_id'   => $sub['id'],
        'description' => 'Monthly membership',
    ], $sub['source_type'] ?? 'card');

    $cls = ResponseCodes::classify($res['response_code']);
    $store->appendCharge($sub['id'], [
        'type' => 'recurring', 'amount' => $sub['monthly_amount'], 'ok' => $res['ok'],
        'capture_id' => $res['capture_id'], 'response_code' => $res['response_code'],
        'response_label' => $cls['label'], 'category' => $cls['category'], 'retryable' => $cls['retryable'],
        'decline' => $res['decline_detail'], 'debug_id' => $res['debug_id'], 'at' => date('c', $now),
    ]);

    if ($res['ok']) {
        $store->update($sub['id'], [
            'status'          => 'active',
            'retry_count'     => 0,
            'next_billing_at' => date('c', strtotime('+1 month', $now)),
            'last_charge_at'  => date('c', $now),
        ]);
        echo "  {$sub['id']}: CHARGED {$sub['monthly_amount']} {$sub['currency']} (cap {$res['capture_id']})\n";
    } else {
        $retries++;
        if ($retries >= MAX_RETRIES) {
            $store->update($sub['id'], ['status' => 'suspended', 'retry_count' => $retries]);
            echo "  {$sub['id']}: SUSPENDED after {$retries} attempts ({$res['decline_detail']})\n";
        } else {
            $store->update($sub['id'], [
                'status'          => 'past_due',
                'retry_count'     => $retries,
                'next_billing_at' => date('c', $now + RETRY_INTERVAL_HOURS * 3600),
            ]);
            echo "  {$sub['id']}: DECLINED ({$res['decline_detail']}) - retry {$retries}/" . MAX_RETRIES . " in " . RETRY_INTERVAL_HOURS . "h\n";
        }
    }
}
echo "Done.\n";
