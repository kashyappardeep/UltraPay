<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChainDeposit extends Model
{
    protected $fillable = [
        'tx_hash', 'log_index', 'from_address', 'amount', 'block_number', 'deposit_request_id', 'status',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8'];
    }
}