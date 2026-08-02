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
require __DIR__ . '/../src/VaultRecurring.php';
require __DIR__ . '/../src/Store.php';

use PayPalHK\PayPalClient;
use PayPalHK\SubscriptionService;
use PayPalHK\WebhookVerifier;
use PayPalHK\VaultRecurring;
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

// Plan figures (would come from config/DB in the Laravel app)
$TRIAL_AMOUNT   = '2.90';
$MONTHLY_AMOUNT = '49.50';
$CURRENCY       = $cfg['currency'];
$TRIAL_HOURS    = 48;

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

            // Exchange setup token -> permanent vault token.
            $ex = $vault->confirmSetupToken($setupTokenId);
            $vaultId = $ex['body']['id'] ?? null;
            if (!$vaultId) {
                http_response_code($ex['status']);
                echo json_encode(['error' => 'vaulting failed', 'debug_id' => $ex['debugId'], 'detail' => $ex['body']]);
                break;
            }

            // Charge the trial as the first (customer-initiated) transaction.
            $charge = $vault->charge($vaultId, $TRIAL_AMOUNT, $CURRENCY, 'FIRST', ['description' => '48h trial']);

            $subId = 'sub_' . bin2hex(random_bytes(6));
            $now   = time();
            $sub = [
                'id'              => $subId,
                'vault_id'        => $vaultId,
                'email'           => $email,
                'currency'        => $CURRENCY,
                'monthly_amount'  => $MONTHLY_AMOUNT,
                'status'          => $charge['ok'] ? 'active' : 'payment_failed',
                'created_at'      => date('c', $now),
                // After the 48h trial, first monthly charge is due.
                'next_billing_at' => date('c', $now + $TRIAL_HOURS * 3600),
                'charges'         => [[
                    'type' => 'trial', 'amount' => $TRIAL_AMOUNT, 'ok' => $charge['ok'],
                    'capture_id' => $charge['capture_id'], 'response_code' => $charge['response_code'],
                    'decline' => $charge['decline_detail'], 'debug_id' => $charge['debug_id'], 'at' => date('c', $now),
                ]],
            ];
            $store->create($sub);

            if (!$charge['ok']) {
                http_response_code(402);
                echo json_encode(['error' => 'trial charge declined', 'subscription_id' => $subId, 'decline' => $charge['decline_detail'], 'debug_id' => $charge['debug_id']]);
                break;
            }
            echo json_encode([
                'subscription_id' => $subId,
                'status'          => 'active',
                'trial_charged'   => $TRIAL_AMOUNT . ' ' . $CURRENCY,
                'next_billing'    => $sub['next_billing_at'],
                'monthly'         => $MONTHLY_AMOUNT . ' ' . $CURRENCY,
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
            // Inbound PayPal webhook. Verify signature, persist, ack 200 fast.
            $raw      = file_get_contents('php://input');
            $verifier = new WebhookVerifier($client, $cfg['webhook_id']);
            $ok       = $cfg['webhook_id'] ? $verifier->verify(getallheaders(), $raw) : false;
            $event    = json_decode($raw, true) ?: [];
            $logger('info', 'Webhook received', ['verified' => $ok, 'type' => $event['event_type'] ?? '?', 'id' => $event['id'] ?? '?']);
            // TODO (M2): idempotent handler dispatch on $event['event_type'].
            http_response_code(200);
            echo json_encode(['received' => true]);
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
