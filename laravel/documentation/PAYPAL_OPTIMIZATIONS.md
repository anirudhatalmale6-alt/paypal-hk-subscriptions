# PayPal Integration — Acceptance & Optimization Reference

TheSmartLookup — PayPal Hong Kong card-subscription engine.
Reference for internal developers and for reusing the same pattern on future
brands/niches (e.g. GlobalRecharge). Everything below is implemented and live
unless explicitly marked "account-side" (a toggle in the PayPal dashboard) or
"next step".

---

## 1. Why this architecture (vault + MIT, not native Subscriptions)

A Hong Kong merchant **cannot** charge raw cards through PayPal's native
card-funded Subscriptions API. So recurring is self-driven:

1. The card / Apple Pay / PayPal wallet is **vaulted once** at checkout (Vault v3).
2. An **hourly billing job** charges the vaulted token each cycle as a
   **Merchant-Initiated Transaction (MIT)**, with a full smart-retry / dunning
   policy that WE control (native subscription retries are a black box).

This gives on-site card entry (no PayPal redirect) + real recurring + full
control over retry timing and analytics.

Files: `app/PayPal/Engine/VaultRecurring.php` (charging), `RunBillingCommand`
(the cron job), `RetryPolicy.php` (dunning), `EloquentStore.php` (DB).

---

## 2. Acceptance optimizations (each one lifts auth rate)

| # | Optimization | Where | Effect |
|---|--------------|-------|--------|
| 1 | **Stored-credential flags** — first charge tagged `CUSTOMER / RECURRING / FIRST`, every renewal `MERCHANT / RECURRING / SUBSEQUENT` | `VaultRecurring::charge()` | Banks price recurring MITs correctly and stop applying SCA to renewals → far fewer renewal declines. |
| 2 | **3-D Secure only where required** — `verification.method = SCA_WHEN_REQUIRED` on the vault | `VaultRecurring` (card attrs) | EU/UK buyers get PSD2 SCA (mandatory), everyone else stays frictionless. No hard-coded region. |
| 3 | **Fraudnet / Data Collector + CMID** — `fb.js` + `fconfig` on the page, and the same `PayPal-Client-Metadata-Id` is forwarded **server-side** on the charge | checkout page head + `VaultRecurring::charge()` header | This is the single biggest cross-border acceptance lever. The device telemetry only helps if the charge carries the same metadata id — it now does. |
| 4 | **Advanced Card Fields (hosted, SAQ A)** — card inputs are PayPal-hosted iframes; the PAN never touches our server | checkout page (`card-fields` component) | Higher trust score on PayPal's side + minimal PCI scope (SAQ A). |
| 5 | **Network tokenization / Account Updater** — vaulting uses the standard path that carries network tokens; reissued/expired cards auto-update | Vault (account-side toggle) | Kills a large share of *involuntary* churn (expired/replaced cards keep billing). Confirm it is ON for the live app. |
| 6 | **Smart retry / dunning** — soft declines retried on a spaced schedule (~2 months), hard declines & auth-required cancelled immediately (no pointless retries), suspected-fraud gets one retry then stop | `RetryPolicy.php` | Recovers recoverable failures, avoids burning issuer trust on dead cards. |
| 7 | **Insufficient-funds handling** — after 4 attempts the amount drops to half for the rest of that cycle, back to full next cycle; retries are **payday-nudged** (1st / 15th / month-end / Mon / Fri) and snapped to a morning hour | `RetryPolicy.php` | Catches buyers who get paid mid-cycle. |
| 8 | **Decline-reason classification** — every charge stores processor code → category (approved / soft / hard / auth-required / fraud) + retryable flag + `PayPal-Debug-Id` | `ResponseCodes.php`, stored on each charge row | Lets you monitor auth rate and *which* declines are recoverable vs dead, and tune pricing/timing. |
| 9 | **EUR held, no auto-convert** — currency is EUR end-to-end | account-side + `config/paypal.php` | Avoids the 2.5–3% HK FX haircut and the extra decline surface of cross-currency. |
| 10 | **Statement descriptor `thesmartlookup`** — soft descriptor set per charge (≤22 chars) | `VaultRecurring` `soft_descriptor` | Recognisable line on the bank statement → fewer "I don't recognise this" chargebacks. |
| 11 | **Consent + stored-credential mandate at checkout** — recurring terms + CGV/privacy acceptance are required before pay; consent is logged (ip, ua, timestamp, text) | checkout page + existing provisioning controller | PSD2 mandate + the strongest chargeback defence. |
| 12 | **Idempotency** — `finalize` is keyed on the setup-token (re-submit returns the existing subscription, no double charge); the trial charge uses a stable `PayPal-Request-Id` | `PayPalController::finalize`, `VaultRecurring` | No double billing on double-clicks / retries. |
| 13 | **Per-card trial cap** — a card fingerprint (brand+last4+expiry) may start at most 2 trials; the 3rd is blocked *before* charging and the token deleted | `PayPalController::finalize` | Anti card-testing / trial-abuse. |
| 14 | **Webhooks (signed)** — dispute-created → suspend, capture reversed → suspend, vault-token deleted → suspend, capture completed → reconcile; deduplicated by event id | `WebhookHandler.php`, `WebhookVerifier.php` | Reacts to money moving out-of-band; disputes auto-suspend to stop further loss. |

---

## 3. Payment flow (what happens on the page)

**Card:**
1. Page loads the Web SDK v6 (`card-fields`) → 3 hosted iframes mount.
2. On submit: email is **pre-checked** (`/paypal/precheck-email`) so we never
   charge a card we can't provision.
3. `POST /paypal/create-setup-token` → hosted fields `submit(setupToken)`
   (runs 3DS if required, vaults the card).
4. `POST /paypal/finalize` → charges €2.90 (FIRST, with CMID) + creates the
   subscription row (status `active`, `next_billing_at` = +48h).
5. Browser hands off to the existing **provisioning controller**
   (`paiement.controller`) → account + welcome email + login, exactly as before.

**Apple Pay / PayPal wallet:** the popup shows only the €2.90 trial (no recurring
terms in the sheet — the recurring terms live on the page). On approve →
`POST /paypal/capture-order` vaults the source + charges €2.90 + creates the
subscription → same provisioning hand-off.

**Recurring:** the cron runs `php artisan paypal:run-billing` hourly; each due
subscription is charged as an MIT (`SUBSEQUENT`) and rescheduled, or run through
the retry policy on decline.

---

## 4. Analytics — segmented from day one

Every subscription and charge is stamped at creation with:
`segment / country / product / brand`, a stable `customer_ref`, a `cohort`
(YYYY-MM) and first-touch `acquisition` (source / channel / medium, from
UTM / gclid / fbclid / referrer). This is enough to compute **LTV, retention,
cohorts and churn broken down by Country, Product/Niche and Acquisition source**
with no later re-modelling. New market/product = one entry in `Segments.php`.

Files: `Segments.php`, `Attribution.php`; rollup helpers on the store
(`metricsBySegment`, `customerLtv`, `cohorts`).

---

## 5. PayPal account-side checklist (dashboard, not code)

For each brand/app that goes live, confirm on the **live** REST app:

- [ ] Advanced (ACDC) Card Payments **enabled & underwriting approved**
- [ ] **Reference Transactions / MIT enabled** (required for recurring)
- [ ] Vault enabled
- [ ] **Network tokenization / Account Updater** ON
- [ ] Currency held (no auto-convert to HKD)
- [ ] Fraud Protection filters configured
- [ ] Webhook registered for the app's `/paypal/webhook` URL
- [ ] Statement/soft descriptor registered to match the code
- [ ] Apple Pay: domain registered + domain-association file hosted

Each brand gets its **own** REST app (client id/secret), **own** webhook id, own
descriptor and own data tags — fully isolated, no shared credentials.

---

## 6. Reusing this for a new brand/niche

1. Add a `Segments.php` entry (price, currency, trial, descriptor, country,
   product, brand).
2. Create a separate live REST app + webhook + descriptor for the brand.
3. Set that brand's env block (base, client id/secret, webhook id).
4. Point the brand's checkout page at the same `/paypal/*` endpoints with
   `?segment=<code>`.
Nothing in the engine changes.
