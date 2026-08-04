<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for inbound PayPal webhooks. PayPal can deliver the same
 * event more than once; we record each event id the first time we process it and
 * skip duplicates, so a redelivered dispute/refund is never double-applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paypal_webhook_events', function (Blueprint $table) {
            $table->string('event_id')->primary(); // PayPal's event id (WH-...)
            $table->timestamp('processed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_webhook_events');
    }
};
