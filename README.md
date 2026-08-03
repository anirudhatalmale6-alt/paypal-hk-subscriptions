# PayPal HK — White-label Card Subscriptions

Production-ready card-funded recurring billing for a **PayPal Hong Kong** business
account, where customers pay by card directly on your site (no PayPal login) and
are billed on a recurring basis.

Stack-agnostic PHP core (`src/`) designed to drop into **Laravel**, plus a
runnable standalone demo (`public/`).

---

## Why this architecture (important)

PayPal's native **Subscriptions API v2 does not support card-funded subscriptions
for a Hong Kong merchant account** — attempting it returns
`MERCHANT_COUNTRY_NOT_ENABLED_FOR_CARD_PAYMENTS` (verified against the live-parity
sandbox).

However, the account **does** support:

- one-time card processing,
- **vaulting** cards (saving them), and
- charging a vaulted card as a **Merchant-Initiated Transaction (MIT)**.

So recurring billing is implemented as **vault + scheduled MIT charges**, which we
drive ourselves. This is not a workaround compromise — it gives *more* control over
retry timing and decline analytics than PayPal's black-box subscription retries,
which is exactly what the optimisation goals call for.

### Flow

```
Customer enters card on your page (hosted card fields, 3DS, SAQ A)
        │
        ▼
Card vaulted  ──►  Trial charged €2.90 (first / customer-initiated)
        │
        ▼
Scheduler charges €49.50 / month against the vaulted card (merchant-initiated,
SCA-exempt) — with retry / dunning + full decline logging
```

## Layout

| File | Role |
|------|------|
| `src/PayPalClient.php` | OAuth token cache; JSON requests capturing `PayPal-Debug-Id`; `idToken()` |
| `src/VaultRecurring.php` | Setup token → vault → charge (FIRST / MIT). The recurring engine. |
| `src/SubscriptionService.php` | Native Subscriptions API (kept for PayPal-wallet payers / if HK card subs get enabled) |
| `src/WebhookVerifier.php` | Signed webhook verification + the events to subscribe to |
| `src/Store.php` | Demo JSON store (→ Eloquent models in Laravel) |
| `public/index.php` | Checkout page (card fields, Fraudnet, plan summary) |
| `public/api.php` | Endpoints: `create-setup-token`, `finalize`, `get`, `cancel`, `webhook` |
| `scripts/setup_plan.php` | Creates the catalog product + plan (for the wallet path) |
| `scripts/run_billing.php` | **The recurring driver** — run on cron; charges due subs as MIT with retry/dunning |

## Optimisation built in

- **Fraudnet / Data Collector**: CMID forwarded server-side as
  `PayPal-Client-Metadata-Id` → higher cross-border acceptance.
- **SCA**: `SCA_WHEN_REQUIRED` — 3DS only where PSD2 mandates it.
- **Retries**: keep retrying (default 8 attempts, spaced) to catch late bank
  approvals; hard-vs-soft handling; nothing cut short.
- **Decline analytics**: every charge stores `PayPal-Debug-Id`, processor
  response code, AVS/CVV, and decline reason.

## Run the demo

```bash
cp .env.example .env         # fill in sandbox credentials
php scripts/setup_plan.php   # (optional) create product/plan for wallet path
php -S 127.0.0.1:8891 -t public
# open http://127.0.0.1:8891
# cron the recurring driver:
php scripts/run_billing.php
```

## Status

- Backend recurring engine (vault → trial → monthly MIT → retry/dunning →
  decline logging): **built and verified end-to-end in sandbox.**
- Front-end card fields (v6 web SDK, hosted number/expiry/cvv, 3DS, SAQ A):
  **working and verified in a real browser** — the fields render, the account is
  eligible for `advanced_cards`, and there are no page errors. See
  ARCHITECTURE.md §7 for the exact integration details.
- Go-live: swap sandbox credentials for live (the SDK host switches automatically)
  and register the live webhook. The live dashboard already shows Advanced Card
  Payments, Vault and JS SDK v6 enabled.
