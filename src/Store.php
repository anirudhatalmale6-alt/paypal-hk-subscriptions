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
