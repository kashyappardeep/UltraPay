<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoinRate;
use App\Models\Recharge;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Recharge flow:
 *  1) ANB balance se "hold" (rok) hota hai, taaki ek saath do recharge se balance minus na ho.
 *     Is waqt koi history nahi banti, withdrawal_balance nahi badhta.
 *  2) A1Topup SUCCESS  -> withdrawal_balance + ANB, wallet_transactions mein 1 entry
 *     A1Topup FAILED   -> roka hua ANB balance mein wapas, koi history nahi
 *     PENDING/UNKNOWN  -> ANB ruka rahega, status API se final hoga
 */
class RechargeController extends Controller
{
    use ApiResponse;

    private const RECHARGE_URL = 'https://business.a1topup.com/recharge/api';
    private const STATUS_URL = 'https://business.a1topup.com/recharge/status';

    /** POST /api/recharge */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isActive(), 403, 'This account is not active for recharges.');

        $data = $request->validate([
            'order_id' => ['required', 'string', 'alpha_dash', 'min:6', 'max:64'],
            'number' => ['required', 'regex:/^[6-9][0-9]{9}$/'],
            'circle_code' => ['nullable', 'string', 'max:50'],   // postpaid mein zaroori nahi
            'operator_code' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:10', 'max:10000'],
        ], [
            'number.regex' => 'Please enter a valid 10-digit mobile number.',
            'amount.decimal' => 'The recharge amount may have no more than 2 decimal places.',
        ]);
        $data['circle_code'] = $data['circle_code'] ?? null;

        if (! $this->credentials()) {
            return $this->fail('The recharge service is not configured yet.', 503);
        }

        // 1) Current rate se ANB nikalo
        $rate = CoinRate::current();
        $inrRate = $rate ? (string) $rate->getRawOriginal('inr_rate') : '';
        if (! preg_match('/^\d+(\.\d+)?$/', $inrRate) || (float) $inrRate <= 0) {
            return $this->fail('The ANB rate is not available yet. Please try again later.', 503);
        }
        $anbAmount = $this->anbForInr((string) $data['amount'], $inrRate);

        // 2) ANB hold (sirf balance se rokna). History abhi NAHI.
        try {
            $recharge = DB::transaction(function () use ($request, $data, $anbAmount, $inrRate) {
                $existing = Recharge::query()
                    ->where('order_id', $data['order_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $this->ensureSameRequest($existing, $request, $data);

                    return $existing;
                }

                $userId = $request->user()->id;

                // Balance check + rokna EK query mein (race-safe)
                $updated = User::whereKey($userId)
                    ->where('balance', '>=', $anbAmount)
                    ->update(['balance' => DB::raw('balance - ' . $anbAmount)]);

                if ($updated === 0) {
                    throw new RuntimeException('INSUFFICIENT_BALANCE');
                }

                return Recharge::create([
                    ...$data,
                    'user_id' => $userId,
                    'status' => 'processing',
                    'anb_amount' => $anbAmount,
                    'anb_rate' => $inrRate,
                ]);
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'INSUFFICIENT_BALANCE') {
                throw $e;
            }
            $balance = (string) $request->user()->fresh()->getRawOriginal('balance');

            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance. This recharge requires ' . $anbAmount . ' ANB.',
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

        // 3) A1Topup ko recharge bhejo
        [$username, $password] = $this->credentials();
        $params = [
            'username' => $username,
            'pwd' => $password,
            'operatorcode' => $data['operator_code'],
            'number' => $data['number'],
            'amount' => $data['amount'],
            'orderid' => $data['order_id'],
            'format' => 'json',
        ];
        if (! empty($data['circle_code'])) {
            $params['circlecode'] = $data['circle_code'];
        }

        try {
            $response = Http::connectTimeout(10)
                ->timeout(35)
                ->withOptions(['allow_redirects' => false])
                ->get(self::RECHARGE_URL, $params);
        } catch (ConnectionException) {
            // Pata nahi recharge hua ya nahi: ANB ruka rahega, status API se tay hoga
            $this->settle($recharge, 'unknown', [
                'provider_message' => 'Provider response was not received; transaction status is unknown.',
            ]);

            return $this->rechargeResponse($recharge->fresh(), 202);
        }

        $providerData = json_decode($response->body(), true);
        if (! $response->successful() || ! is_array($providerData)) {
            $this->settle($recharge, 'unknown', [
                'provider_message' => 'Provider response could not confirm the transaction status.',
            ]);

            return $this->rechargeResponse($recharge->fresh(), 202);
        }

        // 4) Final hisaab: success = katna pakka, failed = ANB wapas
        $status = $this->providerStatus($providerData);
        $this->settle($recharge, $status, $this->providerFields($providerData, $status));

        $fresh = $recharge->fresh();

        return $this->rechargeResponse(
            $fresh,
            in_array($fresh->status, ['processing', 'unknown'], true) ? 202 : 200
        );
    }

    /**
     * GET /api/recharge/{orderId}/status
     * Pending / unknown recharge ka status A1Topup se check karke final karo.
     */
    public function status(Request $request, string $orderId): JsonResponse
    {
        $recharge = Recharge::where('user_id', $request->user()->id)
            ->where('order_id', $orderId)
            ->first();

        if (! $recharge) {
            return $this->fail('Recharge not found.', 404);
        }

        if (in_array($recharge->status, ['processing', 'unknown'], true) && $this->credentials()) {
            [$username, $password] = $this->credentials();
            try {
                $response = Http::connectTimeout(10)->timeout(30)->get(self::STATUS_URL, [
                    'username' => $username,
                    'pwd' => $password,
                    'orderid' => $recharge->order_id,
                    'format' => 'json',
                ]);
                $providerData = json_decode($response->body(), true);

                if ($response->successful() && is_array($providerData)) {
                    $status = $this->providerStatus($providerData);
                    $this->settle($recharge, $status, $this->providerFields($providerData, $status));
                }
            } catch (ConnectionException) {
                // A1Topup na mile to purana status hi dikhao
            }
        }

        return $this->existingResponse($recharge->fresh());
    }

    /**
     * Recharge ka final hisaab. Ek recharge par sirf EK baar chalta hai (row lock).
     *  success            -> withdrawal_balance + ANB, history mein 1 entry
     *  failed             -> roka hua ANB balance mein wapas, koi history nahi
     *  processing/unknown -> ANB ruka rahega
     */
    public function settle(Recharge $recharge, string $status, array $fields = []): void
    {
        try {
            DB::transaction(function () use ($recharge, $status, $fields) {
                $r = Recharge::whereKey($recharge->id)->lockForUpdate()->first();

                // Pehle hi success / failed ho chuka -> kuch mat karo
                if (! $r || ! in_array($r->status, ['processing', 'unknown'], true) || $r->refunded_at) {
                    return;
                }

                $anb = (string) $r->getRawOriginal('anb_amount');
                if (! preg_match('/^\d+(\.\d+)?$/', $anb)) {
                    $r->update(['status' => 'unknown'] + $fields);

                    return;
                }

                if ($status === 'success') {
                    User::whereKey($r->user_id)->update([
                        'withdrawal_balance' => DB::raw('withdrawal_balance + ' . $anb),
                    ]);
                    $balance = User::whereKey($r->user_id)->value('balance');

                    WalletTransaction::create([
                        'user_id' => $r->user_id,
                        'type' => 'recharge',
                        'wallet' => 'main',
                        'amount' => '-' . $anb,
                        'balance_after' => $balance,
                        'reference' => $r->order_id,
                        'description' => "Recharge {$r->number} ₹" . $r->getRawOriginal('amount'),
                    ]);

                    $r->update(['status' => 'success'] + $fields);
                } elseif ($status === 'failed') {
                    User::whereKey($r->user_id)->update([
                        'balance' => DB::raw('balance + ' . $anb),
                    ]);

                    $r->update(['status' => 'failed', 'refunded_at' => now()] + $fields);
                } else {
                    $r->update(['status' => $status === 'processing' ? 'processing' : 'unknown'] + $fields);
                }
            });
        } catch (Throwable $e) {
            // Hisaab fail hua to ANB ruka rahega (status processing/unknown), status API baad mein theek karegi
            Log::error('Recharge settle failed', ['recharge_id' => $recharge->id, 'error' => $e->getMessage()]);
        }
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
            throw new RuntimeException('Could not calculate the ANB amount.');
        }

        return $anb;
    }

    /** @return array{0:string,1:string}|null */
    private function credentials(): ?array
    {
        $username = config('ultrapay.a1topup_username');
        $password = config('ultrapay.a1topup_password');

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            return null;
        }

        return [$username, $password];
    }

    private function ensureSameRequest(Recharge $recharge, Request $request, array $data): void
    {
        $sameRequest = $recharge->user_id === $request->user()->id
            && $recharge->number === $data['number']
            && ($recharge->circle_code ?: null) === ($data['circle_code'] ?: null)
            && $recharge->operator_code === $data['operator_code']
            && (float) $recharge->getRawOriginal('amount') === (float) $data['amount'];

        abort_unless($sameRequest, 409, 'This order ID has already been used for a different request.');
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
            'failed' => 'Recharge failed. Your ANB has been returned to your wallet.'
                . ($recharge->provider_message ? ' (' . $recharge->provider_message . ')' : ''),
            default => 'The recharge status is not confirmed yet. Please check again in a few minutes.',
        };

        return $this->ok($message, [
            'order_id' => $recharge->order_id,
            'status' => $status,
            'number' => $recharge->number,
            'amount' => $recharge->getRawOriginal('amount'),
            'anb_amount' => $recharge->getRawOriginal('anb_amount'),
            'provider_reference' => $recharge->provider_reference,
            'created_at' => $recharge->created_at?->timezone('Asia/Kolkata')->toIso8601String(),
            'created_at_text' => $recharge->created_at?->timezone('Asia/Kolkata')->format('d M Y, h:i A'),
        ], $httpStatus);
    }

    private function providerFields(array $data, string $status): array
    {
        return [
            'provider_status' => $this->providerStatusValue($data),
            'provider_reference' => $this->providerReference($data),
            'provider_message' => $this->providerMessage($data, $status),
        ];
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

        // A1Topup: "Success" / "Failure" / "Pending"
        $successList = array_map('strtolower', array_merge(
            ['success', 'successful'],
            (array) config('ultrapay.a1topup_success_statuses', [])
        ));
        $failureList = array_map('strtolower', array_merge(
            ['failure', 'failed', 'fail'],
            (array) config('ultrapay.a1topup_failure_statuses', [])
        ));

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
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
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