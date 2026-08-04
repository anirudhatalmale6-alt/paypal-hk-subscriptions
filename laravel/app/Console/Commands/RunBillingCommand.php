<?php

namespace App\Console\Commands;

use App\PayPal\EloquentStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PayPalHK\PayPalClient;
use PayPalHK\ResponseCodes;
use PayPalHK\RetryPolicy;
use PayPalHK\VaultRecurring;

/**
 * Recurring billing driver — the Laravel equivalent of scripts/run_billing.php.
 *
 * Schedule it hourly (see PayPalServiceProvider::boot, or a direct crontab):
 *   0 * * * *  php /path/artisan paypal:run-billing
 *
 * For every subscription whose charge is due it charges the vaulted funding source
 * as a merchant-initiated transaction, records the classified result, and applies
 * the smart-retry / dunning policy (RetryPolicy). All decision logic is unchanged
 * from the demo — only the store (DB) and the logger (Laravel Log) differ.
 */
class RunBillingCommand extends Command
{
    protected $signature = 'paypal:run-billing';
    protected $description = 'Charge due subscriptions (MIT) and apply the smart-retry/dunning policy';

    public function handle(EloquentStore $store): int
    {
        $cfg = config('paypal');
        $logger = fn (string $lvl, string $m, array $c) => Log::channel('stack')->log(
            $lvl === 'warning' ? 'warning' : 'info', "[paypal] $m", $c
        );

        $client = new PayPalClient($cfg, $logger);
        $vault  = new VaultRecurring($client);

        $now = time();
        $due = $store->due($now);
        $this->info('Due now: ' . count($due));

        foreach ($due as $sub) {
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
                    'status' => 'active', 'retry_count' => 0, 'insf_streak' => 0, 'fraud_retries' => 0,
                    'first_fail_at' => null, 'next_billing_at' => $d['next_billing_at'], 'last_charge_at' => date('c', $now),
                ];
                if (!empty($d['recovery'])) {
                    $patch['last_recovery'] = $d['recovery'];
                    $store->appendEvent($sub['id'], ['type' => 'recovered'] + $d['recovery']);
                    $logger('info', 'Payment recovered after retries', ['sub' => $sub['id']] + $d['recovery']);
                }
                $store->update($sub['id'], $patch);
                $this->line("  {$sub['id']}: CHARGED {$amount} {$sub['currency']} (cap {$res['capture_id']})");

            } elseif ($d['action'] === 'cancel') {
                $store->update($sub['id'], ['status' => 'cancelled', 'cancel_reason' => $d['reason'], 'cancelled_at' => date('c', $now)]);
                $store->appendEvent($sub['id'], ['type' => 'cancelled', 'reason' => $d['reason'], 'at' => date('c', $now)]);
                $logger('warning', 'Subscription cancelled by retry policy', ['sub' => $sub['id'], 'reason' => $d['reason']]);
                $this->line("  {$sub['id']}: CANCELLED ({$d['reason']})");

            } else { // retry
                $store->update($sub['id'], [
                    'status' => 'past_due', 'retry_count' => $d['retry_count'], 'insf_streak' => $d['insf_streak'],
                    'fraud_retries' => $d['fraud_retries'], 'first_fail_at' => $d['first_fail_at'], 'next_billing_at' => $d['next_billing_at'],
                ]);
                $this->line("  {$sub['id']}: DECLINED ({$d['reason']}) - next retry {$d['next_billing_at']}");
            }
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
