<?php
declare(strict_types=1);

namespace PayPalHK;

/**
 * Applies the side effects of a verified PayPal webhook event to the local
 * subscription store. Framework-agnostic; in Laravel this becomes a queued
 * listener. Every branch is idempotent (dedupe on the event id) so a redelivery
 * never double-applies.
 *
 * Events handled for the vault + MIT card model:
 *   PAYMENT.CAPTURE.COMPLETED  -> reconcile a successful charge (info only; the
 *                                 engine already records its own captures)
 *   PAYMENT.CAPTURE.DENIED     -> log an out-of-band denied capture
 *   PAYMENT.CAPTURE.REFUNDED   -> annotate the subscription with the refund
 *   PAYMENT.CAPTURE.REVERSED   -> money reversed -> suspend
 *   CUSTOMER.DISPUTE.CREATED   -> chargeback opened -> SUSPEND (per requirement)
 *   CUSTOMER.DISPUTE.RESOLVED  -> annotate the dispute outcome
 *   VAULT.PAYMENT-TOKEN.DELETED-> stored card removed -> cannot bill -> suspend
 */
class WebhookHandler
{
    /** @var callable|null */
    private $logger;

    public function __construct(private Store $store, ?callable $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @return array{handled:bool, action:string, event_type:?string, subscription_id:?string}
     */
    public function handle(array $event): array
    {
        $type     = $event['event_type'] ?? null;
        $eventId  = $event['id'] ?? '';
        $resource = $event['resource'] ?? [];

        // Idempotency: skip if we've already processed this event id.
        if (!$this->store->markEventProcessed($eventId)) {
            return ['handled' => false, 'action' => 'duplicate_ignored', 'event_type' => $type, 'subscription_id' => null];
        }

        switch ($type) {
            case 'CUSTOMER.DISPUTE.CREATED':
                return $this->onDisputeCreated($resource);

            case 'CUSTOMER.DISPUTE.RESOLVED':
            case 'CUSTOMER.DISPUTE.UPDATED':
                return $this->onDisputeUpdated($type, $resource);

            case 'PAYMENT.CAPTURE.REVERSED':
                return $this->onCaptureReversed($resource);

            case 'PAYMENT.CAPTURE.REFUNDED':
                return $this->onCaptureRefunded($resource);

            case 'PAYMENT.CAPTURE.DENIED':
                return $this->onCaptureDenied($resource);

            case 'PAYMENT.CAPTURE.COMPLETED':
                return $this->reconcileCapture($resource);

            case 'VAULT.PAYMENT-TOKEN.DELETED':
                return $this->onVaultTokenDeleted($resource);

            default:
                $this->log('info', 'Unhandled webhook type', ['type' => $type]);
                return ['handled' => false, 'action' => 'unhandled', 'event_type' => $type, 'subscription_id' => null];
        }
    }

    /** Chargeback / dispute opened -> suspend the subscription immediately. */
    private function onDisputeCreated(array $r): array
    {
        $sub = $this->subFromDispute($r);
        if (!$sub) {
            return $this->miss('CUSTOMER.DISPUTE.CREATED');
        }
        $this->store->update($sub['id'], ['status' => 'suspended', 'suspended_reason' => 'dispute']);
        $this->store->appendEvent($sub['id'], [
            'type'       => 'dispute_created',
            'dispute_id' => $r['dispute_id'] ?? ($r['id'] ?? null),
            'reason'     => $r['reason'] ?? ($r['dispute_reason'] ?? null),
            'amount'     => $r['dispute_amount']['value'] ?? null,
            'at'         => date('c'),
        ]);
        $this->log('warning', 'Subscription suspended by dispute', ['sub' => $sub['id'], 'dispute' => $r['dispute_id'] ?? null]);
        return ['handled' => true, 'action' => 'suspended_dispute', 'event_type' => 'CUSTOMER.DISPUTE.CREATED', 'subscription_id' => $sub['id']];
    }

    private function onDisputeUpdated(string $type, array $r): array
    {
        $sub = $this->subFromDispute($r);
        if (!$sub) return $this->miss($type);
        $this->store->appendEvent($sub['id'], [
            'type'     => strtolower(str_replace('CUSTOMER.DISPUTE.', 'dispute_', $type)),
            'dispute_id' => $r['dispute_id'] ?? null,
            'outcome'  => $r['dispute_outcome']['outcome_code'] ?? ($r['status'] ?? null),
            'at'       => date('c'),
        ]);
        return ['handled' => true, 'action' => 'dispute_annotated', 'event_type' => $type, 'subscription_id' => $sub['id']];
    }

    /** Capture reversed (funds pulled back) -> suspend. */
    private function onCaptureReversed(array $r): array
    {
        $sub = $this->store->findByCaptureId($r['id'] ?? '');
        if (!$sub) return $this->miss('PAYMENT.CAPTURE.REVERSED');
        $this->store->update($sub['id'], ['status' => 'suspended', 'suspended_reason' => 'reversal']);
        $this->store->appendEvent($sub['id'], ['type' => 'capture_reversed', 'capture_id' => $r['id'] ?? null, 'at' => date('c')]);
        return ['handled' => true, 'action' => 'suspended_reversal', 'event_type' => 'PAYMENT.CAPTURE.REVERSED', 'subscription_id' => $sub['id']];
    }

    /** Capture refunded -> annotate (a refund alone does not cancel the plan). */
    private function onCaptureRefunded(array $r): array
    {
        // A refund resource references the original capture via its links / custom_id.
        $captureId = $this->captureIdFromRefund($r);
        $sub = $captureId ? $this->store->findByCaptureId($captureId) : null;
        if (!$sub && !empty($r['custom_id'])) {
            $sub = $this->store->get((string) $r['custom_id']);
        }
        if (!$sub) return $this->miss('PAYMENT.CAPTURE.REFUNDED');
        $this->store->appendEvent($sub['id'], [
            'type'      => 'capture_refunded',
            'refund_id' => $r['id'] ?? null,
            'amount'    => $r['amount']['value'] ?? null,
            'at'        => date('c'),
        ]);
        return ['handled' => true, 'action' => 'refund_annotated', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'subscription_id' => $sub['id']];
    }

    private function onCaptureDenied(array $r): array
    {
        $sub = $this->store->findByCaptureId($r['id'] ?? '');
        $this->log('warning', 'Capture denied webhook', ['capture' => $r['id'] ?? null, 'sub' => $sub['id'] ?? null]);
        if ($sub) {
            $this->store->appendEvent($sub['id'], ['type' => 'capture_denied', 'capture_id' => $r['id'] ?? null, 'at' => date('c')]);
        }
        return ['handled' => (bool) $sub, 'action' => 'capture_denied_logged', 'event_type' => 'PAYMENT.CAPTURE.DENIED', 'subscription_id' => $sub['id'] ?? null];
    }

    private function reconcileCapture(array $r): array
    {
        $sub = $this->store->findByCaptureId($r['id'] ?? '');
        // The engine records its own captures synchronously; this is just a
        // reconciliation hook (no state change needed if we already have it).
        return ['handled' => (bool) $sub, 'action' => 'capture_reconciled', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'subscription_id' => $sub['id'] ?? null];
    }

    /** Stored card deleted at PayPal -> we can no longer bill it -> suspend. */
    private function onVaultTokenDeleted(array $r): array
    {
        $sub = $this->store->findByVaultId($r['id'] ?? '');
        if (!$sub) return $this->miss('VAULT.PAYMENT-TOKEN.DELETED');
        $this->store->update($sub['id'], ['status' => 'suspended', 'suspended_reason' => 'vault_deleted']);
        $this->store->appendEvent($sub['id'], ['type' => 'vault_deleted', 'vault_id' => $r['id'] ?? null, 'at' => date('c')]);
        return ['handled' => true, 'action' => 'suspended_vault_deleted', 'event_type' => 'VAULT.PAYMENT-TOKEN.DELETED', 'subscription_id' => $sub['id']];
    }

    // --- helpers ---

    /** Resolve the subscription behind a dispute resource (by disputed capture id). */
    private function subFromDispute(array $r): ?array
    {
        foreach ($r['disputed_transactions'] ?? [] as $t) {
            $capture = $t['seller_transaction_id'] ?? null;
            if ($capture && ($sub = $this->store->findByCaptureId((string) $capture))) {
                return $sub;
            }
            // Some dispute payloads carry custom_id we can match to the sub id.
            if (!empty($t['custom']) && ($sub = $this->store->get((string) $t['custom']))) {
                return $sub;
            }
        }
        return null;
    }

    private function captureIdFromRefund(array $r): ?string
    {
        foreach ($r['links'] ?? [] as $l) {
            if (($l['rel'] ?? '') === 'up' && !empty($l['href'])) {
                $parts = explode('/', rtrim($l['href'], '/'));
                return end($parts) ?: null;
            }
        }
        return null;
    }

    private function miss(string $type): array
    {
        $this->log('warning', 'Webhook could not be matched to a subscription', ['type' => $type]);
        return ['handled' => false, 'action' => 'no_match', 'event_type' => $type, 'subscription_id' => null];
    }

    private function log(string $level, string $msg, array $ctx = []): void
    {
        if ($this->logger) ($this->logger)($level, $msg, $ctx);
    }
}
