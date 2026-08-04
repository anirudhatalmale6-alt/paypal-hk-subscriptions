<?php

namespace App\Providers;

use App\Console\Commands\RunBillingCommand;
use App\PayPal\EloquentStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the PayPal integration into the app: the engine autoloader, config,
 * routes, migrations, the store binding, the billing command and its hourly
 * schedule.
 *
 * It is registered in config/app.php ('providers' array). Everything the module
 * needs is self-contained under app/PayPal, app/Http/Controllers/PayPalController,
 * app/Models/Paypal*, config/paypal.php, routes/paypal.php and the paypal_*
 * migrations — nothing here depends on the rest of the application.
 */
class PayPalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The framework-agnostic payment engine lives under app/PayPal/Engine and
        // uses the PayPalHK\ namespace (so the exact same code runs in the demo and
        // here). It is intentionally NOT in composer's PSR-4 map, so we register a
        // small fallback autoloader for it — no `composer dump-autoload` needed when
        // the module is dropped in over FTP. PayPalException is declared alongside
        // PayPalClient, so it maps to that file.
        spl_autoload_register(function (string $class): void {
            $prefix = 'PayPalHK\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $rel = substr($class, strlen($prefix));
            if ($rel === 'PayPalException') {
                $rel = 'PayPalClient';
            }
            $file = __DIR__ . '/../PayPal/Engine/' . str_replace('\\', '/', $rel) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        $this->mergeConfigFrom(__DIR__ . '/../../config/paypal.php', 'paypal');
        $this->app->singleton(EloquentStore::class, fn () => new EloquentStore());
    }

    public function boot(): void
    {
        // Segments::resolve() reads DEFAULT_SEGMENT from the environment; keep it in
        // step with config so the engine's default market matches the app's.
        if ($seg = config('paypal.default_segment')) {
            putenv('DEFAULT_SEGMENT=' . $seg);
        }

        $this->loadRoutesFrom(__DIR__ . '/../../routes/paypal.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([RunBillingCommand::class]);
            // Hourly recurring/dunning run. Needs `* * * * * php artisan schedule:run`
            // in the server crontab. withoutOverlapping guards a slow run from stacking.
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('paypal:run-billing')->hourly()->withoutOverlapping();
            });
        }
    }
}
