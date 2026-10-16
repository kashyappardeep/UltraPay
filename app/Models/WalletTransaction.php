<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'type',          // deposit, recharge, send, receive, bill, withdraw
        'wallet',        // main = balance, withdrawal = withdrawal_balance
        'amount',        // + credit, - debit
        'balance_after',
        'reference',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'balance_after' => 'decimal:8',
        ];
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'wallet' => $this->wallet ?? 'main',
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'reference' => $this->reference,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}