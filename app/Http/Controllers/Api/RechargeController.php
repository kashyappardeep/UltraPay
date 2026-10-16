<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recharge;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\CoinRate;
use App\Models\User;
use App\Models\WalletTransaction;
use RuntimeException;

class RechargeController extends Controller
{
    use ApiResponse;

       public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isActive(), 403, 'Ye account recharge ke liye active nahi hai.');

        $data = $request->validate([
            'order_id' => ['required', 'string', 'alpha_dash', 'min:6', 'max:64'],
            'number' => ['required', 'regex:/^[6-9][0-9]{9}$/'],
            'circle_code' => ['required', 'string', 'max:50'],
            'operator_code' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:10', 'max:10000'],
        ], [
            'number.regex' => 'Sahi 10 digit mobile number daalein.',
            'amount.decimal' => 'Recharge amount mein zyada se zyada 2 decimal places ho sakte hain.',
        ]);

        $username = config('ultrapay.a1topup_username');
        $password = config('ultrapay.a1topup_password');
        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            return $this->fail('Recharge service abhi configure nahi hai.', 503);
        }

        // 1) Current rate se ANB nikalo
        $rate = CoinRate::current();
        if (! $rate || (float) $rate->inr_rate <= 0) {
            return $this->fail('ANB ka rate abhi set nahi hai. Thodi der baad try karein.', 503);
        }
        $anbAmount = $this->anbForInr((string) $data['amount'], (string) $rate->inr_rate);

        try {
            $recharge = DB::transaction(function () use ($request, $data, $anbAmount, $rate) {
                $existing = Recharge::query()
                    ->where('order_id', $data['order_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $this->ensureSameRequest($existing, $request, $data);

                    return $existing;
                }

                $userId = $request->user()->id;

                // 2) + 3) Balance check aur katna EK hi query mein (race-safe):
                //    balance - ANB, withdrawal_balance + ANB, sirf tab jab balance >= ANB
                $updated = User::whereKey($userId)
                    ->where('balance', '>=', $anbAmount)
                    ->update([
                        'balance' => DB::raw('balance - ' . $anbAmount),
                        'withdrawal_balance' => DB::raw('withdrawal_balance + ' . $anbAmount),
                    ]);

                if ($updated === 0) {
                    throw new RuntimeException('INSUFFICIENT_BALANCE');
                }

                $user = User::whereKey($userId)->first(['balance', 'withdrawal_balance']);

                $recharge = Recharge::create([
                    ...$data,
                    'user_id' => $userId,
                    'status' => 'processing',
                    'anb_amount' => $anbAmount,
                    'anb_rate' => $rate->inr_rate,
                ]);

                WalletTransaction::create([
                    'user_id' => $userId,
                    'type' => 'recharge',
                    'wallet' => 'main',
                    'amount' => '-' . $anbAmount,
                    'balance_after' => $user->balance,
                    'reference' => $data['order_id'],
                    'description' => "Recharge {$data['number']} ₹{$data['amount']}",
                ]);
                WalletTransaction::create([
                    'user_id' => $userId,
                    'type' => 'recharge',
                    'wallet' => 'withdrawal',
                    'amount' => $anbAmount,
                    'balance_after' => $user->withdrawal_balance,
                    'reference' => $data['order_id'],
                    'description' => "Recharge {$data['number']} ₹{$data['amount']}",
                ]);

                return $recharge;
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'INSUFFICIENT_BALANCE') {
                throw $e;
            }
            $balance = (string) $request->user()->fresh()->balance;

            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance. Is recharge ke liye ' . $anbAmount . ' ANB chahiye.',
                'data' => [
                    'required_anb' => $anbAmount,
                    'balance' => $balance,
                    'short_by' => number_format(max(0, (float) $anbAmount - (float) $balance), 8, '.', ''),
                ],
            ], 422);
        } catch (QueryException $exception) {
            $existing = Recharge::query()->where('order_id', $data['order_id'])->first();
            if (! $existing) {
                throw $exception;
            }
            $this->ensureSameRequest($existing, $request, $data);

            return $this->existingResponse($existing);
        }

        if ($recharge->wasRecentlyCreated === false) {
            return $this->existingResponse($recharge);
        }

        // 4) Provider ko recharge bhejo
        try {
            $response = Http::connectTimeout(10)
                ->timeout(35)
                ->withOptions(['allow_redirects' => false])
                ->get('https://business.a1topup.com/recharge/api', [
                    'username' => $username,
                    'pwd' => $password,
                    'circlecode' => $data['circle_code'],
                    'operatorcode' => $data['operator_code'],
                    'number' => $data['number'],
                    'amount' => $data['amount'],
                    'orderid' => $data['order_id'],
                    'format' => 'json',
                ]);
        } catch (ConnectionException) {
            // Pata nahi recharge hua ya nahi: ANB ruka rahega, refund NAHI (status check se tay hoga)
            $recharge->update([
                'status' => 'unknown',
                'provider_message' => 'Provider response was not received; transaction status is unknown.',
            ]);

            return $this->rechargeResponse($recharge->fresh(), 202);
        }

        $providerData = json_decode($response->body(), true);
        if (! $response->successful() || ! is_array($providerData)) {
            $recharge->update([
                'status' => 'unknown',
                'provider_message' => 'Provider response could not confirm the transaction status.',
            ]);

            return $this->rechargeResponse($recharge->fresh(), 202);
        }

        $status = $this->providerStatus($providerData);
        $recharge->update([
            'status' => $status,
            'provider_status' => $this->providerStatusValue($providerData),
            'provider_reference' => $this->providerReference($providerData),
            'provider_message' => $this->providerMessage($providerData, $status),
        ]);

        // 5) Recharge fail: ANB wapas
        if ($status === 'failed') {
            $this->refundAnb($recharge);
        }

        return $this->rechargeResponse(
            $recharge->fresh(),
            in_array($status, ['processing', 'unknown'], true) ? 202 : 200
        );
    }

        /**
     * ₹ amount ke liye kitne ANB (8 decimal, upar ki taraf round, taaki company ko ghata na ho).
     * Jaise ₹199 / 4.425 = 44.97175141... -> 44.97175142
     */
    private function anbForInr(string $inr, string $inrRate): string
    {
        if (function_exists('bcdiv')) {
            $anb = bcdiv($inr, $inrRate, 8);
            if (bccomp(bcmul($anb, $inrRate, 10), $inr, 10) < 0) {
                $anb = bcadd($anb, '0.00000001', 8);
            }
        } else {
            $anb = number_format(ceil(((float) $inr / (float) $inrRate) * 1e8) / 1e8, 8, '.', '');
        }

        if (! preg_match('/^\d+\.\d{8}$/', $anb)) {
            throw new RuntimeException('ANB amount calculate nahi ho paaya.');
        }

        return $anb;
    }

    /**
     * Recharge fail hua: balance wapas, withdrawal_balance se utna kam.
     * Do baar call ho jaaye to bhi sirf ek baar refund hoga (refunded_at).
     */
    public function refundAnb(Recharge $recharge): void
    {
        DB::transaction(function () use ($recharge) {
            $r = Recharge::whereKey($recharge->id)->lockForUpdate()->first();
            if (! $r || $r->refunded_at || ! $r->anb_amount) {
                return;
            }

            $anb = (string) $r->getRawOriginal('anb_amount');
            if (! preg_match('/^\d+(\.\d+)?$/', $anb)) {
                return;
            }

            User::whereKey($r->user_id)->update([
                'balance' => DB::raw('balance + ' . $anb),
                'withdrawal_balance' => DB::raw('GREATEST(withdrawal_balance - ' . $anb . ', 0)'),
            ]);
            $user = User::whereKey($r->user_id)->first(['balance', 'withdrawal_balance']);

            WalletTransaction::create([
                'user_id' => $r->user_id,
                'type' => 'recharge_refund',
                'wallet' => 'main',
                'amount' => $anb,
                'balance_after' => $user->balance,
                'reference' => $r->order_id,
                'description' => 'Recharge fail: ANB wapas',
            ]);
            WalletTransaction::create([
                'user_id' => $r->user_id,
                'type' => 'recharge_refund',
                'wallet' => 'withdrawal',
                'amount' => '-' . $anb,
                'balance_after' => $user->withdrawal_balance,
                'reference' => $r->order_id,
                'description' => 'Recharge fail: ANB wapas',
            ]);

            $r->update(['refunded_at' => now()]);
        });
    }

    private function ensureSameRequest(Recharge $recharge, Request $request, array $data): void
    {
        $sameRequest = $recharge->user_id === $request->user()->id
            && $recharge->number === $data['number']
            && $recharge->circle_code === $data['circle_code']
            && $recharge->operator_code === $data['operator_code']
            && (float) $recharge->amount === (float) $data['amount'];

        abort_unless($sameRequest, 409, 'Order ID pehle se kisi doosri request ke liye istemal hua hai.');
    }

    private function existingResponse(Recharge $recharge): JsonResponse
    {
        return $this->rechargeResponse(
            $recharge,
            in_array($recharge->status, ['processing', 'unknown'], true) ? 202 : 200
        );
    }

    private function rechargeResponse(Recharge $recharge, int $httpStatus): JsonResponse
    {
        $status = in_array($recharge->status, ['processing', 'unknown'], true)
            ? 'pending'
            : $recharge->status;
        $message = match ($status) {
            'success' => 'Recharge successful.',
            'failed' => $recharge->provider_message
                ?: 'Recharge failed: ' . ($recharge->provider_status ?: 'Provider ne reject kiya.'),
            default => 'Recharge ka status abhi confirm nahi hua. Isi order ID ko dobara bhejein; naya order na banayein.',
        };

        return $this->ok($message, [
            'order_id' => $recharge->order_id,
            'status' => $status,
            'number' => $recharge->number,
            'amount' => $recharge->amount,
            'provider_reference' => $recharge->provider_reference,
        ], $httpStatus);
    }

    private function providerStatus(array $data): string
    {
        if (($data['success'] ?? null) === true || ($data['success'] ?? null) === 'true') {
            return 'success';
        }
        if (($data['success'] ?? null) === false || ($data['success'] ?? null) === 'false') {
            return 'failed';
        }

        $status = Str::lower(trim((string) ($data['status'] ?? '')));
        $successList = array_map('strtolower', (array) config('ultrapay.a1topup_success_statuses', []));
        $failureList = array_map('strtolower', (array) config('ultrapay.a1topup_failure_statuses', []));

        if (in_array($status, $successList, true)) {
            return 'success';
        }
        if (in_array($status, $failureList, true)) {
            return 'failed';
        }
        if (in_array($status, ['pending', 'processing', 'in_progress'], true)) {
            return 'processing';
        }

        return 'unknown';
    }

    private function providerStatusValue(array $data): ?string
    {
        $status = $data['status'] ?? null;

        return is_scalar($status) ? Str::limit((string) $status, 100, '') : null;
    }

    private function providerReference(array $data): ?string
    {
        foreach (['txid', 'transaction_id', 'refid'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return Str::limit((string) $data[$key], 100, '');
            }
        }

        return null;
    }

    private function providerMessage(array $data, ?string $status = null): ?string
    {
        foreach (['message', 'msg'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return Str::limit((string) $data[$key], 500, '');
            }
        }

        if ($status === 'failed') {
            foreach (['opid', 'error'] as $key) {
                if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                    return Str::limit((string) $data[$key], 500, '');
                }
            }
        }

        return null;
    }
}
