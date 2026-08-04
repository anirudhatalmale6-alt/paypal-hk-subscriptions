# PayPal HK Card-Subscription Module — TheSmartLookup (Laravel 10)

This is the PayPal integration deployed into the TheSmartLookup Laravel app
(`public_html/ThesmartLookUp`). It runs a **paid 48h trial (€2.90) → automatic
€49.50/month recurring** in EUR, on PayPal Hong Kong.

Because a HK merchant cannot use PayPal's native card-funded Subscriptions API,
this is a **self-driven vault + Merchant-Initiated-Transaction (MIT) engine**:
the card (or Apple Pay / PayPal wallet) is vaulted once, and an hourly job charges
the vaulted funding source each cycle, with a full smart-retry / dunning policy.

---

## Where everything lives (as deployed)

```
app/PayPal/Engine/            ← framework-agnostic payment engine (namespace PayPalHK\)
    PayPalClient.php           OAuth + HTTP + browser client-token (+ PayPalException)
    VaultRecurring.php         setup-token, vaulting, trial + MIT charges, wallet orders
    ResponseCodes.php          maps PayPal processor codes → category / retryable
    RetryPolicy.php            dunning schedule + charge-amount (INSF half-price etc.)
    Segments.php               market/product/brand registry (soft descriptor, price…)
    Attribution.php            first-touch acquisition (UTM / gclid / fbclid / referrer)
    WebhookVerifier.php        verifies inbound webhook signatures
    WebhookHandler.php         applies webhook events to subscriptions
    Store.php / SubscriptionService.php  (JSON store used by the standalone demo only)
app/PayPal/EloquentStore.php  ← DB store; same method surface as the demo JSON Store
app/PayPal/sandbox_checkout.php ← self-contained sandbox test checkout page
app/Models/Paypal{Subscription,Charge,Event,WebhookEvent}.php
app/Http/Controllers/PayPalController.php   ← checkout + webhook endpoints
app/Console/Commands/RunBillingCommand.php  ← `php artisan paypal:run-billing`
app/Providers/PayPalServiceProvider.php     ← wires it all together
config/paypal.php             ← all settings, read from .env (nothing hardcoded)
routes/paypal.php             ← /paypal/* routes (loaded by the provider)
database/migrations/2026_08_04_0000*        ← paypal_subscriptions/charges/events/webhook_events
```

### The engine autoloader (no `composer dump-autoload` needed)
The engine uses the `PayPalHK\` namespace and is intentionally kept out of
Composer's PSR-4 map, so it can be dropped in over FTP. `PayPalServiceProvider::register()`
registers a small fallback autoloader that maps `PayPalHK\Foo` → `app/PayPal/Engine/Foo.php`
(`PayPalException` maps to `PayPalClient.php`, where it is declared). If you later
add `"PayPalHK\\": "app/PayPal/Engine/"` to `composer.json` and run
`composer dump-autoload`, you can delete that autoloader block — both work.

### Provider registration
`App\Providers\PayPalServiceProvider::class` is registered in `config/app.php`
(`providers` array). It loads the routes, migrations, the config, binds the store,
registers the `paypal:run-billing` command and schedules it hourly.

---

## Endpoints (`routes/paypal.php`)
| Method | Path | Purpose |
|--------|------|---------|
| POST | `/paypal/create-setup-token` | vault setup token for the hosted card fields |
| POST | `/paypal/finalize` | exchange token → charge €2.90 trial → create subscription |
| POST | `/paypal/create-wallet-order` | Apple Pay / PayPal wallet order (trial amount) |
| POST | `/paypal/capture-order` | capture + vault an approved wallet order |
| GET  | `/paypal/subscription?sub=…` | subscription lookup |
| POST | `/paypal/cancel?sub=…` | cancel |
| GET  | `/paypal/sandbox` | self-contained test checkout (sandbox only; 404s on live) |
| POST | `/paypal/webhook` | inbound PayPal webhook (CSRF-exempt) |

The endpoints are stateless JSON. Your production checkout page
(`/fr/checkout/rapport-auto`) calls the same endpoints — see
`app/PayPal/sandbox_checkout.php` for a complete working reference of the browser
flow (Web SDK v6 hosted card fields + Apple Pay / PayPal buttons).

---

## Configuration (`.env`)
```
PAYPAL_API_BASE=https://api-m.sandbox.paypal.com   # live: https://api-m.paypal.com
PAYPAL_CLIENT_ID=…
PAYPAL_CLIENT_SECRET=…
PAYPAL_WEBHOOK_ID=…            # from the PayPal dashboard / webhook create call
PAYPAL_SDK_DOMAINS=thesmartlookup.com
PAYPAL_DEFAULT_SEGMENT=fr-vehicle-history-report
PAYPAL_CURRENCY=EUR
PAYPAL_MAX_TRIALS_PER_CARD=2
```
After changing `.env` or `config/paypal.php`, run `php artisan config:clear`
(or `config:cache`).

---

## The recurring job (cron)
The billing job must run on a schedule. **Add ONE of these** in
hPanel → Advanced → Cron Jobs (do NOT add both):

Direct (simplest):
```
0 * * * * php /home/u308237610/domains/thesmartlookup.com/public_html/ThesmartLookUp/artisan paypal:run-billing >> storage/logs/paypal-cron.log 2>&1
```
or the standard Laravel scheduler (the provider already schedules the job hourly):
```
* * * * * php /home/u308237610/domains/thesmartlookup.com/public_html/ThesmartLookUp/artisan schedule:run >> /dev/null 2>&1
```
Each run charges every subscription whose `next_billing_at` is due, records the
result, and applies the retry/dunning policy. It is safe to run more often than
hourly — a not-yet-due subscription is simply skipped.

---

## Data model (dashboard-ready from day one)
Every subscription is stamped at creation with `segment / country / product /
brand`, a stable `customer_ref`, a signup `cohort` (YYYY-MM) and first-touch
`acquisition` (source / channel / medium). Charges and events are in their own
tables. This is enough to compute LTV, retention, cohorts and churn broken down
by Country, Product/Niche and Acquisition source without any later re-modelling.

---

## Going live
1. Confirm **Reference Transactions / MIT** is enabled on the LIVE REST app
   (required or the first monthly renewal fails; the trial still succeeds).
2. Set the live `PAYPAL_API_BASE`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`.
3. Create a **live** webhook for `https://thesmartlookup.com/paypal/webhook`
   (same events) and set `PAYPAL_WEBHOOK_ID`.
4. `php artisan config:clear`.
5. Wire the PayPal card fields + wallet buttons into `/fr/checkout/rapport-auto`
   (copy from `app/PayPal/sandbox_checkout.php`; point the `fetch()` calls at the
   `/paypal/*` routes).
6. `/paypal/sandbox` automatically 404s once you are on live credentials.

## Validated (sandbox, on this server)
- `POST /paypal/create-setup-token` → real setup token
- vault a card → `POST /paypal/finalize` → €2.90 trial + subscription row
- `php artisan paypal:run-billing` → MIT charge €49.50 + reschedule +1 month
- re-run → nothing due (no double-billing)
- webhook route live (POST-only)
