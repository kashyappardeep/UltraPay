<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositRequest extends Model
{
    protected $fillable = [
        'user_id', 'amount_expected', 'amount_received', 'status', 'tx_hash', 'expires_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_expected' => 'decimal:8',
            'amount_received' => 'decimal:8',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Timer mein kitne second bache hain (0 = khatam) */
    public function secondsLeft(): int
    {
        if ($this->status !== 'pending' || ! $this->expires_at) {
            return 0;
        }

        return (int) max(0, now()->diffInSeconds($this->expires_at, false));
    }

    /**
     * pending   = timer chal raha hai
     * timeout   = timer khatam, lekin payment aaya to ab bhi add hoga (24 ghante tak)
     * completed = balance mein add ho gaya
     * expired   = 24 ghante beet gaye
     */
    public function displayStatus(): string
    {
        if ($this->status === 'pending' && $this->secondsLeft() === 0) {
            return 'timeout';
        }

        return $this->status;
    }

    public function toApi(): array
    {
        $anb = config('ultrapay.anb');
        $amount = rtrim(rtrim((string) $this->amount_expected, '0'), '.');
        $raw = self::amountToRaw((string) $this->amount_expected, $anb['decimals']);

        return [
            'id' => $this->id,
            'status' => $this->displayStatus(),
            'amount_to_send' => $amount,
            'amount_received' => $this->amount_received,
            'company_address' => $anb['company_address'],
            'network' => $anb['network'],
            'token_contract' => $anb['token_contract'],

            // QR: sirf address (har wallet scan kar leta hai)
            'qr_address' => $anb['company_address'],
            // QR: address + token + exact amount (MetaMask jaise wallet amount khud bhar dete hain)
            'qr_payment_uri' => "ethereum:{$anb['token_contract']}@{$anb['chain_id']}/transfer"
                . "?address={$anb['company_address']}&uint256={$raw}",

            'seconds_left' => $this->secondsLeft(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'tx_hash' => $this->tx_hash,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** "1.00470000" + 18 decimals -> "1004700000000000000" */
    public static function amountToRaw(string $amount, int $decimals): string
    {
        [$int, $frac] = array_pad(explode('.', $amount, 2), 2, '');
        $frac = substr(str_pad($frac, $decimals, '0'), 0, $decimals);
        $raw = ltrim($int . $frac, '0');

        return $raw === '' ? '0' : $raw;
    }
}