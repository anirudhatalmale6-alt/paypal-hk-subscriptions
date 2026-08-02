<?php
declare(strict_types=1);

/**
 * Minimal env loader for the standalone demo. In Laravel you would use
 * config/services.php + the framework .env instead — the src/ classes
 * take a plain config array so they drop straight in.
 */
function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        // The app's own .env is authoritative for these keys, so it overrides
        // any value already present in the OS environment.
        putenv("$k=$v");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
}

load_env(__DIR__ . '/.env');

function pp_config(): array
{
    return [
        'api_base'      => getenv('PAYPAL_API_BASE') ?: 'https://api-m.sandbox.paypal.com',
        'client_id'     => getenv('PAYPAL_CLIENT_ID') ?: '',
        'client_secret' => getenv('PAYPAL_CLIENT_SECRET') ?: '',
        'plan_id'       => getenv('PAYPAL_PLAN_ID') ?: '',
        'webhook_id'    => getenv('PAYPAL_WEBHOOK_ID') ?: '',
        'currency'      => getenv('CURRENCY') ?: 'EUR',
    ];
}
