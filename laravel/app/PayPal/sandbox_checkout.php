<?php
/**
 * Sandbox checkout page (hosted card fields + Apple Pay / PayPal wallet), rendered
 * by PayPalController::sandboxPage(). It is the demo checkout adapted to the
 * Laravel routes (/paypal/*) and is served ONLY in sandbox mode. Use it to
 * validate the full flow end-to-end; your production checkout page reuses the
 * exact same JSON endpoints.
 *
 * Variables provided by the controller: $clientId, $clientToken, $currency,
 * $cmid, $sdkHost, $isSandbox.
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Checkout (sandbox)</title>
<style>
  :root { --ink:#1a1f36; --muted:#6b7280; --line:#e6e8ee; --brand:#2b5cff; --ok:#0a8f4c; --err:#d92d20; }
  * { box-sizing: border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; color:var(--ink); background:#f5f6fa; }
  .wrap { max-width:440px; margin:40px auto; padding:0 16px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:28px; box-shadow:0 4px 24px rgba(20,30,60,.06); }
  h1 { font-size:20px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:14px; margin:0 0 22px; }
  .banner { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; font-size:12px; border-radius:8px; padding:8px 10px; margin-bottom:16px; }
  .plan { border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:22px; background:#fbfcfe; }
  .plan .row { display:flex; justify-content:space-between; font-size:14px; margin:2px 0; }
  .plan .row.total { font-weight:600; margin-top:8px; padding-top:8px; border-top:1px dashed var(--line); }
  .plan .note { color:var(--muted); font-size:12px; margin-top:8px; }
  label { display:block; font-size:13px; font-weight:600; margin:14px 0 6px; }
  .field { height:46px; border:1px solid var(--line); border-radius:9px; padding:0 6px; background:#fff; display:flex; align-items:center; }
  .field:focus-within { border-color:var(--brand); box-shadow:0 0 0 3px rgba(43,92,255,.12); }
  .field paypal-hosted-card-field { display:block; width:100%; height:44px; }
  .grid { display:flex; gap:12px; }
  .grid > div { flex:1; }
  input.plain { width:100%; height:40px; border:0; outline:0; font-size:15px; font-family:inherit; color:var(--ink); background:transparent; }
  button { width:100%; height:48px; margin-top:22px; border:0; border-radius:10px; background:var(--brand); color:#fff; font-size:16px; font-weight:600; cursor:pointer; }
  button:disabled { opacity:.55; cursor:not-allowed; }
  .msg { margin-top:14px; font-size:14px; padding:10px 12px; border-radius:8px; display:none; }
  .msg.ok { display:block; background:#eafaf0; color:var(--ok); }
  .msg.err { display:block; background:#fdecec; color:var(--err); }
  .lock { text-align:center; color:var(--muted); font-size:12px; margin-top:16px; }
  .spin { display:inline-block; width:15px; height:15px; border:2px solid rgba(255,255,255,.5); border-top-color:#fff; border-radius:50%; animation:s .7s linear infinite; vertical-align:-2px; margin-right:6px; }
  @keyframes s { to { transform:rotate(360deg); } }
  .wallets { margin-top:6px; }
  .divider { display:flex; align-items:center; text-align:center; color:var(--muted); font-size:12px; margin:18px 0 14px; }
  .divider::before, .divider::after { content:""; flex:1; height:1px; background:var(--line); }
  .divider span { padding:0 10px; }
  paypal-button, apple-pay-button { display:block; width:100%; min-height:44px; }
  apple-pay-button { --apple-pay-button-height:44px; --apple-pay-button-border-radius:10px; margin-bottom:10px; }
</style>

<!-- Fraudnet / Data Collector config: telemetry that materially lifts cross-border
     card acceptance. The fb.js collector itself is loaded at the end of <body>
     so document.body exists when it initialises. -->
<script type="application/json" fncls="fnparams-dede7cc5-15fd-4c75-a9f4-36c430ee3a99" id="fconfig">
{"f":"<?php echo $cmid; ?>","s":"membership-checkout-page","sandbox":<?php echo $isSandbox ? 'true' : 'false'; ?>}
</script>

<!-- PayPal Web SDK v6 (card-fields component) - host matches credential env -->
<script async src="<?php echo $sdkHost; ?>/web-sdk/v6/core" onload="onPayPalWebSdkLoaded()"></script>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="banner">SANDBOX TEST PAGE &mdash; no real money moves. Not linked from the live site.</div>
    <h1>Complete your subscription</h1>
    <p class="sub">Pay securely with your card. No PayPal account needed.</p>

    <div class="plan">
      <div class="row"><span>48-hour trial (today)</span><span>&euro;2.90</span></div>
      <div class="row"><span>Then monthly</span><span>&euro;49.50 / month</span></div>
      <div class="row total"><span>Due today</span><span>&euro;2.90</span></div>
      <div class="note">Your trial starts now. After 48 hours you'll be charged &euro;49.50 monthly. Cancel anytime.</div>
    </div>

    <form id="card-form" autocomplete="on">
      <label>Card number</label>
      <div id="pp-number" class="field"></div>

      <div class="grid">
        <div>
          <label>Expiry</label>
          <div id="pp-expiry" class="field"></div>
        </div>
        <div>
          <label>CVV</label>
          <div id="pp-cvv" class="field"></div>
        </div>
      </div>

      <label for="name">Name on card</label>
      <div class="field"><input id="name" class="plain" type="text" autocomplete="cc-name" placeholder="Full name"></div>

      <button id="pay-btn" type="button" disabled>Loading&hellip;</button>
      <div id="msg" class="msg"></div>
    </form>

    <div id="wallets" class="wallets" hidden>
      <div class="divider"><span>or pay with</span></div>
      <apple-pay-button id="applepay-button" buttonstyle="black" type="plain" hidden></apple-pay-button>
      <paypal-button id="paypal-button" type="pay" hidden></paypal-button>
    </div>
    <div class="lock">&#128274; Card details are entered in secure fields hosted by PayPal. They never touch this server (PCI-DSS SAQ A).</div>
  </div>
</div>

<script>
var CLIENT_ID = <?php echo json_encode($clientId); ?>;
var CLIENT_TOKEN = <?php echo json_encode($clientToken); ?>;
var CMID = <?php echo json_encode($cmid); ?>;
var CURRENCY_CODE = <?php echo json_encode($currency); ?>;
// All calls go to the Laravel routes (routes/paypal.php). Base is absolute so the
// page works when served at /paypal/sandbox.
var API = '/paypal';
var btn = document.getElementById('pay-btn');
var msg = document.getElementById('msg');
var cardSession = null;

function getAttribution() {
  var KEY = 'sl_first_touch';
  try { var stored = JSON.parse(localStorage.getItem(KEY) || 'null'); if (stored) return stored; } catch (e) {}
  var q = new URLSearchParams(location.search);
  var a = {
    source: q.get('utm_source'), medium: q.get('utm_medium'), campaign: q.get('utm_campaign'),
    content: q.get('utm_content'), term: q.get('utm_term'), gclid: q.get('gclid'), fbclid: q.get('fbclid'),
    referrer: document.referrer || null, landing_page: location.href
  };
  try { localStorage.setItem(KEY, JSON.stringify(a)); } catch (e) {}
  return a;
}

function show(type, text) { msg.className = 'msg ' + type; msg.textContent = text; }

function showActive(fin) {
  show('ok', 'Subscription active! ' + fin.subscription_id + ' — trial €2.90 charged, then €49.50/mo from '
    + (fin.next_billing || '').slice(0, 10) + '.' + (fin.paypal_capture_id ? ' PayPal transaction: ' + fin.paypal_capture_id : ''));
}

async function initWallets() {
  if (!CLIENT_TOKEN) return;
  try {
    var wsdk = await window.paypal.createInstance({ clientToken: CLIENT_TOKEN, components: ['paypal-payments'], pageType: 'checkout' });
    var methods = await wsdk.findEligibleMethods({ currencyCode: CURRENCY_CODE, paymentFlow: 'VAULT_WITH_PAYMENT' });
    var opts = {
      savePayment: true,
      onApprove: async function (data) { await captureWallet(data.orderId); },
      onCancel: function () { show('', ''); },
      onError: function (e) { show('err', 'Payment error: ' + (e && e.message ? e.message : e)); }
    };
    var shown = false;
    if (methods.isEligible('paypal')) {
      var ppSession = wsdk.createPayPalOneTimePaymentSession(opts);
      var ppBtn = document.getElementById('paypal-button');
      ppBtn.removeAttribute('hidden');
      ppBtn.addEventListener('click', function () {
        var order = createWalletOrder('paypal');
        ppSession.start({ presentationMode: 'auto' }, order).catch(function (e) { show('err', 'PayPal error: ' + (e && e.message ? e.message : e)); });
      });
      shown = true;
    }
    if (methods.isEligible('applepay') && typeof wsdk.createApplePayOneTimePaymentSession === 'function') {
      var apSession = wsdk.createApplePayOneTimePaymentSession(opts);
      var apBtn = document.getElementById('applepay-button');
      apBtn.removeAttribute('hidden');
      apBtn.addEventListener('click', function () {
        var order = createWalletOrder('apple_pay');
        apSession.start({ presentationMode: 'auto' }, order).catch(function (e) { show('err', 'Apple Pay error: ' + (e && e.message ? e.message : e)); });
      });
      shown = true;
    }
    if (shown) document.getElementById('wallets').removeAttribute('hidden');
  } catch (e) {
    if (window.console) console.log('wallet init skipped: ' + (e && e.message ? e.message : e));
  }
}

function createWalletOrder(method) {
  return fetch(API + '/create-wallet-order', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ method: method, return_url: location.href, cancel_url: location.href, brand_name: 'TheSmartLookup', attribution: getAttribution() })
  }).then(function (r) { return r.json(); }).then(function (d) {
    if (!d.order_id) throw new Error(d.error || 'could not create order');
    return { orderId: d.order_id };
  });
}

async function captureWallet(orderId) {
  show('', '');
  var r = await fetch(API + '/capture-order', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ order_id: orderId, attribution: getAttribution() })
  });
  var fin = await r.json();
  if (!r.ok || fin.error) { show('err', fin.message || fin.error || 'Payment failed'); return; }
  showActive(fin);
}

async function onPayPalWebSdkLoaded() {
  try {
    var sdk = await window.paypal.createInstance({ clientId: CLIENT_ID, components: ['card-fields'] });
    var methods = await sdk.findEligibleMethods({ currencyCode: CURRENCY_CODE });
    if (!methods.isEligible('advanced_cards')) {
      show('err', 'Card payments are not enabled on this account (advanced_cards not eligible).');
      return;
    }
    cardSession = sdk.createCardFieldsSavePaymentSession();
    var number = cardSession.createCardFieldsComponent({ type: 'number', placeholder: '1234 5678 9012 3456' });
    var expiry = cardSession.createCardFieldsComponent({ type: 'expiry', placeholder: 'MM/YY' });
    var cvv    = cardSession.createCardFieldsComponent({ type: 'cvv',    placeholder: 'CVV' });
    document.getElementById('pp-number').appendChild(number);
    document.getElementById('pp-expiry').appendChild(expiry);
    document.getElementById('pp-cvv').appendChild(cvv);
    btn.disabled = false;
    btn.innerHTML = 'Start subscription &middot; &euro;2.90';
  } catch (e) {
    show('err', 'Payment module could not initialise: ' + (e && e.message ? e.message : e));
  }
  initWallets();
}

async function pay() {
  if (!cardSession) return;
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>Processing&hellip;';
  show('', '');
  try {
    var stRes = await fetch(API + '/create-setup-token', { method: 'POST' });
    var st = await stRes.json();
    if (!st.id) throw new Error(st.error || 'Could not start card save');
    var result = await cardSession.submit(st.id);
    if (result && result.state && result.state !== 'succeeded') {
      if (result.state === 'canceled') throw new Error('Card authentication was cancelled.');
      throw new Error((result.data && result.data.message) || 'Card was declined.');
    }
    var finRes = await fetch(API + '/finalize', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ setup_token: st.id, email: null, cmid: CMID, attribution: getAttribution() })
    });
    var fin = await finRes.json();
    if (!finRes.ok || fin.error) throw new Error(fin.message || fin.error || 'Payment failed');
    showActive(fin);
    btn.innerHTML = 'Subscribed';
  } catch (e) {
    btn.disabled = false;
    btn.innerHTML = 'Start subscription &middot; &euro;2.90';
    show('err', 'Could not complete: ' + (e && e.message ? e.message : e));
  }
}

btn.addEventListener('click', pay);
</script>

<!-- Fraudnet collector: loaded last so document.body is present on init. -->
<script type="text/javascript" src="https://c.paypal.com/da/r/fb.js"></script>
</body>
</html>
