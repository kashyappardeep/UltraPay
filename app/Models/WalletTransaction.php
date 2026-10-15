<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = ['user_id', 'type', 'amount', 'balance_after', 'reference', 'description'];

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
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'reference' => $this->reference,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}