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

### Payment methods (one engine, three funding sources)

All three methods feed the **same** vault + MIT engine, so there is one recurring
system, one retry policy, one analytics dataset:

| Method | First charge (trial) | Recurring |
|---|---|---|
| Card | hosted card fields -> vault -> charge €2.90 (CIT, 3DS) | `card.vault_id` MIT |
| Apple Pay | Apple Pay sheet -> order €2.90 vault-on-success -> capture | `card.vault_id` MIT |
| PayPal wallet | PayPal button -> order €2.90 vault-on-success -> capture | `paypal.vault_id` MIT |

The Apple Pay / PayPal popups show **only the €2.90 trial** (the order is created
for that amount; no recurring terms in the popup). The recurring terms live on the
checkout page. `VaultRecurring::createOrderWithVault()` builds the trial order with
`store_in_vault=ON_SUCCESS`; `captureOrder()` captures it and returns the vaulted
token id + source type; `charge(..., $sourceType)` then bills monthly against the
right `payment_source` (`card` or `paypal`).

Endpoints: `create-wallet-order` (returns the €2.90 order to approve) and
`capture-order` (captures + vaults + creates the subscription, same trial-cap and
decline-analytics as the card flow). Wallet buttons use the browser client token
(`response_type=client_token`) and the `paypal-payments` v6 component with
`paymentFlow: VAULT_WITH_PAYMENT` + `savePayment: true`.

Verification status: card = verified end-to-end in a browser. PayPal wallet =
button renders + €2.90 order creation verified; the approve->capture->vault leg
needs a sandbox buyer login to run through. Apple Pay = built to the same design;
final test needs a registered Apple Pay domain + an Apple device/Safari.

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
| `src/WebhookHandler.php` | Idempotent dispatch of verified events -> subscription state changes (dispute -> suspend, etc.) |
| `src/ResponseCodes.php` | Maps processor response codes -> label + category + retryable (drives retries & analytics) |
| `public/api.php` | `create-setup-token`, `finalize`, `get`, `cancel`, `webhook` |
| `public/index.php` | Checkout page: hosted card fields, Fraudnet, plan summary |
| `scripts/run_billing.php` | **Cron driver.** Charges due subscriptions as MIT; retry/dunning policy |
| `scripts/setup_plan.php` | Creates catalog product + plan (wallet path only) |

## 5. Data model (maps to two Laravel tables)

`subscriptions`: `id`, `vault_id`, `setup_token` (idempotency key), `card_fp`
(card fingerprint for the trial cap), `card_last4`, `card_brand`, `email`,
`currency`, `monthly_amount`,
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

## 6b. Abuse & double-charge safeguards

- **Idempotent finalize.** The `finalize` endpoint is keyed on the vault setup
  token. A re-submitted or double-clicked finalize returns the *existing*
  subscription (`idempotent: true`) instead of charging the trial again. The
  trial charge itself also carries a stable `PayPal-Request-Id` derived from the
  setup token, so PayPal de-duplicates a concurrent retry server-side too.
- **Per-card trial cap.** A card may start at most `MAX_TRIALS_PER_CARD` (= 2)
  trials. On finalize we fingerprint the card from its vault metadata
  (`sha256(brand|last4|expiry)`, stored as `card_fp` — never the PAN) and count
  prior successful trials for that fingerprint. On the 3rd attempt the flow is
  blocked *before any charge*, and the just-created vault token is deleted so a
  blocked card leaves nothing stored. The cap is per-card, not global — a
  different card is unaffected. (Verified end-to-end: 1st/2nd succeed, 3rd
  blocked, other cards still work.)

## 6c. Webhooks

Inbound events are signature-verified (`/v1/notifications/verify-webhook-signature`)
and dispatched by `WebhookHandler`. Every branch is idempotent (dedupe on the
event id), and each transaction carries `custom_id = <subscription id>` so events
can be mapped back to the subscription (disputes/reversals also match on the
capture id). Events registered for the vault + MIT card model:

| Event | Effect |
|---|---|
| `PAYMENT.CAPTURE.COMPLETED` | reconcile a successful charge (info) |
| `PAYMENT.CAPTURE.DENIED` | annotate an out-of-band denied capture |
| `PAYMENT.CAPTURE.REFUNDED` | annotate the subscription with the refund |
| `PAYMENT.CAPTURE.REVERSED` | funds reversed -> **suspend** |
| `CUSTOMER.DISPUTE.CREATED` | chargeback opened -> **suspend** (per requirement) |
| `CUSTOMER.DISPUTE.RESOLVED` / `.UPDATED` | annotate the dispute outcome |
| `VAULT.PAYMENT-TOKEN.DELETED` | stored card gone -> cannot bill -> **suspend** |

The endpoint returns `200` fast on verified events (so PayPal does not
re-deliver) and `400` on a failed signature.

## 6d. Processor response-code mapping

`ResponseCodes::classify($code)` maps each `processor_response.response_code`
to `{label, category, retryable}`. Categories:

- `approved` — success (`0000`).
- `soft_decline` (**retryable**) — issuer *might* approve later: insufficient
  funds (`5120`), do-not-honor (`0500`), generic decline (`5100`). These are the
  ones a bank often clears on the 5th/6th attempt.
- `hard_decline` (**not retryable**) — permanent: expired (`5400`), card closed
  (`5140`), lost/stolen (`9520`), invalid/restricted (`5180`), fraud (`9500`)…
- `authentication_required` — issuer wants SCA again (`5650`); cannot be silently
  retried as an MIT.
- `unknown` — undocumented code; treated as soft/retryable so we never give up
  early (still capped by the scheduler's `MAX_RETRIES`).

Every charge (trial and recurring) stores its `category` and `retryable` flag
alongside the raw code, AVS/CVV and `PayPal-Debug-Id`. This is both the input to
the smart-retry policy and the dataset for decline analytics. **The exact retry
schedule (spacing, max attempts, which categories to chase) is a policy layer on
top of this and is being finalised with the client.**

## 7. Front-end card fields — RESOLVED (2026-08-03)

The **front-end card fields** use PayPal's v6 web SDK
(`createCardFieldsSavePaymentSession`). Getting them to mount required four
things that were previously blocking; all are now solved and verified in a real
browser (Playwright, sandbox):

1. **Load the SDK core from the matching environment host.** Sandbox credentials
   require `https://www.sandbox.paypal.com/web-sdk/v6/core`; live credentials
   require `https://www.paypal.com/web-sdk/v6/core`. Loading the production core
   with sandbox credentials fails with "missing clientId auth". The page now
   derives the host from the credential environment.
2. **Initialise with the public `clientId`, not a token.** The card-fields /
   save-payment session initialises with `createInstance({ clientId })` using the
   plain public client id (safe to expose in the browser). A client token is only
   needed for Fastlane.
3. **Eligibility gate.** Call `sdkInstance.findEligibleMethods()` and confirm
   `isEligible('advanced_cards')` before creating the fields. On this account,
   sandbox returns eligible — confirming advanced (unbranded) card processing.
4. **The fields render inside a shadow DOM.** `createCardFieldsComponent({type})`
   returns a `<paypal-hosted-card-field>` custom element; its secure iframe lives
   in the element's shadow root (so an outer `querySelectorAll('iframe')` sees
   nothing even when it is working).

We also mint a browser-safe **client token** server-side for completeness
(`PayPalClient::browserClientToken`, `response_type=client_token` — the only
variant that returns a JWT carrying a `client_id` claim; `id_token` and
`/v1/identity/generate-token` do not). It is available for the Fastlane / vaulted-
token paths but is not required for the card fields themselves.

Separately, the **Fraudnet** config uses PayPal's fixed `fncls` value
(`fnparams-dede7cc5-15fd-4c75-a9f4-36c430ee3a99`) and the `fb.js` collector is
loaded at the end of `<body>` so `document.body` exists when it initialises.

Verified in-browser: all three fields (number, expiry, cvv) render their PayPal-
hosted iframes, the pay button enables, and there are zero page errors.

## 8. Go-live checklist

1. PayPal confirms live Advanced Card Payments + reference transactions (MIT) on
   the account (questions 3, 5, 6). NOTE: the live dashboard already shows
   Advanced Credit and Debit Card Payments, Save payment methods (Vault) and
   JavaScript SDK v6 all enabled — so the architecture's building blocks are live.
2. Swap sandbox credentials for live in `.env` (the SDK core host switches to
   `www.paypal.com` automatically) and set `PAYPAL_SDK_DOMAINS` to the live
   checkout domain(s).
3. Register the live webhook, set `PAYPAL_WEBHOOK_ID`.
4. Confirm EUR/USD balances are held (no auto-convert to HKD) — question 9.
5. Smoke-test one real card end to end; schedule `run_billing.php` on cron.

## 9. Verified sandbox evidence

All confirmed with live sandbox API calls on 2026-08-02:
- Product `PROD-08220995RB841623P`, plan `P-55F29148CG377610GNJXTMWI`
  (2-day TRIAL €2.90 → MONTH €49.50, `payment_failure_threshold` 4).
- Vault → trial charge (FIRST) `COMPLETED`, response code `0000`.
- Two consecutive MIT recurring charges (€49.50) `COMPLETED`.
- Scheduler run: due subscription charged (`capture 8R95…`), status `active`,
  `next_billing_at` advanced one month.
