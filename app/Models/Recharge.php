<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recharge extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'number',
        'circle_code',
        'operator_code',
        'amount',
        'status',
        'provider_status',
        'provider_reference',
        'provider_message',
        'anb_amount', 
        'anb_rate', 
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'anb_amount' => 'decimal:36,8',
            'anb_rate' => 'decimal:30,10',
        ];
    }
}
