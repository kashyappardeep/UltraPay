<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpException;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PinController extends Controller
{
    use ApiResponse;

    public function __construct(private OtpService $otp) {}

    /**
     * POST /api/pin/verify  (Bearer token)
     * Body: { "pin": "4826" }
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits:' . config('ultrapay.pin_length')]]);

        [$ok, $message, $status] = self::check($request->user(), $data['pin']);

        return response()->json(['success' => $ok, 'message' => $message], $status);
    }

    /**
     * POST /api/pin/change  (Bearer token)
     * Body: { "old_pin": "4826", "pin": "7391", "pin_confirmation": "7391" }
     */
    public function change(Request $request): JsonResponse
    {
        $len = config('ultrapay.pin_length');
        $data = $request->validate([
            'old_pin' => ['required', "digits:{$len}"],
            'pin' => ['required', "digits:{$len}", 'confirmed', 'different:old_pin'],
        ], [
            'pin.confirmed' => 'The new PINs do not match.',
            'pin.different' => 'The new PIN must be different from the old one.',
        ]);

        $user = $request->user();
        [$ok, $message, $status] = self::check($user, $data['old_pin']);
        if (! $ok) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        $user->update(['pin' => $data['pin']]);

        return response()->json(['success' => true, 'message' => 'PIN changed.']);
    }

    /**
     * FORGOT PIN STEP 1:  POST /api/pin/forgot   { "email" }
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:150']]);
        $email = strtolower(trim($data['email']));

        $user = User::where('email', $email)->first();
        if (! $user) {
            return $this->fail('This email is not registered.', 404);
        }
        if (! $user->isActive()) {
            return $this->fail('This account is blocked. Please contact support.', 403);
        }

        try {
            $expires = $this->otp->send($email, OtpService::PIN_RESET);
        } catch (OtpException $e) {
            return $this->fail($e->getMessage(), $e->status);
        }

        return $this->ok('A PIN reset OTP has been sent to your email.', [
            'email' => $email,
            'expires_in_seconds' => $expires,
        ]);
    }

    /**
     * FORGOT PIN STEP 2:  POST /api/pin/verify-otp   { "email", "otp" }
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'otp' => ['required', 'digits:6'],
        ]);
        $email = strtolower(trim($data['email']));

        if (! User::where('email', $email)->exists()) {
            return $this->fail('This email is not registered.', 404);
        }

        try {
            $this->otp->verify($email, OtpService::PIN_RESET, $data['otp']);
        } catch (OtpException $e) {
            return $this->fail($e->getMessage(), $e->status);
        }

        $resetToken = Str::random(64);
        Cache::put(
            'pin_reset_token:' . hash('sha256', $resetToken),
            ['email' => $email],
            now()->addMinutes(15)
        );

        return $this->ok('The OTP is correct. Please set a new PIN.', [
            'reset_token' => $resetToken,
            'expires_in_seconds' => 15 * 60,
        ]);
    }

    /**
     * FORGOT PIN STEP 3:  POST /api/pin/reset
     * Body: { "reset_token", "pin", "pin_confirmation" }
     */
    public function reset(Request $request): JsonResponse
    {
        $len = config('ultrapay.pin_length');
        $data = $request->validate([
            'reset_token' => ['required', 'string'],
            'pin' => ['required', "digits:{$len}", 'confirmed'],
        ], [
            'pin.confirmed' => 'The PINs do not match.',
        ]);

        $cacheKey = 'pin_reset_token:' . hash('sha256', $data['reset_token']);
        $session = Cache::get($cacheKey);
        $email = is_array($session) ? ($session['email'] ?? null) : null;
        if (! $email) {
            return $this->fail('The reset session has expired. Please request a new OTP.', 401);
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            Cache::forget($cacheKey);

            return $this->fail('This email is not registered.', 404);
        }

        $user->update([
            'pin' => $data['pin'],
            'pin_attempts' => 0,
            'pin_locked_until' => null,
        ]);
        Cache::forget($cacheKey);

        // Token rehne dete hain: user isi device par naye PIN se unlock karega
        return $this->ok('PIN reset. Please unlock with your new PIN.');
    }

    /**
     * PIN check + galat try ka hisaab.
     * Recharge / send / bill controller bhi isi ko use karenge:
     *   [$ok, $msg, $status] = PinController::check($user, $request->pin);
     *
     * @return array{0: bool, 1: string, 2: int}
     */
    public static function check(User $user, string $pin): array
    {
        if ($user->isPinLocked()) {
            $mins = (int) ceil(now()->diffInMinutes($user->pin_locked_until));

            return [false, "PIN is locked. Please try again after {$mins} minute(s).", 423];
        }

        if (! Hash::check($pin, $user->pin)) {
            $user->increment('pin_attempts');
            $max = config('ultrapay.pin_max_attempts');

            if ($user->pin_attempts >= $max) {
                $user->update([
                    'pin_attempts' => 0,
                    'pin_locked_until' => now()->addMinutes(config('ultrapay.pin_lock_minutes')),
                ]);

                return [false, 'Too many wrong PIN attempts. Wallet locked for ' . config('ultrapay.pin_lock_minutes') . ' minute(s).', 423];
            }

            $left = $max - $user->pin_attempts;

            return [false, "Wrong PIN. {$left} attempt(s) left.", 422];
        }

        if ($user->pin_attempts > 0) {
            $user->update(['pin_attempts' => 0]);
        }

        return [true, 'PIN is correct.', 200];
    }
}
