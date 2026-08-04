<?php
declare(strict_types=1);

namespace PayPalHK;

/**
 * Minimal JSON-file subscription store for the demo (framework-agnostic, no DB
 * dependency). In the Laravel app this maps to an Eloquent model + migration:
 * a `subscriptions` table and a `charges` table. The method surface mirrors
 * what the recurring scheduler needs.
 */
class Store
{
    public function __construct(private string $file)
    {
        if (!is_file($this->file)) {
            file_put_contents($this->file, json_encode(['subscriptions' => []]));
        }
    }

    private function read(): array
    {
        $data = json_decode((string) file_get_contents($this->file), true);
        return is_array($data) ? $data : ['subscriptions' => []];
    }

    private function write(array $data): void
    {
        file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function create(array $sub): array
    {
        $data = $this->read();
        $data['subscriptions'][] = $sub;
        $this->write($data);
        return $sub;
    }

    public function get(string $id): ?array
    {
        foreach ($this->read()['subscriptions'] as $s) {
            if ($s['id'] === $id) return $s;
        }
        return null;
    }

    public function all(): array
    {
        return $this->read()['subscriptions'];
    }

    public function update(string $id, array $patch): void
    {
        $data = $this->read();
        foreach ($data['subscriptions'] as &$s) {
            if ($s['id'] === $id) {
                $s = array_merge($s, $patch);
            }
        }
        $this->write($data);
    }

    public function appendCharge(string $id, array $charge): void
    {
        $data = $this->read();
        foreach ($data['subscriptions'] as &$s) {
            if ($s['id'] === $id) {
                $s['charges'][] = $charge;
            }
        }
        $this->write($data);
    }

    /** Look up an existing subscription by the setup token it was created from
     *  (idempotency: a re-submitted finalize returns the same record). */
    public function findBySetupToken(string $token): ?array
    {
        if ($token === '') return null;
        foreach ($this->read()['subscriptions'] as $s) {
            if (($s['setup_token'] ?? null) === $token) return $s;
        }
        return null;
    }

    /**
     * Count how many *successful* trial purchases a given card fingerprint has
     * already made. Drives the anti-abuse cap (max 2 trials per card).
     * A "trial purchase" = a subscription created from that card whose trial
     * charge succeeded.
     */
    public function countTrialsByCard(string $cardFp): int
    {
        if ($cardFp === '') return 0;
        $n = 0;
        foreach ($this->read()['subscriptions'] as $s) {
            if (($s['card_fp'] ?? null) !== $cardFp) continue;
            foreach (($s['charges'] ?? []) as $c) {
                if (($c['type'] ?? '') === 'trial' && !empty($c['ok'])) { $n++; break; }
            }
        }
        return $n;
    }

    /** Find the subscription that owns a given capture id (any of its charges). */
    public function findByCaptureId(string $captureId): ?array
    {
        if ($captureId === '') return null;
        foreach ($this->read()['subscriptions'] as $s) {
            foreach (($s['charges'] ?? []) as $c) {
                if (($c['capture_id'] ?? null) === $captureId) return $s;
            }
        }
        return null;
    }

    /** Find the subscription billing a given vault (stored card) id. */
    public function findByVaultId(string $vaultId): ?array
    {
        if ($vaultId === '') return null;
        foreach ($this->read()['subscriptions'] as $s) {
            if (($s['vault_id'] ?? null) === $vaultId) return $s;
        }
        return null;
    }

    /** Append an out-of-band event (dispute, refund, reversal…) to a subscription. */
    public function appendEvent(string $id, array $event): void
    {
        $data = $this->read();
        foreach ($data['subscriptions'] as &$s) {
            if ($s['id'] === $id) {
                $s['events'][] = $event;
            }
        }
        $this->write($data);
    }

    /**
     * Record a webhook event id as processed. Returns true if it was newly
     * recorded, false if we've already seen it (idempotent handler dedupe).
     */
    public function markEventProcessed(string $eventId): bool
    {
        if ($eventId === '') return true;
        $data = $this->read();
        $seen = $data['webhook_events'] ?? [];
        if (in_array($eventId, $seen, true)) return false;
        $seen[] = $eventId;
        // Keep the dedupe list bounded.
        if (count($seen) > 1000) $seen = array_slice($seen, -1000);
        $data['webhook_events'] = $seen;
        $this->write($data);
        return true;
    }

    /**
     * Roll up metrics grouped by market/product/brand segment. Because every
     * record is stamped with segment/country/product/brand at creation, the
     * dashboard can read accurate per-market numbers directly — no joins, no
     * back-filling. Returns one row per segment with counts + captured revenue
     * (by currency, since markets may differ). Grouping key is configurable so
     * the same helper backs "by country", "by product", or "by brand" views.
     */
    public function metricsBySegment(string $groupBy = 'segment'): array
    {
        $rows = [];
        foreach ($this->read()['subscriptions'] as $s) {
            $key = $s[$groupBy] ?? 'unknown';
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    $groupBy    => $key,
                    'country'   => $s['country'] ?? null,
                    'product'   => $s['product'] ?? null,
                    'brand'     => $s['brand'] ?? null,
                    'total'     => 0,
                    'active'    => 0,
                    'past_due'  => 0,
                    'cancelled' => 0,
                    'trials'    => 0,
                    'revenue'   => [],   // currency => captured amount
                ];
            }
            $r = &$rows[$key];
            $r['total']++;
            $status = $s['status'] ?? '';
            if (isset($r[$status])) $r[$status]++;
            $cur = $s['currency'] ?? 'EUR';
            foreach (($s['charges'] ?? []) as $c) {
                if (empty($c['ok'])) continue;
                if (($c['type'] ?? '') === 'trial') $r['trials']++;
                $r['revenue'][$cur] = round(($r['revenue'][$cur] ?? 0) + (float) ($c['amount'] ?? 0), 2);
            }
            unset($r);
        }
        return array_values($rows);
    }

    /** Subscriptions whose next charge is due (active or in dunning). */
    public function due(int $nowTs): array
    {
        $out = [];
        foreach ($this->read()['subscriptions'] as $s) {
            if (in_array($s['status'], ['active', 'past_due'], true)
                && !empty($s['next_billing_at'])
                && strtotime($s['next_billing_at']) <= $nowTs) {
                $out[] = $s;
            }
        }
        return $out;
    }
}
