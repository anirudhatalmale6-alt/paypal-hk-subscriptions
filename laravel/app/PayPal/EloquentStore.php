<?php

namespace App\PayPal;

use App\Models\PaypalCharge;
use App\Models\PaypalEvent;
use App\Models\PaypalSubscription;
use App\Models\PaypalWebhookEvent;
use Illuminate\Support\Carbon;

/**
 * Database-backed store with the EXACT same method surface as the demo's JSON
 * `PayPalHK\Store`. The `src/` engine (VaultRecurring, RetryPolicy, WebhookHandler,
 * run-billing) calls these methods and receives subscriptions as plain arrays
 * shaped identically to the demo — nested `charges` / `events`, ISO-8601 date
 * strings — so none of the engine code changes when it runs on the DB.
 *
 * The subscription's scalar fields live on `paypal_subscriptions`; its charges and
 * events live in their own tables and are re-nested on read.
 */
class EloquentStore
{
    // Columns that live directly on the subscriptions table (everything else in an
    // incoming create/update array that isn't here is ignored, except the nested
    // `charges` handled specially by create()).
    private const SUB_COLUMNS = [
        'id', 'vault_id', 'source_type', 'order_id', 'setup_token',
        'card_fp', 'card_last4', 'card_brand', 'email',
        'currency', 'monthly_amount', 'soft_descriptor',
        'segment', 'country', 'product', 'brand', 'product_label',
        'customer_id', 'customer_ref', 'cohort', 'acquisition',
        'acq_source', 'acq_channel', 'acq_medium',
        'status', 'retry_count', 'insf_streak', 'fraud_retries',
        'first_fail_at', 'next_billing_at', 'last_charge_at', 'last_recovery',
        'cancel_reason', 'cancelled_at', 'activated_at',
    ];

    /** Persist a new subscription (and any nested trial charge) and return it. */
    public function create(array $sub): array
    {
        $charges = $sub['charges'] ?? [];
        PaypalSubscription::create($this->onlySubColumns($sub));
        foreach ($charges as $c) {
            $this->insertCharge($sub['id'], $c);
        }
        return $this->get($sub['id']) ?? $sub;
    }

    public function get(string $id): ?array
    {
        $m = PaypalSubscription::with(['charges', 'events'])->find($id);
        return $m ? $this->hydrate($m) : null;
    }

    /** @return array<int,array> */
    public function all(): array
    {
        return PaypalSubscription::with(['charges', 'events'])->get()
            ->map(fn ($m) => $this->hydrate($m))->all();
    }

    public function update(string $id, array $patch): void
    {
        $data = $this->onlySubColumns($patch);
        unset($data['id']);
        if ($data) {
            PaypalSubscription::whereKey($id)->update($data);
        }
    }

    public function appendCharge(string $id, array $charge): void
    {
        $this->insertCharge($id, $charge);
    }

    public function findBySetupToken(string $token): ?array
    {
        if ($token === '') return null;
        $m = PaypalSubscription::with(['charges', 'events'])->where('setup_token', $token)->first();
        return $m ? $this->hydrate($m) : null;
    }

    /**
     * How many *successful* trials a given card fingerprint has already bought —
     * one per subscription whose trial charge succeeded. Drives the anti-abuse cap.
     */
    public function countTrialsByCard(string $cardFp): int
    {
        if ($cardFp === '') return 0;
        return PaypalSubscription::where('card_fp', $cardFp)
            ->whereHas('charges', fn ($q) => $q->where('type', 'trial')->where('ok', true))
            ->count();
    }

    public function findByCaptureId(string $captureId): ?array
    {
        if ($captureId === '') return null;
        $subId = PaypalCharge::where('capture_id', $captureId)->value('subscription_id');
        return $subId ? $this->get($subId) : null;
    }

    public function findByVaultId(string $vaultId): ?array
    {
        if ($vaultId === '') return null;
        $m = PaypalSubscription::with(['charges', 'events'])->where('vault_id', $vaultId)->first();
        return $m ? $this->hydrate($m) : null;
    }

    public function appendEvent(string $id, array $event): void
    {
        PaypalEvent::create([
            'subscription_id' => $id,
            'type'            => $event['type'] ?? 'event',
            'data'            => $event,
            'occurred_at'     => isset($event['at']) ? Carbon::parse($event['at']) : now(),
        ]);
    }

    /** Record a webhook id as processed; false if we've already seen it. */
    public function markEventProcessed(string $eventId): bool
    {
        if ($eventId === '') return true;
        if (PaypalWebhookEvent::whereKey($eventId)->exists()) return false;
        PaypalWebhookEvent::create(['event_id' => $eventId, 'processed_at' => now()]);
        return true;
    }

    /** Subscriptions whose next charge is due (active or in dunning). */
    public function due(int $nowTs): array
    {
        return PaypalSubscription::with(['charges', 'events'])
            ->whereIn('status', ['active', 'past_due'])
            ->whereNotNull('next_billing_at')
            ->where('next_billing_at', '<=', Carbon::createFromTimestamp($nowTs))
            ->get()->map(fn ($m) => $this->hydrate($m))->all();
    }

    // ---- Dashboard roll-ups (mirror the demo Store; can be pushed to SQL later) --

    public function metricsBySegment(string $groupBy = 'segment'): array
    {
        $rows = [];
        foreach ($this->all() as $s) {
            $key = $s[$groupBy] ?? 'unknown';
            $rows[$key] ??= [$groupBy => $key, 'country' => $s['country'] ?? null, 'product' => $s['product'] ?? null,
                'brand' => $s['brand'] ?? null, 'total' => 0, 'active' => 0, 'past_due' => 0,
                'cancelled' => 0, 'trials' => 0, 'revenue' => []];
            $r = &$rows[$key];
            $r['total']++;
            if (isset($r[$s['status'] ?? ''])) $r[$s['status']]++;
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

    public function customerLtv(): array
    {
        $rows = [];
        foreach ($this->all() as $s) {
            $key = $s['customer_ref'] ?? ('anon:' . ($s['id'] ?? ''));
            $rows[$key] ??= ['customer_ref' => $key, 'email' => $s['email'] ?? null, 'cohort' => $s['cohort'] ?? null,
                'country' => $s['country'] ?? null, 'acq_channel' => $s['acq_channel'] ?? null,
                'acq_source' => $s['acq_source'] ?? null, 'subscriptions' => 0, 'active' => 0, 'products' => [],
                'first_seen' => $s['created_at'] ?? null, 'last_seen' => $s['created_at'] ?? null, 'gross_revenue' => []];
            $r = &$rows[$key];
            $r['subscriptions']++;
            if (($s['status'] ?? '') === 'active') $r['active']++;
            if (!empty($s['product']) && !in_array($s['product'], $r['products'], true)) $r['products'][] = $s['product'];
            $created = $s['created_at'] ?? null;
            if ($created && (!$r['first_seen'] || $created < $r['first_seen'])) $r['first_seen'] = $created;
            if ($created && (!$r['last_seen'] || $created > $r['last_seen'])) $r['last_seen'] = $created;
            $cur = $s['currency'] ?? 'EUR';
            foreach (($s['charges'] ?? []) as $c) {
                if (!empty($c['ok'])) $r['gross_revenue'][$cur] = round(($r['gross_revenue'][$cur] ?? 0) + (float) ($c['amount'] ?? 0), 2);
            }
            unset($r);
        }
        return array_values($rows);
    }

    public function cohorts(?string $dimension = null): array
    {
        $rows = [];
        foreach ($this->all() as $s) {
            $cohort = $s['cohort'] ?? 'unknown';
            $dim = $dimension ? ($s[$dimension] ?? 'unknown') : null;
            $key = $cohort . '|' . ($dim ?? '');
            $rows[$key] ??= ['cohort' => $cohort, 'dimension' => $dimension, 'value' => $dim,
                'customers' => 0, 'active' => 0, 'churned' => 0, 'retention' => 0.0, 'revenue' => []];
            $r = &$rows[$key];
            $r['customers']++;
            $status = $s['status'] ?? '';
            if ($status === 'active') $r['active']++;
            if (in_array($status, ['cancelled', 'canceled'], true)) $r['churned']++;
            $cur = $s['currency'] ?? 'EUR';
            foreach (($s['charges'] ?? []) as $c) {
                if (!empty($c['ok'])) $r['revenue'][$cur] = round(($r['revenue'][$cur] ?? 0) + (float) ($c['amount'] ?? 0), 2);
            }
            unset($r);
        }
        foreach ($rows as &$r) {
            $r['retention'] = $r['customers'] > 0 ? round($r['active'] / $r['customers'], 4) : 0.0;
        }
        return array_values($rows);
    }

    // ---------------------------------------------------------------- helpers ---

    private function onlySubColumns(array $in): array
    {
        return array_intersect_key($in, array_flip(self::SUB_COLUMNS));
    }

    private function insertCharge(string $subId, array $c): void
    {
        PaypalCharge::create([
            'subscription_id' => $subId,
            'type'            => $c['type'] ?? 'recurring',
            'amount'          => (float) ($c['amount'] ?? 0),
            'ok'              => (bool) ($c['ok'] ?? false),
            'capture_id'      => $c['capture_id'] ?? null,
            'response_code'   => $c['response_code'] ?? null,
            'response_label'  => $c['response_label'] ?? null,
            'category'        => $c['category'] ?? null,
            'retryable'       => $c['retryable'] ?? null,
            'decline'         => $c['decline'] ?? null,
            'debug_id'        => $c['debug_id'] ?? null,
            'charged_at'      => isset($c['at']) ? Carbon::parse($c['at']) : now(),
        ]);
    }

    /** Model -> the plain array shape the engine expects (ISO dates, nested rows). */
    private function hydrate(PaypalSubscription $m): array
    {
        $iso = fn ($v) => $v instanceof Carbon ? $v->toIso8601String() : $v;

        $sub = [
            'id'              => $m->id,
            'vault_id'        => $m->vault_id,
            'source_type'     => $m->source_type,
            'order_id'        => $m->order_id,
            'setup_token'     => $m->setup_token,
            'card_fp'         => $m->card_fp,
            'card_last4'      => $m->card_last4,
            'card_brand'      => $m->card_brand,
            'email'           => $m->email,
            'currency'        => $m->currency,
            'monthly_amount'  => $m->monthly_amount,
            'soft_descriptor' => $m->soft_descriptor,
            'segment'         => $m->segment,
            'country'         => $m->country,
            'product'         => $m->product,
            'brand'           => $m->brand,
            'product_label'   => $m->product_label,
            'customer_id'     => $m->customer_id,
            'customer_ref'    => $m->customer_ref,
            'cohort'          => $m->cohort,
            'acquisition'     => $m->acquisition,
            'acq_source'      => $m->acq_source,
            'acq_channel'     => $m->acq_channel,
            'acq_medium'      => $m->acq_medium,
            'status'          => $m->status,
            'retry_count'     => (int) $m->retry_count,
            'insf_streak'     => (int) $m->insf_streak,
            'fraud_retries'   => (int) $m->fraud_retries,
            'first_fail_at'   => $iso($m->first_fail_at),
            'next_billing_at' => $iso($m->next_billing_at),
            'last_charge_at'  => $iso($m->last_charge_at),
            'last_recovery'   => $m->last_recovery,
            'cancel_reason'   => $m->cancel_reason,
            'cancelled_at'    => $iso($m->cancelled_at),
            'activated_at'    => $iso($m->activated_at),
            'created_at'      => $iso($m->created_at),
        ];

        $sub['charges'] = $m->charges->map(fn (PaypalCharge $c) => [
            'type' => $c->type, 'amount' => (string) $c->amount, 'ok' => (bool) $c->ok,
            'capture_id' => $c->capture_id, 'response_code' => $c->response_code,
            'response_label' => $c->response_label, 'category' => $c->category,
            'retryable' => $c->retryable, 'decline' => $c->decline, 'debug_id' => $c->debug_id,
            'at' => $iso($c->charged_at),
        ])->all();

        $sub['events'] = $m->events->map(fn (PaypalEvent $e) => ($e->data ?: []) + [
            'type' => $e->type, 'at' => $iso($e->occurred_at),
        ])->all();

        return $sub;
    }
}
