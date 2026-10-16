<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpException;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private OtpService $otp) {}

    /** REGISTER STEP 1:  POST /api/otp/send   { "email" } */
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:150']]);
        $email = $this->cleanEmail($data['email']);

        if (User::where('email', $email)->exists()) {
            return $this->fail('This email is already registered. Please log in.', 409);
        }

        try {
            $expires = $this->otp->send($email, OtpService::REGISTER);
        } catch (OtpException $e) {
            return $this->fail($e->getMessage(), $e->status);
        }

        return $this->ok('An OTP has been sent to your email address.', [
            'email' => $email,
            'expires_in_seconds' => $expires,
        ]);
    }

    /** REGISTER STEP 2:  POST /api/otp/verify   { "email", "otp" } */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'otp' => ['required', 'digits:6'],
        ]);
        $email = $this->cleanEmail($data['email']);

        if (User::where('email', $email)->exists()) {
            return $this->fail('This email is already registered. Please log in.', 409);
        }

        try {
            $this->otp->verify($email, OtpService::REGISTER, $data['otp']);
        } catch (OtpException $e) {
            return $this->fail($e->getMessage(), $e->status);
        }

        $regToken = Str::random(64);
        Cache::put(
            'reg_token:' . hash('sha256', $regToken),
            ['email' => $email],
            now()->addMinutes(config('ultrapay.registration_token_minutes'))
        );

        return $this->ok('Email verified. Complete your registration.', [
            'registration_token' => $regToken,
            'email' => $email,
            'expires_in_seconds' => config('ultrapay.registration_token_minutes') * 60,
        ]);
    }

    /**
     * REGISTER STEP 3:  POST /api/register
     * { "registration_token", "name", "mobile", "password", "password_confirmation",
     *   "pin", "pin_confirmation", "referral_code"(optional), "device_id"(optional) }
     */
    public function register(Request $request): JsonResponse
    {
        $pinLen = config('ultrapay.pin_length');

        $data = $request->validate([
            'registration_token' => ['required', 'string'],
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'mobile' => ['required', 'regex:/^[6-9][0-9]{9}$/', 'unique:users,mobile'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'pin' => ['required', "digits:{$pinLen}", 'confirmed'],
            'referral_code' => ['nullable', 'string', 'exists:users,referral_code'],
            'device_id' => ['nullable', 'string', 'max:255'],
        ], [
            'mobile.regex' => 'Please enter a valid 10-digit mobile number.',
            'mobile.unique' => 'This mobile number is already registered.',
            'password.confirmed' => 'The passwords do not match.',
            'pin.digits' => "The PIN must contain {$pinLen} digits.",
            'pin.confirmed' => 'The PINs do not match.',
            'referral_code.exists' => 'The referral code is invalid.',
        ]);

        if ($this->isWeakPin($data['pin'])) {
            return $this->fail('The PIN is too easy to guess (for example, 1111 or 1234). Choose a different PIN.', 422);
        }

        $cacheKey = 'reg_token:' . hash('sha256', $data['registration_token']);
        $session = Cache::get($cacheKey);
        $email = is_array($session) ? ($session['email'] ?? null) : null;
        if (! $email) {
            return $this->fail('The registration session has expired. Please verify your OTP again.', 401);
        }
        if (User::where('email', $email)->exists()) {
            Cache::forget($cacheKey);

            return $this->fail('This email is already registered. Please log in.', 409);
        }

        $referrerId = isset($data['referral_code'])
            ? User::where('referral_code', $data['referral_code'])->value('id')
            : null;

        $user = DB::transaction(function () use ($data, $email, $referrerId) {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => $email,
                'mobile' => $data['mobile'],
                'password' => $data['password'],      // model khud hash karega
                'pin' => $data['pin'],                // model khud hash karega
                'wallet_id' => User::generateWalletId(),
                'referral_code' => User::generateReferralCode(),
                'referred_by' => $referrerId,
                'device_id' => $data['device_id'] ?? null,
            ]);

            // HD wallet index = user id. Ye kabhi nahi badlega.
            $user->update(['hd_index' => $user->id]);

            return $user;
        });

        Cache::forget($cacheKey);

        return $this->ok('Registration successful. Welcome to UltraPay!',
            $this->issueToken($user->fresh(), $data['device_id'] ?? null), 201);
    }

    /** LOGIN:  POST /api/login   { "email", "password", "device_id"(optional) } */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string'],
            'device_id' => ['nullable', 'string', 'max:255'],
        ]);
        $email = $this->cleanEmail($data['email']);

        // 5 galat try ke baad 5 minute ka block
        $key = 'login:' . $email . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $mins = (int) ceil(RateLimiter::availableIn($key) / 60);

            return $this->fail("Too many failed attempts. Please try logging in again in {$mins} minutes.", 429);
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 300);

            return $this->fail('The email address or password is incorrect.', 401);
        }
        if (! $user->isActive()) {
            return $this->fail('This account is blocked. Please contact support.', 403);
        }

        RateLimiter::clear($key);

        return $this->ok('Login successful.', $this->issueToken($user, $data['device_id'] ?? null));
    }

    /** GET /api/me  (Bearer token) */
    public function me(Request $request): JsonResponse
    {
        return $this->ok('User details.', ['user' => $request->user()->toApi()]);
    }

    /** POST /api/logout  (Bearer token) */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok('Logout successful.');
    }

    // ---------------------------------------------------------------

    private function issueToken(User $user, ?string $deviceId): array
    {
        // Ek time par ek hi phone: purane saare token hata do
        $user->tokens()->delete();

        $user->update([
            'last_login_at' => now(),
            'device_id' => $deviceId ?? $user->device_id,
        ]);

        return [
            'token' => $user->createToken('ultrapay-app')->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $user->toApi(),
        ];
    }

    private function cleanEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function isWeakPin(string $pin): bool
    {
        $weak = ['0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777',
            '8888', '9999', '1234', '4321', '0123', '1212', '123456', '654321', '000000', '111111'];

        return in_array($pin, $weak, true);
    }
}