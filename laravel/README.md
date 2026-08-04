# Laravel integration (TheSmartLookup)

This folder is the **drop-in integration layer** for the PayPal HK card-subscription
engine. The payment logic itself lives in the framework-agnostic classes under
`../src/` (PayPalClient, VaultRecurring, SubscriptionService, RetryPolicy,
ResponseCodes, WebhookVerifier, WebhookHandler, Segments, Attribution). Those files
are pure PHP with no framework dependency — they run identically in the demo and in
your Laravel app. This layer only supplies the Laravel-native pieces:

| Piece | File | Purpose |
|-------|------|---------|
| DB tables | `database/migrations/*` | subscriptions, charges, events, webhook-event dedupe |
| Models | `app/Models/Paypal*.php` | Eloquent models for those tables |
| Store | `app/PayPal/EloquentStore.php` | same method surface as the demo's JSON `Store`, backed by the DB — so the `src/` engine works unchanged |
| Cron | `app/Console/Commands/RunBillingCommand.php` | `php artisan paypal:run-billing` — the recurring/dunning driver (replaces `scripts/run_billing.php`) |
| HTTP | `app/Http/Controllers/PayPalController.php` | the checkout + webhook endpoints (replaces `public/api.php`) |
| Routes | `routes/paypal.php` | route definitions for the controller |
| Config | `config/paypal.php` | reads your `.env`; no hardcoded keys |
| Wiring | `app/Providers/PayPalServiceProvider.php` | binds the store + engine into the container, schedules the cron |

## Why an EloquentStore instead of the JSON file

The demo persists to a single JSON file for zero-dependency portability. In
production that becomes real tables. `EloquentStore` implements the *exact same
method surface* the engine calls (`create`, `update`, `appendCharge`,
`findBySetupToken`, `countTrialsByCard`, `findByVaultId`, `findByCaptureId`,
`appendEvent`, `markEventProcessed`, `due`, plus the analytics roll-ups
`metricsBySegment` / `customerLtv` / `cohorts`). Because the surface matches, none
of the `src/` engine code changes — you only swap which store it is handed.

## Install steps

1. Copy `../src/*` into `app/PayPal/Engine/` (or add the repo as a Composer path
   package) and PSR-4 autoload the `PayPalHK\` namespace to it.
2. Copy this folder's `app/`, `config/`, `database/`, `routes/` into your project,
   adjusting the `App\...` namespaces / table names to your conventions.
3. Add the PayPal keys to `.env` (see `config/paypal.php` for the full list).
4. `php artisan migrate` — creates the four tables.
5. Register the routes (RouteServiceProvider or `bootstrap/app.php`) and the
   scheduler entry (see `PayPalServiceProvider::boot`).
6. Register the webhook URL `POST /paypal/webhook` in the PayPal dashboard and put
   the resulting webhook id in `PAYPAL_WEBHOOK_ID`. The route is CSRF-exempt.
7. Point the cron at Laravel's scheduler: `* * * * * php artisan schedule:run`
   (the scheduler runs `paypal:run-billing` hourly). If you prefer a direct cron:
   `0 * * * * php /path/artisan paypal:run-billing`.

## Data model — dashboard-ready from day one

Every subscription row is stamped at creation with its **segment / country /
product / brand**, the **customer_ref** (stable identity across products), the
signup **cohort** (YYYY-MM), and first-touch **acquisition** (source / channel /
medium). That is everything the future metrics dashboard needs to compute LTV,
retention, cohorts and churn broken down by country, product/niche and acquisition
source — no back-filling, no schema redesign. See `../ARCHITECTURE.md` for the full
dimension list and the ready-made query primitives.

> Sandbox first. Set `PAYPAL_API_BASE=https://api-m.sandbox.paypal.com` and validate
> the full flow (trial → vault → MIT renewal → dunning → webhooks → wallet) end to
> end before switching to `https://api-m.paypal.com` for live traffic. The single
> hard dependency for live recurring is that **Reference Transactions / MIT** are
> enabled on the live REST app.
