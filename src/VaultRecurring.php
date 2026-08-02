<?php
declare(strict_types=1);

namespace PayPalHK;

/**
 * Card-funded recurring engine for merchant countries where PayPal's
 * Subscriptions API does not support card funding (e.g. Hong Kong).
 *
 * Flow:
 *   1. createSetupToken()          -> server creates a vault setup token
 *   2. [browser card fields save]  -> customer enters card, 3DS runs, card is
 *                                     tokenised into the setup token (SAQ A)
 *   3. confirmSetupToken()         -> exchange setup token for a permanent
 *                                     vault payment token (vault_id)
 *   4. charge(vault_id, ..., FIRST)-> first (customer-initiated) charge: the
 *                                     trial. Establishes the stored credential.
 *   5. charge(vault_id, ..., MIT)  -> subsequent merchant-initiated recurring
 *                                     charges (SCA-exempt). Driven by scheduler.
 */
class VaultRecurring
{
    public function __construct(private PayPalClient $client) {}

    /** Step 1: create an empty card vault setup token for the browser to fill. */
    public function createSetupToken(): array
    {
        $res = $this->client->request('POST', '/v3/vault/setup-tokens', [
            'payment_source' => [
                'card' => ['attributes' => ['verification' => ['method' => 'SCA_WHEN_REQUIRED']]],
            ],
        ], ['Prefer: return=representation', 'PayPal-Request-Id: st-' . bin2hex(random_bytes(8))]);
        return $res;
    }

    /** Step 3: exchange an approved setup token for a permanent vault token. */
    public function confirmSetupToken(string $setupTokenId): array
    {
        $res = $this->client->request('POST', '/v3/vault/payment-tokens', [
            'payment_source' => ['token' => ['id' => $setupTokenId, 'type' => 'SETUP_TOKEN']],
        ], ['Prefer: return=representation', 'PayPal-Request-Id: pt-' . bin2hex(random_bytes(8))]);
        return $res;
    }

    /**
     * Charge a vaulted card via Orders v2.
     *
     * @param string $vaultId  vault payment token id
     * @param string $amount   e.g. "49.50"
     * @param string $mode     'FIRST' (customer-initiated, establishes credential)
     *                         or 'MIT' (merchant-initiated recurring, SCA-exempt)
     * @return array normalised result incl. capture id + decline reason
     */
    public function charge(string $vaultId, string $amount, string $currency, string $mode = 'MIT', array $meta = []): array
    {
        $stored = $mode === 'FIRST'
            ? ['payment_initiator' => 'CUSTOMER', 'payment_type' => 'RECURRING', 'usage' => 'FIRST']
            : ['payment_initiator' => 'MERCHANT', 'payment_type' => 'RECURRING', 'usage' => 'SUBSEQUENT'];

        $res = $this->client->request('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount'      => ['currency_code' => $currency, 'value' => $amount],
                'custom_id'   => $meta['custom_id'] ?? null,
                'description' => $meta['description'] ?? 'Membership',
            ]],
            'payment_source' => ['card' => [
                'vault_id'          => $vaultId,
                'stored_credential' => $stored,
            ]],
        ], [
            'Prefer: return=representation',
            'PayPal-Request-Id: ord-' . bin2hex(random_bytes(8)),
        ]);

        return $this->normaliseCapture($res);
    }

    /**
     * Flatten an Orders capture response into the fields the recurring engine
     * and the decline-analytics layer care about.
     */
    private function normaliseCapture(array $res): array
    {
        $b       = $res['body'];
        $status  = $b['status'] ?? null;
        $capture = $b['purchase_units'][0]['payments']['captures'][0] ?? null;
        $procResp = $capture['processor_response'] ?? [];

        return [
            'http'            => $res['status'],
            'ok'              => $res['status'] < 300 && $status === 'COMPLETED',
            'order_id'        => $b['id'] ?? null,
            'status'          => $status,
            'capture_id'      => $capture['id'] ?? null,
            'debug_id'        => $res['debugId'],
            // Decline analytics: everything needed to optimise later.
            'response_code'   => $procResp['response_code']   ?? null,
            'avs_code'        => $procResp['avs_code']         ?? null,
            'cvv_code'        => $procResp['cvv_code']         ?? null,
            'decline_detail'  => $b['details'][0]['issue']     ?? null,
            'raw'             => $b,
        ];
    }
}
