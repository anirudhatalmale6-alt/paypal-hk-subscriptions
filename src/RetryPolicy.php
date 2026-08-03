<?php
declare(strict_types=1);

namespace PayPalHK;

require_once __DIR__ . '/ResponseCodes.php';

/**
 * The smart-retry / dunning policy for failed recurring (MIT) charges.
 *
 * Encapsulated as pure decision logic (no I/O) so it is unit-testable and the
 * scheduler (run_billing.php) just applies the returned decision. Mirrors the
 * client's specification (2026-08-03):
 *
 *  - Hard decline / authentication required  -> cancel immediately.
 *  - Suspected fraud (9500)                  -> retry ONCE after 7 days; if it
 *                                               is suspected fraud again, cancel.
 *  - Soft decline (incl. unknown)            -> smart-retry schedule:
 *        +2, +3, +4, +5, +5, +6, +7, +7, +7, +7, +7 days  (~60 days / 2 months),
 *        then cancel.
 *  - Insufficient funds (5120)               -> follows the soft schedule, but
 *        after 4 insufficient-funds attempts in a cycle the retry amount drops
 *        to half (e.g. 24.75) for the rest of that cycle. On the next cycle it
 *        returns to the full price. If it fails again for insufficient funds,
 *        the same logic repeats.
 *  - On success after prior failures         -> record the approval time / hour /
 *        attempts so the schedule can be analysed and tuned over time.
 */
final class RetryPolicy
{
    /** Days to wait after each successive SOFT-decline failure (11 retries). */
    public const SOFT_RETRY_DAYS = [2, 3, 4, 5, 5, 6, 7, 7, 7, 7, 7];

    public const INSUFFICIENT_FUNDS_CODE = '5120';
    public const SUSPECTED_FRAUD_CODE    = '9500';

    /** After this many insufficient-funds attempts in a cycle, charge half. */
    public const INSF_HALF_AFTER = 4;

    /** Suspected fraud gets exactly one 7-day retry before cancelling. */
    public const FRAUD_RETRY_DAYS  = 7;
    public const MAX_FRAUD_RETRIES = 1;

    /**
     * Preferred hour of day (server timezone) to schedule retries. Banks are
     * more likely to approve in the local morning (funds posted overnight), so
     * this defaults to the morning. Set the server TZ to the customer base's
     * region (Europe here) or make it per-customer if timezone is known.
     */
    public const RETRY_HOUR = 10;

    /** For insufficient-funds retries, nudge the date forward (up to this many
     *  days) onto a payday-favorable day when accounts are more likely funded. */
    public const PAYDAY_NUDGE_MAX_DAYS = 3;

    /** Amount to charge for this attempt: half once the cycle is in insufficient-funds half-price mode. */
    public static function chargeAmount(array $sub): string
    {
        $full = (float) ($sub['monthly_amount'] ?? 0);
        $insf = (int) ($sub['insf_streak'] ?? 0);
        $value = $insf >= self::INSF_HALF_AFTER ? $full / 2 : $full;
        return number_format($value, 2, '.', '');
    }

    /**
     * Decide what to do after a charge attempt.
     *
     * @param array $sub  subscription state (retry_count, insf_streak, fraud_retries, first_fail_at, monthly_amount)
     * @param array $res  charge result: ['ok'=>bool, 'response_code'=>?string, 'amount'=>?string]
     * @param int   $now  unix timestamp
     * @return array {action: renew|retry|cancel, status, ...state, next_billing_at?, reason?, recovery?}
     */
    public static function decide(array $sub, array $res, int $now): array
    {
        $retry     = (int) ($sub['retry_count'] ?? 0);
        $insf      = (int) ($sub['insf_streak'] ?? 0);
        $fraud     = (int) ($sub['fraud_retries'] ?? 0);
        $firstFail = $sub['first_fail_at'] ?? null;

        // --- Success ---
        if (!empty($res['ok'])) {
            $out = [
                'action'          => 'renew',
                'status'          => 'active',
                'retry_count'     => 0,
                'insf_streak'     => 0,
                'fraud_retries'   => 0,
                'first_fail_at'   => null,
                'next_billing_at' => date('c', strtotime('+1 month', $now)),
            ];
            // Approval-pattern analytics: only meaningful if it recovered after failures.
            if ($retry > 0 || $insf > 0 || $fraud > 0) {
                $out['recovery'] = [
                    'approved_at'           => date('c', $now),
                    'hour'                  => (int) date('G', $now),
                    'weekday'               => date('l', $now),
                    'after_retries'         => $retry,
                    'amount'                => $res['amount'] ?? null,
                    'days_since_first_fail' => $firstFail ? round(($now - strtotime($firstFail)) / 86400, 2) : null,
                ];
            }
            return $out;
        }

        $code = (string) ($res['response_code'] ?? '');
        $cls  = ResponseCodes::classify($code);
        $firstFail = $firstFail ?: date('c', $now);

        // --- Suspected fraud: one 7-day retry, then cancel ---
        if ($code === self::SUSPECTED_FRAUD_CODE) {
            if ($fraud < self::MAX_FRAUD_RETRIES) {
                return [
                    'action'          => 'retry',
                    'status'          => 'past_due',
                    'retry_count'     => $retry,
                    'insf_streak'     => $insf,
                    'fraud_retries'   => $fraud + 1,
                    'first_fail_at'   => $firstFail,
                    'reason'          => 'suspected_fraud_retry',
                    'next_billing_at' => date('c', self::atRetryHour($now + self::FRAUD_RETRY_DAYS * 86400)),
                ];
            }
            return ['action' => 'cancel', 'status' => 'cancelled', 'reason' => 'suspected_fraud'];
        }

        // --- Hard decline / authentication required: cancel immediately ---
        if ($cls['category'] === ResponseCodes::HARD_DECLINE || $cls['category'] === ResponseCodes::AUTH_REQUIRED) {
            return ['action' => 'cancel', 'status' => 'cancelled', 'reason' => $cls['category']];
        }

        // --- Soft decline (or unknown): smart-retry schedule ---
        if ($code === self::INSUFFICIENT_FUNDS_CODE) {
            $insf++;
        }
        if ($retry >= count(self::SOFT_RETRY_DAYS)) {
            return ['action' => 'cancel', 'status' => 'cancelled', 'reason' => 'retries_exhausted'];
        }
        $delayDays = self::SOFT_RETRY_DAYS[$retry];
        $next = self::atRetryHour($now + $delayDays * 86400);
        // Insufficient funds: nudge onto the next payday-favorable day.
        if ($code === self::INSUFFICIENT_FUNDS_CODE) {
            $next = self::nudgeToPayday($next);
        }
        return [
            'action'          => 'retry',
            'status'          => 'past_due',
            'retry_count'     => $retry + 1,
            'insf_streak'     => $insf,
            'fraud_retries'   => $fraud,
            'first_fail_at'   => $firstFail,
            'reason'          => $cls['category'] . ($code === self::INSUFFICIENT_FUNDS_CODE ? '_insufficient_funds' : ''),
            'next_billing_at' => date('c', $next),
        ];
    }

    /** Days most likely to see a funded account: paydays (1st, 15th, month-end)
     *  and Mondays/Fridays. */
    public static function isPaydayFavorable(int $ts): bool
    {
        $dom  = (int) date('j', $ts);
        $last = (int) date('t', $ts);
        $dow  = (int) date('N', $ts); // 1=Mon .. 7=Sun
        return $dom === 1 || $dom === 15 || $dom === $last || $dow === 1 || $dow === 5;
    }

    /** Shift a retry timestamp forward (bounded) onto the next favorable day. */
    public static function nudgeToPayday(int $ts): int
    {
        for ($i = 0; $i <= self::PAYDAY_NUDGE_MAX_DAYS; $i++) {
            $c = self::atRetryHour($ts + $i * 86400);
            if (self::isPaydayFavorable($c)) return $c;
        }
        return $ts;
    }

    /** Snap a timestamp to the preferred retry hour on that day (server TZ). */
    public static function atRetryHour(int $ts): int
    {
        return (int) strtotime(date('Y-m-d', $ts) . sprintf(' %02d:00:00', self::RETRY_HOUR));
    }
}
