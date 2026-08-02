<?php
declare(strict_types=1);

namespace PayPalHK;

/**
 * High-level subscription operations built on top of PayPalClient.
 *
 * Covers the full lifecycle: product/plan setup, subscription create,
 * fetch, cancel, suspend, activate, and refund of a captured payment.
 *
 * The white-label card flow (Advanced Card Fields) works like this:
 *   1. Frontend loads the JS SDK v6 with components=card-fields, intent=subscription.
 *   2. Frontend calls createSubscription() -> hits our /api create-subscription,
 *      which calls SubscriptionService::create() and returns the subscription id.
 *   3. The card fields submit() captures the card on-site, runs 3DS, vaults the
 *      card, and attaches it to that subscription. No PAN ever touches our server.
 *   4. onApprove returns the subscription id; we fetch + persist final state and
 *      rely on webhooks for ongoing truth.
 */
class SubscriptionService
{
    public function __construct(private PayPalClient $client) {}

    /**
     * Create a subscription against a plan.
     *
     * @param string $planId       PayPal billing plan id (P-xxxx)
     * @param array  $subscriber   ['name'=>['given_name','surname'],'email_address'=>...]
     * @param array  $opts         custom_id, return_url, cancel_url, metadata id
     * @return array{status:int, body:array, debugId:?string}
     */
    public function create(string $planId, array $subscriber = [], array $opts = []): array
    {
        $payload = [
            'plan_id' => $planId,
            // SCA_WHEN_REQUIRED => 3DS enforced only where PSD2 mandates it (EU/UK),
            // frictionless everywhere else. Best balance of compliance + auth rate.
            'application_context' => [
                'brand_name'          => $opts['brand_name'] ?? 'Membership',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action'         => 'SUBSCRIBE_NOW',
                'payment_method'      => [
                    'payer_selected'  => 'PAYPAL',
                    'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED',
                ],
                'return_url' => $opts['return_url'] ?? 'https://example.com/return',
                'cancel_url' => $opts['cancel_url'] ?? 'https://example.com/cancel',
            ],
        ];
        if ($subscriber) {
            $payload['subscriber'] = $subscriber;
        }
        if (!empty($opts['custom_id'])) {
            $payload['custom_id'] = $opts['custom_id'];
        }

        $headers = ['Prefer: return=representation'];
        // Idempotency: safe to retry the same request without double-creating.
        if (!empty($opts['request_id'])) {
            $headers[] = 'PayPal-Request-Id: ' . $opts['request_id'];
        }
        // Fraudnet correlation: forward the client metadata id captured on the
        // browser so PayPal's risk engine receives the telemetry. Big lever on
        // cross-border acceptance for European cards.
        if (!empty($opts['cmid'])) {
            $headers[] = 'PayPal-Client-Metadata-Id: ' . $opts['cmid'];
        }

        return $this->client->request('POST', '/v1/billing/subscriptions', $payload, $headers);
    }

    /**
     * Generate a client token for the JS SDK. Advanced Card Fields require this
     * short-lived token, passed to the SDK script as data-client-token, before
     * the hosted card fields will initialise.
     */
    public function clientToken(): ?string
    {
        $res = $this->client->request('POST', '/v1/identity/generate-token', null, ['Accept-Language: en_US']);
        return $res['body']['client_token'] ?? null;
    }

    public function get(string $subscriptionId): array
    {
        return $this->client->request('GET', "/v1/billing/subscriptions/{$subscriptionId}");
    }

    public function cancel(string $subscriptionId, string $reason = 'Customer requested cancellation'): array
    {
        return $this->client->request('POST', "/v1/billing/subscriptions/{$subscriptionId}/cancel", ['reason' => $reason]);
    }

    public function suspend(string $subscriptionId, string $reason = 'Suspended'): array
    {
        return $this->client->request('POST', "/v1/billing/subscriptions/{$subscriptionId}/suspend", ['reason' => $reason]);
    }

    public function activate(string $subscriptionId, string $reason = 'Reactivated'): array
    {
        return $this->client->request('POST', "/v1/billing/subscriptions/{$subscriptionId}/activate", ['reason' => $reason]);
    }

    /** List captured transactions for a subscription within a time window. */
    public function transactions(string $subscriptionId, string $startTime, string $endTime): array
    {
        $q = http_build_query(['start_time' => $startTime, 'end_time' => $endTime]);
        return $this->client->request('GET', "/v1/billing/subscriptions/{$subscriptionId}/transactions?{$q}");
    }

    /**
     * Refund a captured payment (sale) by its capture id.
     * Amount optional -> full refund when omitted.
     */
    public function refund(string $captureId, ?string $amount = null, string $currency = 'EUR'): array
    {
        $payload = null;
        if ($amount !== null) {
            $payload = ['amount' => ['value' => $amount, 'currency_code' => $currency]];
        }
        return $this->client->request('POST', "/v2/payments/captures/{$captureId}/refund", $payload);
    }
}
