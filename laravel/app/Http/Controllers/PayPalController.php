<?php

namespace App\Http\Controllers;

use App\PayPal\EloquentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PayPalHK\Attribution;
use PayPalHK\PayPalClient;
use PayPalHK\PayPalException;
use PayPalHK\ResponseCodes;
use PayPalHK\Segments;
use PayPalHK\VaultRecurring;
use PayPalHK\WebhookHandler;
use PayPalHK\WebhookVerifier;

/**
 * The checkout + webhook endpoints — Laravel equivalent of public/api.php. Each
 * former `?action=` becomes a controller method (see routes/paypal.php). The
 * payment logic is untouched; only the transport (Request/JsonResponse) and the
 * store (DB) differ.
 */
class PayPalController extends Controller
{
    private array $cfg;
    private PayPalClient $client;
    private VaultRecurring $vault;

    public function __construct(private EloquentStore $store)
    {
        $this->cfg = config('paypal');
        $logger = fn (string $lvl, string $m, array $c) => Log::log($lvl === 'warning' ? 'warning' : 'info', "[paypal] $m", $c);
        $this->client = new PayPalClient($this->cfg, $logger);
        $this->vault  = new VaultRecurring($this->client);
    }

    /** Step 1: browser asks for a vault setup token to render the card fields against. */
    public function createSetupToken(): JsonResponse
    {
        $res = $this->vault->createSetupToken();
        if ($res['status'] >= 400) {
            return response()->json(['error' => 'setup token failed', 'debug_id' => $res['debugId'], 'detail' => $res['body']], $res['status']);
        }
        return response()->json(['id' => $res['body']['id'] ?? null]);
    }

    /** Step 3-4: card vaulted in browser -> exchange token, charge trial, create the subscription. */
    public function finalize(Request $request): JsonResponse
    {
        $in = $request->json()->all();
        $setupTokenId = $in['setup_token'] ?? '';
        $email = $in['email'] ?? null;
        if (!$setupTokenId) {
            return response()->json(['error' => 'missing setup_token'], 400);
        }

        $seg = $this->resolveSegment($in);
        [$TRIAL, $MONTHLY, $CURRENCY, $HOURS] = [$seg['trial_amount'], $seg['monthly_amount'], $seg['currency'], $seg['trial_hours']];

        // Idempotency: a re-submitted finalize returns the existing record, no re-charge.
        if ($existing = $this->store->findBySetupToken($setupTokenId)) {
            return response()->json([
                'subscription_id' => $existing['id'], 'status' => $existing['status'],
                'segment' => $existing['segment'] ?? $seg['code'], 'trial_charged' => "$TRIAL $CURRENCY",
                'next_billing' => $existing['next_billing_at'], 'monthly' => "$MONTHLY $CURRENCY", 'idempotent' => true,
            ]);
        }

        $ex = $this->vault->confirmSetupToken($setupTokenId);
        $vaultId = $ex['body']['id'] ?? null;
        if (!$vaultId) {
            return response()->json(['error' => 'vaulting failed', 'debug_id' => $ex['debugId'], 'detail' => $ex['body']], $ex['status']);
        }

        // Per-card trial cap (anti-abuse) — block BEFORE charging, delete the token.
        $vaultCard = $ex['body']['payment_source']['card'] ?? [];
        $cardFp = $this->cardFingerprint($vaultCard);
        $prior = $this->store->countTrialsByCard($cardFp);
        if ($cardFp !== '' && $prior >= $this->cfg['max_trials_per_card']) {
            $this->vault->deleteVaultToken($vaultId);
            return response()->json(['error' => 'trial_limit_reached', 'message' => 'This card has already been used for the maximum number of trials.', 'prior_trials' => $prior], 409);
        }

        $subId = 'sub_' . bin2hex(random_bytes(6));
        $charge = $this->vault->charge($vaultId, $TRIAL, $CURRENCY, 'FIRST', [
            'description' => Segments::label($seg) . ' - 48h trial', 'custom_id' => $subId,
            'segment' => $seg['code'], 'soft_descriptor' => $seg['soft_descriptor'] ?? null,
            'request_id' => 'trial-' . substr(hash('sha256', $setupTokenId), 0, 24),
        ]);
        $cls = ResponseCodes::classify($charge['response_code']);

        $now = time();
        $sub = [
            'id' => $subId, 'vault_id' => $vaultId, 'source_type' => 'card', 'setup_token' => $setupTokenId,
            'card_fp' => $cardFp, 'card_last4' => $vaultCard['last_digits'] ?? null, 'card_brand' => $vaultCard['brand'] ?? null,
            'email' => $email, 'currency' => $CURRENCY, 'monthly_amount' => $MONTHLY,
            'segment' => $seg['code'], 'country' => $seg['country'], 'product' => $seg['product'], 'brand' => $seg['brand'],
            'product_label' => $seg['label'], 'soft_descriptor' => $seg['soft_descriptor'] ?? null,
            'status' => $charge['ok'] ? 'active' : 'payment_failed', 'created_at' => date('c', $now),
            'next_billing_at' => date('c', $now + $HOURS * 3600),
            'charges' => [[
                'type' => 'trial', 'amount' => $TRIAL, 'ok' => $charge['ok'], 'capture_id' => $charge['capture_id'],
                'response_code' => $charge['response_code'], 'response_label' => $cls['label'], 'category' => $cls['category'],
                'retryable' => $cls['retryable'], 'decline' => $charge['decline_detail'], 'debug_id' => $charge['debug_id'], 'at' => date('c', $now),
            ]],
        ];
        $sub = array_merge($sub, $this->analyticsMeta($in, $email, $now));
        if ($charge['ok']) $sub['activated_at'] = date('c', $now);
        $this->store->create($sub);

        if (!$charge['ok']) {
            return response()->json(['error' => 'trial charge declined', 'subscription_id' => $subId, 'decline' => $charge['decline_detail'], 'debug_id' => $charge['debug_id']], 402);
        }
        return response()->json([
            'subscription_id' => $subId, 'status' => 'active', 'segment' => $seg['code'],
            'trial_charged' => "$TRIAL $CURRENCY", 'next_billing' => $sub['next_billing_at'], 'monthly' => "$MONTHLY $CURRENCY",
            'paypal_capture_id' => $charge['capture_id'], 'paypal_order_id' => $charge['order_id'], 'vault_id' => $vaultId,
        ]);
    }

    /** Apple Pay / PayPal wallet: create a trial-amount order that vaults the source on success. */
    public function createWalletOrder(Request $request): JsonResponse
    {
        $in = $request->json()->all();
        $seg = $this->resolveSegment($in);
        $method = ($in['method'] ?? 'paypal') === 'apple_pay' ? 'apple_pay' : 'paypal';
        $subId = 'sub_' . bin2hex(random_bytes(6));
        $res = $this->vault->createOrderWithVault($seg['trial_amount'], $seg['currency'], $method, [
            'custom_id' => $subId, 'description' => Segments::label($seg) . ' - 48h trial',
            'segment' => $seg['code'], 'soft_descriptor' => $seg['soft_descriptor'] ?? null,
            'return_url' => $in['return_url'] ?? '', 'cancel_url' => $in['cancel_url'] ?? '',
            'brand_name' => $in['brand_name'] ?? Segments::label($seg),
        ]);
        if ($res['status'] >= 400) {
            return response()->json(['error' => 'order create failed', 'debug_id' => $res['debugId'], 'detail' => $res['body']], $res['status']);
        }
        $approve = null;
        foreach ($res['body']['links'] ?? [] as $l) {
            if (in_array($l['rel'] ?? '', ['approve', 'payer-action'], true)) $approve = $l['href'];
        }
        return response()->json(['order_id' => $res['body']['id'] ?? null, 'approve_link' => $approve, 'method' => $method]);
    }

    /** Capture an approved wallet/Apple Pay order, vault the source, create the subscription. */
    public function captureOrder(Request $request): JsonResponse
    {
        $in = $request->json()->all();
        $orderId = $in['order_id'] ?? '';
        if (!$orderId) return response()->json(['error' => 'missing order_id'], 400);

        $cap = $this->vault->captureOrder($orderId);
        if (!$cap['ok'] || empty($cap['vault_id'])) {
            return response()->json(['error' => 'capture_or_vault_failed', 'decline' => $cap['decline_detail'], 'debug_id' => $cap['debug_id']], 402);
        }
        $vaultId = $cap['vault_id'];
        $sourceType = $cap['source_type'] ?? 'card';
        $psource = $cap['raw']['payment_source'] ?? [];

        // Segment travels on the order's reference_id (set at create), resolved authoritatively here.
        $refSeg = $cap['raw']['purchase_units'][0]['reference_id'] ?? null;
        $seg = Segments::resolve(is_string($refSeg) ? $refSeg : ($in['segment'] ?? null));

        if ($sourceType === 'paypal') {
            $payer = $psource['paypal'] ?? [];
            $cardFp = substr(hash('sha256', 'paypal|' . ($payer['email_address'] ?? $payer['account_id'] ?? $vaultId)), 0, 32);
            [$last4, $brand] = [null, 'PAYPAL'];
        } else {
            $cardMeta = $psource['card'] ?? [];
            $cardFp = $this->cardFingerprint($cardMeta);
            [$last4, $brand] = [$cardMeta['last_digits'] ?? null, $cardMeta['brand'] ?? null];
        }

        if ($cardFp !== '' && $this->store->countTrialsByCard($cardFp) >= $this->cfg['max_trials_per_card']) {
            $this->vault->deleteVaultToken($vaultId);
            return response()->json(['error' => 'trial_limit_reached', 'message' => 'This payment method has already been used for the maximum number of trials.'], 409);
        }

        $subId = $cap['raw']['purchase_units'][0]['custom_id'] ?? ('sub_' . bin2hex(random_bytes(6)));
        $cls = ResponseCodes::classify($cap['response_code']);
        $now = time();
        $sub = [
            'id' => $subId, 'vault_id' => $vaultId, 'source_type' => $sourceType, 'order_id' => $orderId,
            'card_fp' => $cardFp, 'card_last4' => $last4, 'card_brand' => $brand,
            'email' => $psource['paypal']['email_address'] ?? ($in['email'] ?? null),
            'currency' => $seg['currency'], 'monthly_amount' => $seg['monthly_amount'],
            'segment' => $seg['code'], 'country' => $seg['country'], 'product' => $seg['product'], 'brand' => $seg['brand'],
            'product_label' => $seg['label'], 'soft_descriptor' => $seg['soft_descriptor'] ?? null,
            'status' => 'active', 'created_at' => date('c', $now), 'next_billing_at' => date('c', $now + $seg['trial_hours'] * 3600),
            'charges' => [[
                'type' => 'trial', 'amount' => $seg['trial_amount'], 'ok' => true, 'capture_id' => $cap['capture_id'],
                'response_code' => $cap['response_code'], 'response_label' => $cls['label'], 'category' => $cls['category'],
                'retryable' => $cls['retryable'], 'decline' => $cap['decline_detail'], 'debug_id' => $cap['debug_id'], 'at' => date('c', $now),
            ]],
        ];
        $sub = array_merge($sub, $this->analyticsMeta($in, $sub['email'], $now));
        $sub['activated_at'] = date('c', $now);
        $this->store->create($sub);
        return response()->json([
            'subscription_id' => $subId, 'status' => 'active', 'segment' => $seg['code'],
            'trial_charged' => $seg['trial_amount'] . ' ' . $seg['currency'], 'next_billing' => $sub['next_billing_at'],
            'monthly' => $seg['monthly_amount'] . ' ' . $seg['currency'], 'method' => $sourceType,
            'paypal_capture_id' => $cap['capture_id'], 'paypal_order_id' => $orderId, 'vault_id' => $vaultId,
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $res = (new \PayPalHK\SubscriptionService($this->client))->get($request->query('sub', ''));
        return response()->json($res['body'], $res['status']);
    }

    public function cancel(Request $request): JsonResponse
    {
        $res = (new \PayPalHK\SubscriptionService($this->client))->cancel($request->query('sub', ''));
        return response()->json(['ok' => $res['status'] < 300, 'status' => $res['status']]);
    }

    /** Inbound PayPal webhook. Verify signature, dispatch, ack 200 fast. CSRF-exempt route. */
    public function webhook(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $verifier = new WebhookVerifier($this->client, $this->cfg['webhook_id']);
        $ok = $this->cfg['webhook_id'] ? $verifier->verify($this->headers($request), $raw) : false;
        $event = json_decode($raw, true) ?: [];
        Log::info('[paypal] webhook received', ['verified' => $ok, 'type' => $event['event_type'] ?? '?', 'id' => $event['id'] ?? '?']);

        if ($ok || !$this->cfg['webhook_id']) {
            $logger = fn (string $lvl, string $m, array $c) => Log::log($lvl === 'warning' ? 'warning' : 'info', "[paypal] $m", $c);
            $handler = new WebhookHandler($this->store, $logger);
            $result = $handler->handle($event);
            return response()->json(['received' => true] + $result, 200);
        }
        return response()->json(['received' => false, 'error' => 'signature_verification_failed'], 400);
    }

    // ---------------------------------------------------------------- helpers ---

    private function resolveSegment(array $in): array
    {
        $code = $in['segment'] ?? $this->cfg['default_segment'] ?? null;
        return Segments::resolve(is_string($code) ? $code : null);
    }

    private function customerRef(?string $customerId, ?string $email): ?string
    {
        if (is_string($customerId) && $customerId !== '') return 'u:' . substr(hash('sha256', $customerId), 0, 24);
        if (is_string($email) && trim($email) !== '') return 'e:' . substr(hash('sha256', strtolower(trim($email))), 0, 24);
        return null;
    }

    private function analyticsMeta(array $in, ?string $email, int $now): array
    {
        $cid = (isset($in['customer_id']) && is_string($in['customer_id']) && $in['customer_id'] !== '') ? $in['customer_id'] : null;
        $acq = Attribution::fromInput($in);
        return [
            'customer_id' => $cid, 'customer_ref' => $this->customerRef($cid, $email),
            'cohort' => date('Y-m', $now), 'acquisition' => $acq,
            'acq_source' => $acq['source'], 'acq_channel' => $acq['channel'], 'acq_medium' => $acq['medium'],
        ];
    }

    private function cardFingerprint(array $card): string
    {
        return substr(hash('sha256', implode('|', [
            strtolower($card['brand'] ?? ''), $card['last_digits'] ?? '', $card['expiry'] ?? '',
        ])), 0, 32);
    }

    /** @return array<string,string> header lines for the webhook verifier. */
    private function headers(Request $request): array
    {
        $out = [];
        foreach ($request->headers->all() as $k => $v) {
            $out[$k] = is_array($v) ? ($v[0] ?? '') : $v;
        }
        return $out;
    }
}
