# Architecture & Implementation Notes

Developer-facing document for the PayPal Hong Kong white-label card subscription
integration. Written so your engineering team can understand the design, the
constraints we discovered, and the exact API behaviour we verified.

Last updated: 2026-08-02.

---

## 1. Goal

Customers in Europe pay by credit/debit card **directly on our checkout** (no
PayPal login/redirect), starting on a **48-hour paid trial (€2.90)** that
converts to a **€49.50/month recurring** subscription. White-label (PSD2/SCA
compliant, PCI-DSS SAQ A), with resilient retries and decline analytics.

## 2. The key constraint we discovered (read this first)

The original plan was PayPal's **Subscriptions API v2** with a card as the funding
source. We tested this directly against the sandbox on this account and it is
**not supported for a Hong Kong merchant**:

```
POST /v1/billing/subscriptions   (subscriber.payment_source.card)
→ 422 MERCHANT_COUNTRY_NOT_ENABLED_FOR_CARD_PAYMENTS
  "Merchant country is not supported for processing card payments."
```

This is an account/country-level restriction on PayPal's side, not a code issue.
Passing a vaulted token to the subscription is silently ignored — the API falls
back to a PayPal-wallet approval (`payment_source` comes back empty with a wallet
`approve` link).

**However**, the same account fully supports the building blocks we need:

| Capability | API call | Result |
|---|---|---|
| One-time card charge | `POST /v2/checkout/orders` (card) | ✅ `201 COMPLETED` |
| Vault a card | `POST /v3/vault/payment-tokens` | ✅ `201` |
| Vault setup token (for browser card fields) | `POST /v3/vault/setup-tokens` | ✅ `201 CREATED` |
| Charge a vaulted card | `POST /v2/checkout/orders` (`card.vault_id`) | ✅ `201 COMPLETED` |
| **Recurring** charge on vaulted card (MIT) | order + `stored_credential` (MERCHANT/SUBSEQUENT) | ✅ `201 COMPLETED` |
| Card-funded subscription (v2) | `POST /v1/billing/subscriptions` (card) | ❌ `422 MERCHANT_COUNTRY_NOT_ENABLED` |

**Conclusion:** implement recurring as **vault + self-driven Merchant-Initiated
Transactions (MIT)**, not PayPal's subscription object. This also gives us more
control over retry timing and decline analysis than PayPal's black-box retries —
which aligns with the optimisation goals.

## 3. Architecture

```
 Browser (checkout)                         Backend (Laravel-ready PHP)                 PayPal
 ─────────────────                          ──────────────────────────                 ──────
 hosted card fields  ──create-setup-token──►                        ──► POST /v3/vault/setup-tokens
   (3DS, SAQ A)      ◄───── setup token id ──                       ◄── setup token
 session.submit(setupToken)  ───────────────────── card tokenised into setup token (3DS runs) ──►
        │
        └──finalize(setupToken)────────────►  confirmSetupToken   ──► POST /v3/vault/payment-tokens
                                              (exchange)          ◄── vault_id (permanent)
                                              charge trial FIRST  ──► POST /v2/checkout/orders (vault_id, CIT)
                                              persist subscription
                                              next_billing = now + 48h
 ─────────────────────────────────────────────────────────────────────────────────────────────
 cron: run_billing.php (hourly) ─► for each due sub ─► charge MIT ─► POST /v2/checkout/orders
                                                                     (vault_id, stored_credential MERCHANT/SUBSEQUENT)
                                    on success: next_billing += 1 month
                                    on decline: past_due, retry (spaced) up to N, then suspend
```

### Why MIT matters for SCA

The **first** charge is customer-initiated (`stored_credential.payment_initiator =
CUSTOMER`, `usage = FIRST`) and carries 3DS/SCA for European cards. Every
**subsequent** monthly charge is merchant-initiated (`MERCHANT / SUBSEQUENT`),
which is **SCA-exempt** — so the customer is never challenged again. Getting this
flag right is what lets renewals run unattended.

## 4. Code map

| File | Responsibility |
|---|---|
| `src/PayPalClient.php` | OAuth token cache; JSON requests; captures `PayPal-Debug-Id` on every call; `idToken()` |
| `src/VaultRecurring.php` | `createSetupToken` → `confirmSetupToken` → `charge(vaultId, amount, currency, FIRST\|MIT)`; `normaliseCapture()` extracts response/AVS/CVV/decline |
| `src/Store.php` | Subscription persistence (JSON in the demo → Eloquent models in Laravel). Exposes `due(now)` for the scheduler |
| `src/SubscriptionService.php` | Native Subscriptions API wrapper — retained for the PayPal-wallet payer path and in case card subs get enabled for HK later |
| `src/WebhookVerifier.php` | `verify-webhook-signature`; the event list to subscribe to |
| `public/api.php` | `create-setup-token`, `finalize`, `get`, `cancel`, `webhook` |
| `public/index.php` | Checkout page: hosted card fields, Fraudnet, plan summary |
| `scripts/run_billing.php` | **Cron driver.** Charges due subscriptions as MIT; retry/dunning policy |
| `scripts/setup_plan.php` | Creates catalog product + plan (wallet path only) |

## 5. Data model (maps to two Laravel tables)

`subscriptions`: `id`, `vault_id`, `email`, `currency`, `monthly_amount`,
`status` (active | past_due | suspended | payment_failed | cancelled),
`created_at`, `next_billing_at`, `retry_count`, `last_charge_at`.

`charges` (embedded now, own table in Laravel): `type` (trial | recurring),
`amount`, `ok`, `capture_id`, `response_code`, `avs_code`, `cvv_code`,
`decline`, `debug_id`, `at`.

## 6. Optimisation layer

- **Fraudnet / Data Collector** — the client-metadata-id (CMID) generated in the
  browser is forwarded server-side as the `PayPal-Client-Metadata-Id` header on
  the charge. This is the single biggest lever on cross-border acceptance for
  European cards; loading the script without forwarding the header does nothing.
- **SCA** — `verification.method = SCA_WHEN_REQUIRED`: 3DS is enforced only where
  PSD2 mandates it (EU/UK) and skipped elsewhere, minimising friction.
- **Retries** — `run_billing.php` keeps retrying declines (`MAX_RETRIES = 8`,
  spaced `RETRY_INTERVAL_HOURS = 48`) so late bank approvals (attempt 5–6) are
  captured; the subscription only suspends once retries are exhausted.
- **Decline analytics** — every attempt stores the processor response code,
  AVS/CVV result, decline reason, and `PayPal-Debug-Id`. That is the dataset for
  tuning retry timing and pricing later. (Note: end-customer notifications are
  intentionally disabled per requirement — recovery is fully silent.)

## 7. Open dependency on PayPal (raised with their reps)

The backend is complete and verified. The **front-end card fields** use PayPal's
v6 web SDK (`https://www.paypal.com/web-sdk/v6/core`,
`createCardFieldsSavePaymentSession`). This component requires
`createInstance({ clientToken })` where `clientToken` must be a **JWT containing a
`client_id` claim**. Neither the `/v1/identity/generate-token` token (Braintree
format) nor the standard OAuth `id_token` (no `client_id` claim) satisfies this,
and the exact mint method for this account is not in PayPal's public docs. This is
question 12 in the list sent to PayPal support; their answer unblocks the front
end immediately (the entire flow behind it is already built and tested).

## 8. Go-live checklist

1. PayPal confirms live Advanced Card Payments + reference transactions (MIT) on
   the account (questions 3, 5, 6).
2. PayPal confirms the v6 SDK client-token method (question 12).
3. Swap sandbox credentials for live in `.env`.
4. Register the live webhook, set `PAYPAL_WEBHOOK_ID`.
5. Confirm EUR/USD balances are held (no auto-convert to HKD) — question 9.
6. Smoke-test one real card end to end; schedule `run_billing.php` on cron.

## 9. Verified sandbox evidence

All confirmed with live sandbox API calls on 2026-08-02:
- Product `PROD-08220995RB841623P`, plan `P-55F29148CG377610GNJXTMWI`
  (2-day TRIAL €2.90 → MONTH €49.50, `payment_failure_threshold` 4).
- Vault → trial charge (FIRST) `COMPLETED`, response code `0000`.
- Two consecutive MIT recurring charges (€49.50) `COMPLETED`.
- Scheduler run: due subscription charged (`capture 8R95…`), status `active`,
  `next_billing_at` advanced one month.
