<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../src/PayPalClient.php';
require __DIR__ . '/../src/SubscriptionService.php';

use PayPalHK\PayPalClient;
use PayPalHK\SubscriptionService;

$cfg = pp_config();
$clientId = $cfg['client_id'];
$currency = $cfg['currency'];
// Per-session Fraudnet correlation id (CMID) -> also forwarded server-side.
$cmid = bin2hex(random_bytes(16));

// The v6 save-payment (vault) card-fields session requires a JWT client token.
// This is the id_token from the OAuth endpoint (a real JWT), NOT the
// Braintree-style token from /v1/identity/generate-token.
$clientToken = '';
try {
    $clientToken = (string) (new PayPalClient($cfg))->idToken();
} catch (\Throwable $e) {
    $clientToken = '';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Checkout</title>
<style>
  :root { --ink:#1a1f36; --muted:#6b7280; --line:#e6e8ee; --brand:#2b5cff; --ok:#0a8f4c; --err:#d92d20; }
  * { box-sizing: border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; color:var(--ink); background:#f5f6fa; }
  .wrap { max-width:440px; margin:40px auto; padding:0 16px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:28px; box-shadow:0 4px 24px rgba(20,30,60,.06); }
  h1 { font-size:20px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:14px; margin:0 0 22px; }
  .plan { border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:22px; background:#fbfcfe; }
  .plan .row { display:flex; justify-content:space-between; font-size:14px; margin:2px 0; }
  .plan .row.total { font-weight:600; margin-top:8px; padding-top:8px; border-top:1px dashed var(--line); }
  .plan .note { color:var(--muted); font-size:12px; margin-top:8px; }
  label { display:block; font-size:13px; font-weight:600; margin:14px 0 6px; }
  .field { min-height:44px; border:1px solid var(--line); border-radius:9px; padding:2px 6px; background:#fff; }
  .field:focus-within { border-color:var(--brand); box-shadow:0 0 0 3px rgba(43,92,255,.12); }
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
</style>

<!-- Fraudnet / Data Collector: telemetry that materially lifts cross-border card acceptance. -->
<script type="application/json" fncls="fncls-b6d5d1d8-2e34-4c67-9c1a-example" id="fconfig">
{"f":"<?php echo $cmid; ?>","s":"membership-checkout-page","sandbox":true}
</script>
<script type="text/javascript" src="https://c.paypal.com/da/r/fb.js"></script>

<!-- PayPal Web SDK v6 (card-fields component) -->
<script async src="https://www.paypal.com/web-sdk/v6/core" onload="onPayPalWebSdkLoaded()"></script>
</head>
<body>
<div class="wrap">
  <div class="card">
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
    <div class="lock">&#128274; Card details are entered in secure fields hosted by PayPal. They never touch this server (PCI-DSS SAQ A).</div>
  </div>
</div>

<script>
var CLIENT_ID = <?php echo json_encode($clientId); ?>;
var CLIENT_TOKEN = <?php echo json_encode($clientToken); ?>;
var CMID = <?php echo json_encode($cmid); ?>;
var btn = document.getElementById('pay-btn');
var msg = document.getElementById('msg');
var session = null;

function show(type, text) { msg.className = 'msg ' + type; msg.textContent = text; }

async function onPayPalWebSdkLoaded() {
  try {
    var sdk = await window.paypal.createInstance({
      clientToken: CLIENT_TOKEN,
      components: ['card-fields'],
      pageType: 'checkout'
    });

    // Save-payment session: vaults the card (with 3DS) for recurring reuse.
    session = sdk.createCardFieldsSavePaymentSession();

    var number = session.createCardFieldsComponent({ type: 'number', placeholder: '1234 5678 9012 3456' });
    var expiry = session.createCardFieldsComponent({ type: 'expiry', placeholder: 'MM/YY' });
    var cvv    = session.createCardFieldsComponent({ type: 'cvv',    placeholder: 'CVV' });

    document.getElementById('pp-number').appendChild(number);
    document.getElementById('pp-expiry').appendChild(expiry);
    document.getElementById('pp-cvv').appendChild(cvv);

    btn.disabled = false;
    btn.innerHTML = 'Start subscription &middot; &euro;2.90';
  } catch (e) {
    show('err', 'Payment module could not initialise: ' + (e && e.message ? e.message : e));
  }
}

async function pay() {
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>Processing&hellip;';
  show('', '');
  try {
    // 1. Get a vault setup token from our backend.
    var stRes = await fetch('api.php?action=create-setup-token', { method: 'POST' });
    var st = await stRes.json();
    if (!st.id) throw new Error(st.error || 'Could not start card save');

    // 2. Submit the card into the setup token (runs 3DS, tokenises card).
    var result = await session.submit(st.id, {
      billingAddress: { countryCode: 'FR' }
    });
    void result;

    // 3. Backend exchanges the token, charges the trial, creates the subscription.
    var finRes = await fetch('api.php?action=finalize', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ setup_token: st.id, cmid: CMID })
    });
    var fin = await finRes.json();
    if (!finRes.ok || fin.error) throw new Error(fin.error || 'Payment failed');

    show('ok', 'Subscription active! ' + fin.subscription_id + ' — trial €2.90 charged, then €49.50/mo from ' + (fin.next_billing || '').slice(0, 10) + '.');
    btn.innerHTML = 'Subscribed';
  } catch (e) {
    btn.disabled = false;
    btn.innerHTML = 'Start subscription &middot; &euro;2.90';
    show('err', 'Could not complete: ' + (e && e.message ? e.message : e));
  }
}

btn.addEventListener('click', pay);
</script>
</body>
</html>
