<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalEvent extends Model
{
    protected $table = 'paypal_events';
    protected $guarded = [];

    protected $casts = [
        'data'        => 'array',
        'occurred_at' => 'datetime',
    ];
}
