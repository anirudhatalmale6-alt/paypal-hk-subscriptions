<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalCharge extends Model
{
    protected $table = 'paypal_charges';
    protected $guarded = [];

    protected $casts = [
        'amount'     => 'decimal:2',
        'ok'         => 'boolean',
        'retryable'  => 'boolean',
        'decline'    => 'array',
        'charged_at' => 'datetime',
    ];
}
