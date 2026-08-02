<?php
declare(strict_types=1);

namespace PayPalHK;

/**
 * Verifies inbound webhook authenticity via PayPal's
 * /v1/notifications/verify-webhook-signature endpoint.
 *
 * Usage (framework-agnostic):
 *   $verifier = new WebhookVerifier($client, $webhookId);
 *   $ok = $verifier->verify(getallheaders(), file_get_contents('php://input'));
 *
 * Always return HTTP 200 quickly once persisted so PayPal does not re-deliver;
 * do heavy processing asynchronously. Handlers must be idempotent (dedupe on
 * the event id).
 */
class WebhookVerifier
{
    public function __construct(private PayPalClient $client, private string $webhookId) {}

    public function verify(array $headers, string $rawBody): bool
    {
        // Header names arrive with varying case depending on the SAPI.
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }

        $payload = [
            'auth_algo'         => $h['paypal-auth-algo']         ?? '',
            'cert_url'          => $h['paypal-cert-url']          ?? '',
            'transmission_id'   => $h['paypal-transmission-id']   ?? '',
            'transmission_sig'  => $h['paypal-transmission-sig']  ?? '',
            'transmission_time' => $h['paypal-transmission-time'] ?? '',
            'webhook_id'        => $this->webhookId,
            'webhook_event'     => json_decode($rawBody, true),
        ];

        $res = $this->client->request('POST', '/v1/notifications/verify-webhook-signature', $payload);
        return ($res['body']['verification_status'] ?? '') === 'SUCCESS';
    }

    /**
     * The events this integration listens for, and what each drives.
     * (Registered against the webhook in the PayPal dashboard or via API.)
     */
    public const EVENTS = [
        'BILLING.SUBSCRIPTION.ACTIVATED'  => 'mark subscription active / grant access',
        'BILLING.SUBSCRIPTION.CREATED'    => 'record pending subscription',
        'PAYMENT.SALE.COMPLETED'          => 'trial or recurring charge succeeded -> extend paid period',
        'PAYMENT.SALE.DENIED'             => 'charge failed -> mark past_due (retries continue)',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => 'a scheduled payment failed -> track before retries exhaust',
        'BILLING.SUBSCRIPTION.CANCELLED'  => 'revoke access',
        'BILLING.SUBSCRIPTION.SUSPENDED'  => 'revoke access (retries exhausted)',
        'BILLING.SUBSCRIPTION.EXPIRED'    => 'subscription ended',
    ];
}
