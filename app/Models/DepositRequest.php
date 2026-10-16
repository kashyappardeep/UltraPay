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

    /** Seconds remaining on the timer (0 = expired). */
    public function secondsLeft(): int
    {
        if ($this->status !== 'pending' || ! $this->expires_at) {
            return 0;
        }

        return (int) max(0, now()->diffInSeconds($this->expires_at, false));
    }

    /**
     * pending   = timer is running
     * timeout   = timer ended, but a payment will still be credited for up to 24 hours
     * completed = amount has been added to the balance
     * expired   = 24 hours have passed
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

    $token = $anb['token_contract'];
    $to = $anb['company_address'];
    $chainId = $anb['chain_id'];

    // EIP-681: standard format that pre-fills the token, address, and amount in the wallet when scanned
    $paymentUri = "ethereum:{$token}@{$chainId}/transfer?address={$to}&uint256={$raw}";

    // Links to open a wallet app directly on this phone
    $links = [
        'metamask' => "https://metamask.app.link/send/{$token}@{$chainId}/transfer?address={$to}&uint256={$raw}",
    ];
    if ($chainId === 56) { // Trust Wallet's BSC coin ID is 20000714
        $links['trust_wallet'] = 'https://link.trustwallet.com/send?coin=20000714'
            . "&token_id={$token}&address={$to}&amount={$amount}";
    }

    return [
        'id' => $this->id,
        'status' => $this->displayStatus(),
        'amount_to_send' => $amount,
        'amount_received' => $this->amount_received,
        'company_address' => $to,
        'network' => $anb['network'],
        'token_contract' => $token,

        'qr_payment_uri' => $paymentUri,   // Use this for the QR code (includes the amount)
        'qr_address' => $to,               // Fallback: QR code containing only the address
        'wallet_links' => $links,

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