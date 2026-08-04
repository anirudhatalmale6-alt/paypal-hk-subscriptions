<?php
declare(strict_types=1);

/**
 * Demo backend router. In Laravel these map to controller actions/routes;
 * the logic lives in the framework-agnostic src/ classes either way.
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../src/PayPalClient.php';
require __DIR__ . '/../src/SubscriptionService.php';
require __DIR__ . '/../src/WebhookVerifier.php';
require __DIR__ . '/../src/WebhookHandler.php';
require __DIR__ . '/../src/ResponseCodes.php';
require __DIR__ . '/../src/VaultRecurring.php';
require __DIR__ . '/../src/Segments.php';
require __DIR__ . '/../src/Attribution.php';
require __DIR__ . '/../src/Store.php';

use PayPalHK\PayPalClient;
use PayPalHK\SubscriptionService;
use PayPalHK\WebhookVerifier;
use PayPalHK\WebhookHandler;
use PayPalHK\ResponseCodes;
use PayPalHK\VaultRecurring;
use PayPalHK\Segments;
use PayPalHK\Attribution;
use PayPalHK\Store;
use PayPalHK\PayPalException;

header('Content-Type: application/json');

$cfg    = pp_config();
$logFile = __DIR__ . '/../storage/paypal.log';
$logger = function (string $level, string $msg, array $ctx) use ($logFile) {
    $line = sprintf("[%s] %s %s %s\n", date('c'), strtoupper($level), $msg, json_encode($ctx, JSON_UNESCAPED_SLASHES));
    @file_put_contents($logFile, $line, FILE_APPEND);
};

$client  = new PayPalClient($cfg, $logger);
$service = new SubscriptionService($client);
$vault   = new VaultRecurring($client);
$store   = new Store(__DIR__ . '/../storage/subscriptions.json');
$action  = $_GET['action'] ?? '';

// Anti-abuse: a single card may start at most this many trials. Beyond it, the
// trial-to-subscription flow is blocked for that card.
$MAX_TRIALS_PER_CARD = 2;

// Resolve the market/product/brand segment for this request. Price points,
// currency, trial length, labels and the statement descriptor all come from the
// segment, so a new market is one registry entry (see src/Segments.php). Defaults
// to FR Vehicle History Report. Callers may pass ?segment= or {"segment":...}.
$resolveSegment = static function (array $in): array {
    $code = $in['segment'] ?? ($_GET['segment'] ?? null);
    return Segments::resolve(is_string($code) ? $code : null);
};

// Stable customer key so LTV / retention / cohorts / churn can group a person's
// activity across multiple products and subscriptions. Prefer the client app's
// authenticated user id (customer_id) — the most reliable key; fall back to a
// hashed email. Prefixed so the two key spaces never collide.
$customerRef = static function (?string $customerId, ?string $email): ?string {
    if (is_string($customerId) && $customerId !== '') return 'u:' . substr(hash('sha256', $customerId), 0, 24);
    if (is_string($email) && trim($email) !== '')      return 'e:' . substr(hash('sha256', strtolower(trim($email))), 0, 24);
    return null;
};

// The analytics metadata stamped on every subscription so the future dashboard
// can compute LTV / retention / cohorts / churn broken down by country, product
// and acquisition source — captured from day one, no data-model redesign later.
$analyticsMeta = static function (array $in, ?string $email, int $now) use ($customerRef): array {
    $cid = (isset($in['customer_id']) && is_string($in['customer_id']) && $in['customer_id'] !== '') ? $in['customer_id'] : null;
    $acq = Attribution::fromInput($in);
    return [
        'customer_id'  => $cid,
        'customer_ref' => $customerRef($cid, $email),
        'cohort'       => date('Y-m', $now),   // signup month, for cohort/retention
        'acquisition'  => $acq,                 // full first-touch attribution
        'acq_source'   => $acq['source'],       // flattened for direct grouping
        'acq_channel'  => $acq['channel'],
        'acq_medium'   => $acq['medium'],
    ];
};

/** Stable, non-reversible fingerprint of a card from its vault metadata. */
$cardFingerprint = static function (array $card): string {
    $parts = [
        strtolower($card['brand'] ?? ''),
        $card['last_digits'] ?? '',
        $card['expiry'] ?? '',           // "YYYY-MM"
    ];
    return substr(hash('sha256', implode('|', $parts)), 0, 32);
};

try {
    switch ($action) {

        // Step 1: browser asks for a vault setup token to render card fields against.
        case 'create-setup-token':
            $res = $vault->createSetupToken();
            if ($res['status'] >= 400) {
                http_response_code($res['status']);
                echo json_encode(['error' => 'setup token failed', 'debug_id' => $res['debugId'], 'detail' => $res['body']]);
                break;
            }
            echo json_encode(['id' => $res['body']['id'] ?? null]);
            break;

        // Step 3-4: card vaulted in browser -> exchange token, charge trial,
        // create the local subscription with its first billing date.
        case 'finalize':
            $in          = json_decode(file_get_contents('php://input'), true) ?: [];
            $setupTokenId = $in['setup_token'] ?? '';
            $email        = $in['email'] ?? null;
            if (!$setupTokenId) {
                http_response_code(400);
                echo json_encode(['error' => 'missing setup_token']);
                break;
            }

            // Market/product/brand segment drives price, currency and descriptor.
            $seg            = $resolveSegment($in);
            $TRIAL_AMOUNT   = $seg['trial_amount'];
            $MONTHLY_AMOUNT = $seg['monthly_amount'];
            $CURRENCY       = $seg['currency'];
            $TRIAL_HOURS    = $seg['trial_hours'];

            // --- Idempotency (point #5): if this setup token was already
            // finalized, return the existing subscription instead of charging
            // again (guards against double-submit / browser retry). ---
            if ($existing = $store->findBySetupToken($setupTokenId)) {
                echo json_encode([
                    'subscription_id' => $existing['id'],
                    'status'          => $existing['status'],
                    'segment'         => $existing['segment'] ?? $seg['code'],
                    'trial_charged'   => $TRIAL_AMOUNT . ' ' . $CURRENCY,
                    'next_billing'    => $existing['next_billing_at'],
                    'monthly'         => $MONTHLY_AMOUNT . ' ' . $CURRENCY,
                    'idempotent'      => true,
                ]);
                break;
            }

            // Exchange setup token -> permanent vault token.
            $ex = $vault->confirmSetupToken($setupTokenId);
            $vaultId = $ex['body']['id'] ?? null;
            if (!$vaultId) {
                http_response_code($ex['status']);
                echo json_encode(['error' => 'vaulting failed', 'debug_id' => $ex['debugId'], 'detail' => $ex['body']]);
                break;
            }

            // --- Per-card trial cap (anti-abuse): fingerprint the card from the
            // vault token's (authoritative) metadata and block if it has already
            // bought the max number of trials. We block BEFORE charging, and
            // delete the just-created vault token so a blocked card leaves
            // nothing stored. ---
            $vaultCard = $ex['body']['payment_source']['card'] ?? [];
            $cardFp    = $cardFingerprint($vaultCard);
            $priorTrials = $store->countTrialsByCard($cardFp);
            if ($cardFp !== '' && $priorTrials >= $MAX_TRIALS_PER_CARD) {
                $vault->deleteVaultToken($vaultId);
                $logger('warning', 'Trial blocked: per-card limit reached', ['card_fp' => $cardFp, 'prior' => $priorTrials, 'last4' => $vaultCard['last_digits'] ?? null]);
                http_response_code(409);
                echo json_encode([
                    'error'        => 'trial_limit_reached',
                    'message'      => 'This card has already been used for the maximum number of trials.',
                    'prior_trials' => $priorTrials,
                ]);
                break;
            }

            // Charge the trial as the first (customer-initiated) transaction.
            // subId is generated first so it can be attached as custom_id -> lets
            // webhooks (disputes/refunds) map a transaction back to the sub.
            // Stable request id => PayPal de-dupes a concurrent double-submit.
            $subId = 'sub_' . bin2hex(random_bytes(6));
            $charge = $vault->charge($vaultId, $TRIAL_AMOUNT, $CURRENCY, 'FIRST', [
                'description'     => Segments::label($seg) . ' - 48h trial',
                'custom_id'       => $subId,
                'segment'         => $seg['code'],
                'soft_descriptor' => $seg['soft_descriptor'] ?? null,
                'request_id'      => 'trial-' . substr(hash('sha256', $setupTokenId), 0, 24),
            ]);
            $cls = ResponseCodes::classify($charge['response_code']);

            $now = time();
            $sub = [
                'id'              => $subId,
                'vault_id'        => $vaultId,
                'source_type'     => 'card',                  // funding source for MIT
                'setup_token'     => $setupTokenId,           // idempotency key
                'card_fp'         => $cardFp,                 // per-card trial cap
                'card_last4'      => $vaultCard['last_digits'] ?? null,
                'card_brand'      => $vaultCard['brand'] ?? null,
                'email'           => $email,
                'currency'        => $CURRENCY,
                'monthly_amount'  => $MONTHLY_AMOUNT,
                // Analytics dimensions: country / product / brand kept separate
                // from the start for clean per-market dashboard metrics.
                'segment'         => $seg['code'],
                'country'         => $seg['country'],
                'product'         => $seg['product'],
                'brand'           => $seg['brand'],
                'product_label'   => $seg['label'],
                'soft_descriptor' => $seg['soft_descriptor'] ?? null,
                'status'          => $charge['ok'] ? 'active' : 'payment_failed',
                'created_at'      => date('c', $now),
                // After the 48h trial, first monthly charge is due.
                'next_billing_at' => date('c', $now + $TRIAL_HOURS * 3600),
                'charges'         => [[
                    'type' => 'trial', 'amount' => $TRIAL_AMOUNT, 'ok' => $charge['ok'],
                    'capture_id' => $charge['capture_id'], 'response_code' => $charge['response_code'],
                    'response_label' => $cls['label'], 'category' => $cls['category'], 'retryable' => $cls['retryable'],
                    'decline' => $charge['decline_detail'], 'debug_id' => $charge['debug_id'], 'at' => date('c', $now),
                ]],
            ];
            // Identity + cohort + acquisition metadata for the dashboard.
            $sub = array_merge($sub, $analyticsMeta($in, $email, $now));
            if ($charge['ok']) $sub['activated_at'] = date('c', $now);
            $store->create($sub);

            if (!$charge['ok']) {
                http_response_code(402);
                echo json_encode(['error' => 'trial charge declined', 'subscription_id' => $subId, 'decline' => $charge['decline_detail'], 'debug_id' => $charge['debug_id']]);
                break;
            }
            echo json_encode([
                'subscription_id'   => $subId,          // OUR internal record id
                'status'            => 'active',
                'segment'           => $seg['code'],
                'trial_charged'     => $TRIAL_AMOUNT . ' ' . $CURRENCY,
                'next_billing'      => $sub['next_billing_at'],
                'monthly'           => $MONTHLY_AMOUNT . ' ' . $CURRENCY,
                // PayPal-side references so the payment can be found in the
                // dashboard / via API (this is the real transaction, not sub_*).
                'paypal_capture_id' => $charge['capture_id'],
                'paypal_order_id'   => $charge['order_id'],
                'vault_id'          => $vaultId,
            ]);
            break;

        // --- Apple Pay / PayPal wallet: create a trial-amount order that vaults
        // the funding source on success. The buyer's popup/sheet shows ONLY the
        // trial amount (no recurring terms) per requirement; recurring is driven
        // by our engine afterwards. ---
        case 'create-wallet-order':
            $in     = json_decode(file_get_contents('php://input'), true) ?: [];
            $seg    = $resolveSegment($in);
            $method = ($in['method'] ?? 'paypal') === 'apple_pay' ? 'apple_pay' : 'paypal';
            $subId  = 'sub_' . bin2hex(random_bytes(6)); // carried via custom_id
            $res = $vault->createOrderWithVault($seg['trial_amount'], $seg['currency'], $method, [
                'custom_id'       => $subId,
                'description'     => Segments::label($seg) . ' - 48h trial',
                'segment'         => $seg['code'],           // read back at capture
                'soft_descriptor' => $seg['soft_descriptor'] ?? null,
                'return_url'      => $in['return_url'] ?? '',
                'cancel_url'      => $in['cancel_url'] ?? '',
                'brand_name'      => $in['brand_name'] ?? Segments::label($seg),
            ]);
            if ($res['status'] >= 400) {
                http_response_code($res['status']);
                echo json_encode(['error' => 'order create failed', 'debug_id' => $res['debugId'], 'detail' => $res['body']]);
                break;
            }
            $approve = null;
            foreach ($res['body']['links'] ?? [] as $l) {
                if (in_array($l['rel'] ?? '', ['approve', 'payer-action'], true)) $approve = $l['href'];
            }
            echo json_encode(['order_id' => $res['body']['id'] ?? null, 'approve_link' => $approve, 'method' => $method]);
            break;

        // Capture an approved wallet/Apple Pay order, vault the source, create
        // the subscription. Same trial-cap + analytics as the card flow.
        case 'capture-order':
            $in      = json_decode(file_get_contents('php://input'), true) ?: [];
            $orderId = $in['order_id'] ?? '';
            if (!$orderId) { http_response_code(400); echo json_encode(['error' => 'missing order_id']); break; }

            $cap = $vault->captureOrder($orderId);
            if (!$cap['ok'] || empty($cap['vault_id'])) {
                http_response_code(402);
                echo json_encode(['error' => 'capture_or_vault_failed', 'decline' => $cap['decline_detail'], 'debug_id' => $cap['debug_id']]);
                break;
            }
            $vaultId    = $cap['vault_id'];
            $sourceType = $cap['source_type'] ?? 'card';
            $psource    = $cap['raw']['payment_source'] ?? [];

            // Segment travels on the order's reference_id (set at create time),
            // so the market/product/brand is resolved authoritatively here even
            // without trusting the client to resend it.
            $refSeg = $cap['raw']['purchase_units'][0]['reference_id'] ?? null;
            $seg    = Segments::resolve(is_string($refSeg) ? $refSeg : ($in['segment'] ?? null));

            // Fingerprint for the per-account trial cap: card meta for a saved
            // card/Apple Pay, payer identity for a saved PayPal wallet.
            if ($sourceType === 'paypal') {
                $payer   = $psource['paypal'] ?? [];
                $cardFp  = substr(hash('sha256', 'paypal|' . ($payer['email_address'] ?? $payer['account_id'] ?? $vaultId)), 0, 32);
                $last4   = null;
                $brand   = 'PAYPAL';
            } else {
                $cardMeta = $psource['card'] ?? [];
                $cardFp   = $cardFingerprint($cardMeta);
                $last4    = $cardMeta['last_digits'] ?? null;
                $brand    = $cardMeta['brand'] ?? null;
            }

            if ($cardFp !== '' && $store->countTrialsByCard($cardFp) >= $MAX_TRIALS_PER_CARD) {
                $vault->deleteVaultToken($vaultId);
                http_response_code(409);
                echo json_encode(['error' => 'trial_limit_reached', 'message' => 'This payment method has already been used for the maximum number of trials.']);
                break;
            }

            // Reuse the sub id we stamped as custom_id at order creation.
            $subId = $cap['raw']['purchase_units'][0]['custom_id'] ?? ('sub_' . bin2hex(random_bytes(6)));
            $cls   = ResponseCodes::classify($cap['response_code']);
            $now   = time();
            $sub = [
                'id'              => $subId,
                'vault_id'        => $vaultId,
                'source_type'     => $sourceType,
                'order_id'        => $orderId,
                'card_fp'         => $cardFp,
                'card_last4'      => $last4,
                'card_brand'      => $brand,
                'email'           => $psource['paypal']['email_address'] ?? ($in['email'] ?? null),
                'currency'        => $seg['currency'],
                'monthly_amount'  => $seg['monthly_amount'],
                'segment'         => $seg['code'],
                'country'         => $seg['country'],
                'product'         => $seg['product'],
                'brand'           => $seg['brand'],
                'product_label'   => $seg['label'],
                'soft_descriptor' => $seg['soft_descriptor'] ?? null,
                'status'          => 'active',
                'created_at'      => date('c', $now),
                'next_billing_at' => date('c', $now + $seg['trial_hours'] * 3600),
                'charges'         => [[
                    'type' => 'trial', 'amount' => $seg['trial_amount'], 'ok' => true,
                    'capture_id' => $cap['capture_id'], 'response_code' => $cap['response_code'],
                    'response_label' => $cls['label'], 'category' => $cls['category'], 'retryable' => $cls['retryable'],
                    'decline' => $cap['decline_detail'], 'debug_id' => $cap['debug_id'], 'at' => date('c', $now),
                ]],
            ];
            // Identity + cohort + acquisition metadata for the dashboard (wallet
            // path uses the PayPal-supplied email when the app didn't pass one).
            $sub = array_merge($sub, $analyticsMeta($in, $sub['email'], $now));
            $sub['activated_at'] = date('c', $now);
            $store->create($sub);
            echo json_encode([
                'subscription_id'   => $subId,
                'status'            => 'active',
                'segment'           => $seg['code'],
                'trial_charged'     => $seg['trial_amount'] . ' ' . $seg['currency'],
                'next_billing'      => $sub['next_billing_at'],
                'monthly'           => $seg['monthly_amount'] . ' ' . $seg['currency'],
                'method'            => $sourceType,
                'paypal_capture_id' => $cap['capture_id'],
                'paypal_order_id'   => $orderId,
                'vault_id'          => $vaultId,
            ]);
            break;

        case 'create-subscription':
            $in   = json_decode(file_get_contents('php://input'), true) ?: [];
            $cmid = $in['cmid'] ?? null;
            if (!$cfg['plan_id']) {
                http_response_code(500);
                echo json_encode(['error' => 'PAYPAL_PLAN_ID not configured']);
                break;
            }
            $res = $service->create(
                $cfg['plan_id'],
                [], // subscriber details optional; PayPal collects from card
                [
                    'brand_name' => 'Membership',
                    'request_id' => bin2hex(random_bytes(12)), // idempotency
                    'cmid'       => $cmid,                      // Fraudnet correlation
                    'custom_id'  => 'demo-' . time(),
                ]
            );
            if ($res['status'] >= 400) {
                http_response_code($res['status']);
                echo json_encode(['error' => 'PayPal rejected subscription create', 'debug_id' => $res['debugId'], 'detail' => $res['body']]);
                break;
            }
            echo json_encode(['id' => $res['body']['id'] ?? null, 'status' => $res['body']['status'] ?? null]);
            break;

        case 'get':
            $sub = $_GET['sub'] ?? '';
            $res = $service->get($sub);
            http_response_code($res['status']);
            echo json_encode($res['body']);
            break;

        case 'cancel':
            $sub = $_GET['sub'] ?? '';
            $res = $service->cancel($sub);
            echo json_encode(['ok' => $res['status'] < 300, 'status' => $res['status']]);
            break;

        case 'webhook':
            // Inbound PayPal webhook. Verify signature, dispatch, ack 200 fast.
            $raw      = file_get_contents('php://input');
            $verifier = new WebhookVerifier($client, $cfg['webhook_id']);
            $ok       = $cfg['webhook_id'] ? $verifier->verify(getallheaders(), $raw) : false;
            $event    = json_decode($raw, true) ?: [];
            $logger('info', 'Webhook received', ['verified' => $ok, 'type' => $event['event_type'] ?? '?', 'id' => $event['id'] ?? '?']);

            // Only act on verified events in production. (When no webhook id is
            // configured - e.g. local demo - we skip verification but still
            // dispatch so the handler can be exercised.)
            if ($ok || !$cfg['webhook_id']) {
                $handler = new WebhookHandler($store, $logger);
                $result  = $handler->handle($event);
                $logger('info', 'Webhook handled', $result);
                http_response_code(200);
                echo json_encode(['received' => true] + $result);
            } else {
                // Signature failed - do not act; 400 so PayPal retries/flags.
                http_response_code(400);
                echo json_encode(['received' => false, 'error' => 'signature_verification_failed']);
            }
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'unknown action']);
    }
} catch (PayPalException $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage(), 'detail' => $e->paypalBody]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
