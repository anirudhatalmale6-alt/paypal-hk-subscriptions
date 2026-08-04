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
     * Charge a vaulted payment method via Orders v2 (used for the recurring
     * monthly MIT charges). Works for any vaulted funding source; the
     * $sourceType selects the payment_source key: a saved card / Apple Pay card
     * is 'card', a saved PayPal wallet is 'paypal'.
     *
     * @param string $vaultId    vault payment token id
     * @param string $amount     e.g. "49.50"
     * @param string $mode       'FIRST' (customer-initiated, establishes the
     *                           stored credential) or 'MIT' (merchant-initiated
     *                           recurring, SCA-exempt)
     * @param string $sourceType 'card' | 'paypal'
     * @return array normalised result incl. capture id + decline reason
     */
    public function charge(string $vaultId, string $amount, string $currency, string $mode = 'MIT', array $meta = [], string $sourceType = 'card'): array
    {
        $stored = $mode === 'FIRST'
            ? ['payment_initiator' => 'CUSTOMER', 'payment_type' => 'RECURRING', 'usage' => 'FIRST']
            : ['payment_initiator' => 'MERCHANT', 'payment_type' => 'RECURRING', 'usage' => 'SUBSEQUENT'];

        $key = $sourceType === 'paypal' ? 'paypal' : 'card';

        // Segment tag (country/product/brand) travels with the transaction so it
        // is visible PayPal-side too: reference_id carries the segment code and
        // soft_descriptor sets the bank-statement label for that market.
        $pu = [
            'amount'      => ['currency_code' => $currency, 'value' => $amount],
            'custom_id'   => $meta['custom_id'] ?? null,
            'description' => $meta['description'] ?? 'Membership',
        ];
        if (!empty($meta['segment']))         $pu['reference_id']    = $meta['segment'];
        if (!empty($meta['soft_descriptor'])) $pu['soft_descriptor'] = substr($meta['soft_descriptor'], 0, 22);

        $headers = [
            'Prefer: return=representation',
            // A stable request id can be supplied (e.g. derived from the setup
            // token) so a double-submit is de-duplicated by PayPal and never
            // charges twice; otherwise a fresh id is used per call.
            'PayPal-Request-Id: ' . ($meta['request_id'] ?? 'ord-' . bin2hex(random_bytes(8))),
        ];
        // Fraudnet device correlation: forwarding the browser's client-metadata-id
        // ties the on-page Data Collector telemetry to this charge, which materially
        // lifts cross-border card acceptance. Only meaningful on the FIRST (customer-
        // initiated) charge, where the browser session exists; harmless otherwise.
        if (!empty($meta['cmid'])) $headers[] = 'PayPal-Client-Metadata-Id: ' . $meta['cmid'];

        $res = $this->client->request('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [$pu],
            'payment_source' => [$key => [
                'vault_id'          => $vaultId,
                'stored_credential' => $stored,
            ]],
        ], $headers);

        return $this->normaliseCapture($res);
    }

    /**
     * Create a €X order that ALSO vaults the funding source on success, for the
     * Apple Pay / PayPal wallet buttons. The buyer's approval popup shows only
     * this amount (the trial) - no recurring terms - which keeps the wallet/
     * Apple Pay sheets friction-free; the recurring terms live on the checkout
     * page. After approval the order is captured and the vaulted token is used
     * for the monthly MIT charges.
     *
     * @param string $method 'paypal' | 'apple_pay'
     */
    public function createOrderWithVault(string $amount, string $currency, string $method, array $ctx = []): array
    {
        $source = [
            'attributes' => ['vault' => [
                'store_in_vault' => 'ON_SUCCESS',
                'usage_type'     => 'MERCHANT',
                'customer_type'  => 'CONSUMER',
            ]],
        ];
        if ($method === 'paypal') {
            $source['experience_context'] = [
                'return_url'          => $ctx['return_url'] ?? '',
                'cancel_url'          => $ctx['cancel_url'] ?? '',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action'         => 'PAY_NOW',
                'brand_name'          => $ctx['brand_name'] ?? 'Membership',
            ];
        }

        // Same segment tagging as the card path: reference_id = segment code
        // (read back at capture time to resolve the market), soft_descriptor =
        // that market's bank-statement label.
        $pu = [
            'amount'      => ['currency_code' => $currency, 'value' => $amount],
            'custom_id'   => $ctx['custom_id'] ?? null,
            'description' => $ctx['description'] ?? '48h trial',
        ];
        if (!empty($ctx['segment']))         $pu['reference_id']    = $ctx['segment'];
        if (!empty($ctx['soft_descriptor'])) $pu['soft_descriptor'] = substr($ctx['soft_descriptor'], 0, 22);

        $headers = ['Prefer: return=representation', 'PayPal-Request-Id: vo-' . bin2hex(random_bytes(8))];
        if (!empty($ctx['cmid'])) $headers[] = 'PayPal-Client-Metadata-Id: ' . $ctx['cmid'];

        return $this->client->request('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [$pu],
            'payment_source' => [$method => $source],
        ], $headers);
    }

    /** Capture an approved order (the trial) and surface the vaulted token id. */
    public function captureOrder(string $orderId): array
    {
        $res = $this->client->request('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', null,
            ['Prefer: return=representation']);
        $norm = $this->normaliseCapture($res);

        // The vaulted token id lands under payment_source.<method>.attributes.vault.id.
        $ps = $res['body']['payment_source'] ?? [];
        foreach (['paypal', 'card', 'apple_pay'] as $m) {
            if (!empty($ps[$m]['attributes']['vault']['id'])) {
                $norm['vault_id']    = $ps[$m]['attributes']['vault']['id'];
                $norm['source_type'] = $m === 'paypal' ? 'paypal' : 'card';
                $norm['card']        = $ps[$m] ?? [];
                break;
            }
        }
        return $norm;
    }

    /** Delete a vault payment token (e.g. a card blocked by the trial cap so we
     *  don't retain a card we will never charge). */
    public function deleteVaultToken(string $vaultId): array
    {
        return $this->client->request('DELETE', '/v3/vault/payment-tokens/' . rawurlencode($vaultId));
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
