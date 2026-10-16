<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\Otp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email OTP bhejna aur verify karna.
 * purpose: 'register' (naya account) ya 'reset' (password bhool gaye)
 */
class OtpService
{
    public const REGISTER = 'register';
    public const RESET = 'reset';
    public const PIN_RESET = 'pin_reset';

    /** @return int  OTP kitne second mein expire hoga */
    public function send(string $email, string $purpose): int
    {
        // Ek email par 60 second mein ek hi OTP
        $wait = config('ultrapay.otp_resend_seconds');
        $last = Otp::where('email', $email)->where('purpose', $purpose)->latest('id')->first();
        if ($last && $last->created_at->diffInSeconds(now()) < $wait) {
            $left = (int) ceil($wait - $last->created_at->diffInSeconds(now()));
            throw new OtpException("Please wait {$left} seconds before requesting another OTP.", 429);
        }

        $minutes = config('ultrapay.otp_expiry_minutes');
        $code = config('ultrapay.otp_test_mode')
            ? (string) config('ultrapay.otp_test_code')
            : (string) random_int(100000, 999999);

        // Purane OTP hata do, naya save (hash karke)
        Otp::where('email', $email)->where('purpose', $purpose)->whereNull('verified_at')->delete();
        $otp = Otp::create([
            'email' => $email,
            'purpose' => $purpose,
            'otp' => Hash::make($code),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        if (! config('ultrapay.otp_test_mode')) {
            try {
                Mail::to($email)->send(new OtpMail($code, $minutes, $purpose));
            } catch (Throwable $e) {
                $otp->delete();
                Log::error('OTP email fail', ['error' => $e->getMessage()]);
                throw new OtpException('Could not send the email. Please try again later.', 500);
            }
        }

        return $minutes * 60;
    }

    /** OTP check karo. Sahi ho to verified mark kar deta hai. */
    public function verify(string $email, string $purpose, string $code): void
    {
        $otp = Otp::where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            throw new OtpException('Please request an OTP first.');
        }
        if ($otp->isExpired()) {
            throw new OtpException('The OTP has expired. Please request a new one.');
        }
        if ($otp->attempts >= config('ultrapay.otp_max_attempts')) {
            throw new OtpException('Too many incorrect attempts. Please request a new OTP.', 429);
        }

        if (! Hash::check($code, $otp->otp)) {
            $otp->increment('attempts');
            $left = config('ultrapay.otp_max_attempts') - $otp->attempts;
            throw new OtpException("Incorrect OTP. {$left} attempts remaining.");
        }

        $otp->update(['verified_at' => now()]);
    }
}