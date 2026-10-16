<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PinController extends Controller
{
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
            'pin.different' => 'The new PIN must be different from the current PIN.',
        ]);

        $user = $request->user();
        [$ok, $message, $status] = self::check($user, $data['old_pin']);
        if (! $ok) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        $user->update(['pin' => $data['pin']]);

        return response()->json(['success' => true, 'message' => 'PIN changed successfully.']);
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

            return [false, "Your PIN is locked. Please try again in {$mins} minutes.", 423];
        }

        if (! Hash::check($pin, $user->pin)) {
            $user->increment('pin_attempts');
            $max = config('ultrapay.pin_max_attempts');

            if ($user->pin_attempts >= $max) {
                $user->update([
                    'pin_attempts' => 0,
                    'pin_locked_until' => now()->addMinutes(config('ultrapay.pin_lock_minutes')),
                ]);

                return [false, 'Too many incorrect PIN attempts. Your wallet is locked for ' . config('ultrapay.pin_lock_minutes') . ' minutes.', 423];
            }

            $left = $max - $user->pin_attempts;

            return [false, "Incorrect PIN. {$left} attempts remaining.", 422];
        }

        if ($user->pin_attempts > 0) {
            $user->update(['pin_attempts' => 0]);
        }

        return [true, 'PIN verified successfully.', 200];
    }
}
