<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer subscription. `id` is our own string id (sub_xxx), not auto-inc, so
 * incrementing is off and the key type is string.
 */
class PaypalSubscription extends Model
{
    protected $table = 'paypal_subscriptions';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'acquisition'     => 'array',
        'last_recovery'   => 'array',
        'monthly_amount'  => 'decimal:2',
        'retry_count'     => 'integer',
        'insf_streak'     => 'integer',
        'fraud_retries'   => 'integer',
        'first_fail_at'   => 'datetime',
        'next_billing_at' => 'datetime',
        'last_charge_at'  => 'datetime',
        'cancelled_at'    => 'datetime',
        'activated_at'    => 'datetime',
    ];

    public function charges(): HasMany
    {
        return $this->hasMany(PaypalCharge::class, 'subscription_id')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaypalEvent::class, 'subscription_id')->orderBy('id');
    }
}
