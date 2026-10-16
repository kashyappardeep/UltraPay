<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OtpException;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Forgot PIN (user login hona chahiye, Bearer token):
 *  1) POST /api/pin/forgot      -> user ke registered email par OTP
 *  2) POST /api/pin/verify-otp  -> OTP sahi to pin_reset_token
 *  3) POST /api/pin/reset       -> naya PIN set
 */
class PinResetController extends Controller
{
    use ApiResponse;

    private const TOKEN_MINUTES = 10;

    public function __construct(private OtpService $otp) {}

    /**
     * STEP 1
     * POST /api/pin/forgot
     * Body: kuch nahi (email user ke account se liya jaata hai)
     */
    public function forgot(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $expires = $this->otp->send($user->email, OtpService::PIN_RESET);
        } catch (OtpException $e) {
            return $this->fail($e->getMessage(), $e->status);
        }

        return $this->ok('A PIN reset OTP has been sent to your email.', [
            'email' => $this->maskEmail($user->email),
            'expires_in_seconds' => $expires,
        ]);
    }

    /**
     * STEP 2
     * POST /api/pin/verify-otp
     * Body: { "otp": "123456" }
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['otp' => ['required', 'digits:6']], [
            'otp.digits' => 'OTP must be 6 digits',
        ]);
        $user = $request->user();

        try {
            $this->otp->verify($user->email, OtpService::PIN_RESET, $data['otp']);
        } catch (OtpException $e) {
            return $this->fail($e->getMessage(), $e->status);
        }

        $token = Str::random(64);
        Cache::put(
            'pin_reset_token:' . hash('sha256', $token),
            ['user_id' => $user->id],
            now()->addMinutes(self::TOKEN_MINUTES)
        );

        return $this->ok('OTP verified. Please set your new PIN.', [
            'pin_reset_token' => $token,
            'expires_in_seconds' => self::TOKEN_MINUTES * 60,
        ]);
    }

    /**
     * STEP 3
     * POST /api/pin/reset
     * Body: { "pin_reset_token": "...", "pin": "7391", "pin_confirmation": "7391" }
     */
    public function reset(Request $request): JsonResponse
    {
        $len = config('ultrapay.pin_length');
        $data = $request->validate([
            'pin_reset_token' => ['required', 'string'],
            'pin' => ['required', "digits:{$len}", 'confirmed'],
        ], [
            'pin.digits' => "PIN must be {$len} digits",
            'pin.confirmed' => 'PIN confirmation does not match.',
        ]);

        $user = $request->user();
        $cacheKey = 'pin_reset_token:' . hash('sha256', $data['pin_reset_token']);
        $session = Cache::get($cacheKey);

        // Token kisi aur user ka ho ya expire ho gaya ho to mana
        if (! is_array($session) || ($session['user_id'] ?? null) !== $user->id) {
            return $this->fail('PIN reset session has expired. Please request a new OTP.', 401);
        }

        if ($this->isWeakPin($data['pin'])) {
            return $this->fail('This PIN is too easy to guess (like 1111 or 1234). Please choose a different PIN.', 422);
        }
        if (Hash::check($data['pin'], $user->pin)) {
            return $this->fail('New PIN must be different from your current PIN.', 422);
        }

        $user->update([
            'pin' => $data['pin'],          // model khud hash karega
            'pin_attempts' => 0,            // galat try ka hisaab saaf
            'pin_locked_until' => null,     // lock tha to khul jaayega
        ]);

        Cache::forget($cacheKey);           // token sirf ek baar chalega

        return $this->ok('Your PIN has been changed successfully.');
    }

    /** kashyap@gmail.com -> ka****p@gmail.com */
    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $shown = strlen($name) <= 2
            ? substr($name, 0, 1) . '*'
            : substr($name, 0, 2) . str_repeat('*', max(1, strlen($name) - 3)) . substr($name, -1);

        return $shown . '@' . $domain;
    }

    private function isWeakPin(string $pin): bool
    {
        $weak = ['0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777',
            '8888', '9999', '1234', '4321', '0123', '1212', '123456', '654321', '000000', '111111'];

        return in_array($pin, $weak, true);
    }
}