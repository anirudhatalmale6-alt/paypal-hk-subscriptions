<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every charge attempt against a subscription — the trial and each monthly MIT
 * charge, successful or declined — with the classified response so revenue and
 * decline analytics are queryable directly. In the demo these live nested inside
 * the subscription JSON; here they are their own table for clean reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paypal_charges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('subscription_id')->index();
            $table->foreign('subscription_id')->references('id')->on('paypal_subscriptions')->cascadeOnDelete();

            $table->string('type');                 // trial | recurring
            $table->decimal('amount', 10, 2);
            $table->boolean('ok')->default(false);

            $table->string('capture_id')->nullable()->index(); // PayPal capture id (real txn ref)
            $table->string('response_code')->nullable();       // processor response code
            $table->string('response_label')->nullable();      // human label
            $table->string('category')->nullable();            // approved|soft_decline|hard_decline|...
            $table->boolean('retryable')->nullable();
            $table->json('decline')->nullable();               // full decline detail
            $table->string('debug_id')->nullable();            // PayPal debug id for support

            $table->timestamp('charged_at')->nullable();       // the record's own timestamp
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_charges');
    }
};
