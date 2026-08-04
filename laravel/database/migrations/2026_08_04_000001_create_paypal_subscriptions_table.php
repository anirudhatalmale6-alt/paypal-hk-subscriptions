<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The subscription is the heart of the system: one row per customer sign-up. It
 * carries the funding source (vault token), the billing state machine used by the
 * dunning engine, and — captured from day one — the analytics dimensions the
 * future dashboard needs (segment / country / product / brand, stable customer
 * identity, signup cohort, first-touch acquisition).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paypal_subscriptions', function (Blueprint $table) {
            // Our own opaque id (sub_xxx). Not PayPal's — carried as custom_id on
            // every transaction so webhooks can map a payment back to the sub.
            $table->string('id')->primary();

            // --- Funding source (what the MIT engine charges each month) ---
            $table->string('vault_id')->nullable()->index();   // PayPal vault token
            $table->string('source_type')->default('card');    // card | paypal | apple_pay
            $table->string('order_id')->nullable();            // wallet path order id
            $table->string('setup_token')->nullable()->index(); // idempotency key (card path)

            // --- Card metadata (for the per-card trial cap; NO PAN stored) ---
            $table->string('card_fp', 64)->nullable()->index(); // non-reversible fingerprint
            $table->string('card_last4', 4)->nullable();
            $table->string('card_brand')->nullable();

            $table->string('email')->nullable()->index();

            // --- Pricing (per-market, from the segment registry) ---
            $table->string('currency', 3)->default('EUR');
            $table->decimal('monthly_amount', 10, 2)->nullable();
            $table->string('soft_descriptor')->nullable();     // bank statement label

            // --- Analytics dimensions: kept separate from the start so per-market
            //     metrics stay clean no matter how many products/countries launch ---
            $table->string('segment')->index();                // e.g. fr-vehicle-history-report
            $table->string('country', 2)->nullable()->index(); // FR, GB, AU...
            $table->string('product')->nullable()->index();    // vehicle-history-report...
            $table->string('brand')->nullable()->index();      // thesmartlookup...
            $table->string('product_label')->nullable();

            // --- Dashboard identity / cohort / acquisition (LTV, retention, churn) ---
            $table->string('customer_id')->nullable()->index();   // your app's user id (best key)
            $table->string('customer_ref')->nullable()->index();  // stable hashed identity
            $table->string('cohort', 7)->nullable()->index();     // signup month YYYY-MM
            $table->json('acquisition')->nullable();              // full first-touch object
            $table->string('acq_source')->nullable();            // flattened for grouping
            $table->string('acq_channel')->nullable()->index();  // google | meta | ...
            $table->string('acq_medium')->nullable();

            // --- Lifecycle / dunning state machine (see RetryPolicy) ---
            $table->string('status')->default('active')->index(); // active|past_due|cancelled|payment_failed
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('insf_streak')->default(0);   // insufficient-funds streak (half-price trigger)
            $table->unsignedInteger('fraud_retries')->default(0);
            $table->timestamp('first_fail_at')->nullable();
            $table->timestamp('next_billing_at')->nullable()->index(); // what `due()` scans
            $table->timestamp('last_charge_at')->nullable();
            $table->json('last_recovery')->nullable();            // recovered-after-retries analytics
            $table->string('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('activated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_subscriptions');
    }
};
