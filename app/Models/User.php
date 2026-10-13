<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'mobile', 'email', 'password', 'pin', 'wallet_id', 'hd_index', 'anb_address',
        'referral_code', 'referred_by', 'kyc_status', 'status', 'device_id', 'last_login_at',
    ];

    /** Ye kabhi API response mein nahi jaayenge */
    protected $hidden = ['password', 'pin', 'pin_attempts', 'pin_locked_until', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'pin' => 'hashed',
            'pin_locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function isPinLocked(): bool
    {
        return $this->pin_locked_until !== null && $this->pin_locked_until->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public static function generateWalletId(): string
    {
        do {
            $id = 'ultra' . random_int(1000, 999999);
        } while (static::where('wallet_id', $id)->exists());

        return $id;
    }

    public static function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    /** App ko jo data bhejna hai */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'wallet_id' => $this->wallet_id,
            'anb_address' => $this->anb_address,
            'referral_code' => $this->referral_code,
            'kyc_status' => $this->kyc_status,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}