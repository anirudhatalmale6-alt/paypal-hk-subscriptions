<?php
declare(strict_types=1);

namespace PayPalHK;

/**
 * Low-level PayPal REST client.
 *
 * Responsibilities:
 *  - OAuth2 client-credentials token retrieval + in-memory caching
 *  - JSON request helper that ALWAYS captures the PayPal-Debug-Id response
 *    header (essential for resolving declines with PayPal support)
 *  - Structured result: [status, body(array), debugId, headers]
 *
 * Framework-agnostic: no Laravel dependency. In Laravel you would register
 * this as a singleton in a service provider and inject the config.
 */
class PayPalClient
{
    private string $base;
    private string $clientId;
    private string $secret;

    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    /** @var callable|null logger(string $level, string $message, array $ctx) */
    private $logger;

    public function __construct(array $cfg, ?callable $logger = null)
    {
        $this->base     = rtrim($cfg['api_base'], '/');
        $this->clientId = $cfg['client_id'];
        $this->secret   = $cfg['client_secret'];
        $this->logger   = $logger;
    }

    /** Get a cached (or fresh) OAuth2 access token. */
    public function token(): string
    {
        if ($this->token !== null && time() < $this->tokenExpiresAt - 60) {
            return $this->token;
        }

        $ch = curl_init($this->base . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $this->clientId . ':' . $this->secret,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode((string) $raw, true) ?: [];
        if ($code !== 200 || empty($data['access_token'])) {
            $this->log('error', 'OAuth token request failed', ['http' => $code, 'body' => $data]);
            throw new PayPalException('Unable to obtain PayPal access token', $code, $data);
        }

        $this->token          = $data['access_token'];
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 32400);
        return $this->token;
    }

    /**
     * Generate a user id token (a.k.a. SDK token) via the OAuth endpoint.
     * Required by the JS SDK CardFields component for the subscription flow;
     * passed to the SDK script as data-user-id-token.
     */
    public function idToken(): ?string
    {
        $ch = curl_init($this->base . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $this->clientId . ':' . $this->secret,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials&response_type=id_token',
            CURLOPT_TIMEOUT        => 30,
        ]);
        $data = json_decode((string) curl_exec($ch), true) ?: [];
        curl_close($ch);
        return $data['id_token'] ?? null;
    }

    /**
     * Perform a JSON request against the PayPal API.
     *
     * @return array{status:int, body:array, debugId:?string, headers:array}
     */
    public function request(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $url = $this->base . $path;
        $headers = array_merge([
            'Authorization: Bearer ' . $this->token(),
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HEADER         => true,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $response   = (string) curl_exec($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $rawBody    = substr($response, $headerSize);
        $headers    = $this->parseHeaders($rawHeaders);
        $debugId    = $headers['paypal-debug-id'] ?? null;
        $body       = json_decode($rawBody, true);
        if (!is_array($body)) {
            $body = [];
        }

        // Always log the debug id + status so declines are traceable later.
        $this->log($status >= 400 ? 'warning' : 'debug', "PayPal {$method} {$path}", [
            'status'    => $status,
            'debug_id'  => $debugId,
        ]);

        return ['status' => $status, 'body' => $body, 'debugId' => $debugId, 'headers' => $headers];
    }

    private function parseHeaders(string $raw): array
    {
        $out = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $out[strtolower(trim($k))] = trim($v);
            }
        }
        return $out;
    }

    private function log(string $level, string $msg, array $ctx = []): void
    {
        if ($this->logger) {
            ($this->logger)($level, $msg, $ctx);
        }
    }
}

class PayPalException extends \RuntimeException
{
    public array $paypalBody;
    public function __construct(string $message, int $code = 0, array $body = [])
    {
        parent::__construct($message, $code);
        $this->paypalBody = $body;
    }
}
