<?php
declare(strict_types=1);

namespace PayPalHK;

/**
 * Maps PayPal / card-network processor response codes (the
 * `processor_response.response_code` returned on an Orders v2 capture) to a
 * human label, a category, and a "retryable" flag.
 *
 * The retryable flag is what the smart-retry engine keys off:
 *   - soft_decline  (retryable)      -> issuer *might* approve later (funds,
 *                                       do-not-honor, generic). Keep retrying;
 *                                       these are the ones a bank often clears
 *                                       on the 5th/6th attempt.
 *   - hard_decline  (NOT retryable)  -> a permanent condition (expired, closed,
 *                                       stolen, invalid). Retrying wastes
 *                                       attempts and can raise the decline
 *                                       ratio; suspend instead.
 *   - authentication_required        -> issuer wants SCA/3DS again. Cannot be
 *                                       silently retried as an MIT.
 *   - approved                       -> success.
 *   - unknown                        -> treated as soft/retryable by default so
 *                                       we never give up early on an
 *                                       undocumented code (still capped by the
 *                                       scheduler's MAX_RETRIES).
 *
 * Category constants are stable strings so they can drive both the retry logic
 * and the decline-analytics dashboard.
 */
final class ResponseCodes
{
    public const APPROVED                = 'approved';
    public const SOFT_DECLINE            = 'soft_decline';
    public const HARD_DECLINE            = 'hard_decline';
    public const AUTH_REQUIRED           = 'authentication_required';
    public const UNKNOWN                 = 'unknown';

    /**
     * code => [human label, category, retryable]
     * Source: PayPal Advanced Credit/Debit Card processor response codes.
     */
    private const MAP = [
        '0000' => ['Approved',                              self::APPROVED,      false],
        '00N7' => ['CVV2 failure (retry with CVV)',         self::HARD_DECLINE,  false],
        '0500' => ['Do not honor',                          self::SOFT_DECLINE,  true],
        '0580' => ['Unauthorized transaction',              self::HARD_DECLINE,  false],
        '1330' => ['Invalid account',                       self::HARD_DECLINE,  false],
        '5100' => ['Generic decline',                       self::SOFT_DECLINE,  true],
        '5110' => ['CVV2 failure',                          self::HARD_DECLINE,  false],
        '5120' => ['Insufficient funds',                    self::SOFT_DECLINE,  true],
        '5130' => ['Invalid PIN',                           self::HARD_DECLINE,  false],
        '5140' => ['Card closed',                           self::HARD_DECLINE,  false],
        '5150' => ['Pickup card (special conditions)',      self::HARD_DECLINE,  false],
        '5160' => ['Decline (do not retry)',                self::HARD_DECLINE,  false],
        '5180' => ['Invalid or restricted card',            self::HARD_DECLINE,  false],
        '5200' => ['Duplicate transaction',                 self::HARD_DECLINE,  false],
        '5400' => ['Expired card',                          self::HARD_DECLINE,  false],
        '5500' => ['Incorrect PIN, re-enter',               self::HARD_DECLINE,  false],
        '5650' => ['Declined - SCA required',               self::AUTH_REQUIRED, false],
        '5700' => ['Transaction not permitted',             self::HARD_DECLINE,  false],
        '5800' => ['Reversal rejected',                     self::HARD_DECLINE,  false],
        '5900' => ['Invalid issue',                         self::HARD_DECLINE,  false],
        '5930' => ['Card not activated',                    self::HARD_DECLINE,  false],
        '6300' => ['Account not on file',                   self::HARD_DECLINE,  false],
        '9500' => ['Suspected fraud',                       self::HARD_DECLINE,  false],
        '9510' => ['Security violation',                    self::HARD_DECLINE,  false],
        '9520' => ['Lost or stolen card',                   self::HARD_DECLINE,  false],
        '9540' => ['Decline (do not honor)',                self::SOFT_DECLINE,  true],
    ];

    /**
     * Classify a processor response code.
     *
     * @return array{code:string,label:string,category:string,retryable:bool,known:bool}
     */
    public static function classify(?string $code): array
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return ['code' => '', 'label' => 'No response code', 'category' => self::UNKNOWN, 'retryable' => true, 'known' => false];
        }
        if (isset(self::MAP[$code])) {
            [$label, $category, $retryable] = self::MAP[$code];
            return ['code' => $code, 'label' => $label, 'category' => $category, 'retryable' => $retryable, 'known' => true];
        }
        // Undocumented code: default to soft/retryable so we don't give up early.
        return ['code' => $code, 'label' => 'Unmapped decline (' . $code . ')', 'category' => self::UNKNOWN, 'retryable' => true, 'known' => false];
    }

    /** Convenience: is this code safe to auto-retry as a merchant-initiated charge? */
    public static function isRetryable(?string $code): bool
    {
        $c = self::classify($code);
        return $c['category'] !== self::HARD_DECLINE
            && $c['category'] !== self::AUTH_REQUIRED
            && $c['category'] !== self::APPROVED
            && $c['retryable'];
    }
}
