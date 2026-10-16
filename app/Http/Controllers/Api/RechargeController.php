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

        try {
            $recharge = DB::transaction(function () use ($request, $data) {
                $existing = Recharge::query()
                    ->where('order_id', $data['order_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $this->ensureSameRequest($existing, $request, $data);

                    return $existing;
                }

                return Recharge::create([
                    ...$data,
                    'user_id' => $request->user()->id,
                    'status' => 'processing',
                ]);
            });
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

        return $this->rechargeResponse(
            $recharge->fresh(),
            in_array($status, ['processing', 'unknown'], true) ? 202 : 200
        );
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
