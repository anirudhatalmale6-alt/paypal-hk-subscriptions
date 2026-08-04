<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Out-of-band lifecycle events on a subscription (dispute, refund, reversal,
 * recovered-after-retries, cancelled). Appended by the webhook handler and the
 * dunning engine; an audit trail separate from the charge attempts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paypal_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('subscription_id')->index();
            $table->foreign('subscription_id')->references('id')->on('paypal_subscriptions')->cascadeOnDelete();

            $table->string('type');           // dispute | refund | reversal | recovered | cancelled ...
            $table->json('data')->nullable(); // full event payload
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_events');
    }
};
