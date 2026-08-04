<?php

namespace App\Providers;

use App\Console\Commands\RunBillingCommand;
use App\PayPal\EloquentStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the PayPal integration into the app: config, routes, migrations, the
 * store binding, the billing command and its hourly schedule.
 *
 * Register it in config/app.php providers (Laravel <=10) or bootstrap/providers.php
 * (Laravel 11+).
 */
class PayPalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
