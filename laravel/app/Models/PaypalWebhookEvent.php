<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalWebhookEvent extends Model
{
    protected $table = 'paypal_webhook_events';
    protected $primaryKey = 'event_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
