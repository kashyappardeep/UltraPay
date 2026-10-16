<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpException;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** Forgot password: email -> OTP -> verify -> naya password */
class PasswordController extends Controller
{
    use ApiResponse;

    public function __construct(private OtpService $otp) {}

    /** STEP 1:  POST /api/password/forgot   { "email" } */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:150']]);
        $email = strtolower(trim($data['email']));

        $user = User::where('email', $email)->first();
        if (! $user) {
            return $this->fail('This email address is not registered.', 404);
        }
        if (! $user->isActive()) {
            return $this->fail('This account is blocked. Please contact support.', 403);
        }

        try {
            $expires = $this->otp->send($email, OtpService::RESET);
        } catch (OtpException $e) {
            return $this->fail($e->getMessage(), $e->status);
        }

        return $this->ok('A password reset OTP has been sent to your email address.', [
            'email' => $email,
            'expires_in_seconds' => $expires,
        ]);
    }

    /** STEP 2:  POST /api/password/verify-otp   { "email", "otp" } */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'otp' => ['required', 'digits:6'],
        ]);
        $email = strtolower(trim($data['email']));

        if (! User::where('email', $email)->exists()) {
            return $this->fail('This email address is not registered.', 404);
        }

        try {
            $this->otp->verify($email, OtpService::RESET, $data['otp']);
        } catch (OtpException $e) {
            return $this->fail($e->getMessage(), $e->status);
        }

        $resetToken = Str::random(64);
        Cache::put(
            'reset_token:' . hash('sha256', $resetToken),
            ['email' => $email],
            now()->addMinutes(15)
        );

        return $this->ok('OTP verified. You can now set a new password.', [
            'reset_token' => $resetToken,
            'expires_in_seconds' => 15 * 60,
        ]);
    }

    /** STEP 3:  POST /api/password/reset   { "reset_token", "password", "password_confirmation" } */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reset_token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'password.confirmed' => 'The passwords do not match.',
        ]);

        $cacheKey = 'reset_token:' . hash('sha256', $data['reset_token']);
        $session = Cache::get($cacheKey);
        $email = is_array($session) ? ($session['email'] ?? null) : null;
        if (! $email) {
            return $this->fail('The password reset session has expired. Please request a new OTP.', 401);
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            Cache::forget($cacheKey);

            return $this->fail('This email address is not registered.', 404);
        }

        $user->update(['password' => $data['password']]);   // model khud hash karega

        // Security: saare phone se logout (purane token hata do)
        $user->tokens()->delete();
        Cache::forget($cacheKey);

        return $this->ok('Password changed. Please log in with your new password.');
    }
}