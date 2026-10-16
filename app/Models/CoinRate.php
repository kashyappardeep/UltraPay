<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinRate extends Model
{
    protected $fillable = ['symbol', 'usdt_rate', 'usdt_inr', 'inr_rate', 'source', 'note'];

    protected function casts(): array
    {
        return [
            'usdt_rate' => 'decimal:10',
            'usdt_inr' => 'decimal:4',
            'inr_rate' => 'decimal:10',
        ];
    }

    /** Abhi ka rate (sabse nayi row) */
    public static function current(string $symbol = 'ANB'): ?self
    {
        return static::where('symbol', $symbol)->latest('id')->first();
    }

    /** a x b, 10 decimal tak */
    public static function multiply(string $a, string $b, int $scale = 10): string
    {
        if (function_exists('bcmul')) {
            return bcmul($a, $b, $scale);
        }

        return number_format((float) $a * (float) $b, $scale, '.', '');
    }

    /** a / b, 8 decimal tak */
    public static function divide(string $a, string $b, int $scale = 8): string
    {
        if ((float) $b == 0.0) {
            return '0';
        }
        if (function_exists('bcdiv')) {
            return bcdiv($a, $b, $scale);
        }

        return number_format((float) $a / (float) $b, $scale, '.', '');
    }

    /** ₹ amount ke liye kitne ANB lagenge */
    public function inrToAnb(string $inr): string
    {
        return self::divide($inr, (string) $this->inr_rate);
    }

    /** USDT amount ke liye kitne ANB */
    public function usdtToAnb(string $usdt): string
    {
        return self::divide($usdt, (string) $this->usdt_rate);
    }

    /** ANB ki value ₹ mein */
    public function anbToInr(string $anb): string
    {
        return self::multiply($anb, (string) $this->inr_rate, 2);
    }

    /** ANB ki value USDT mein */
    public function anbToUsdt(string $anb): string
    {
        return self::multiply($anb, (string) $this->usdt_rate, 4);
    }

    public function toApi(): array
    {
        return [
            'symbol' => $this->symbol,
            'anb_usdt' => $this->usdt_rate,   // 1 ANB = ? USDT
            'usdt_inr' => $this->usdt_inr,    // 1 USDT = ? INR
            'anb_inr' => $this->inr_rate,     // 1 ANB = ? INR
            'source' => $this->source,
            'updated_at' => $this->created_at?->toIso8601String(),
        ];
    }
}