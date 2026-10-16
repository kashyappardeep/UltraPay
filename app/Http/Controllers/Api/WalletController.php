<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\WalletTransaction;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use App\Models\CoinRate;
use App\Services\DepositScanner;
use Illuminate\Support\Facades\Cache;
use Throwable;

class WalletController extends Controller
{
    use ApiResponse;

    /** GET /api/wallet  -> balance + last 20 transactions */
       /** GET /api/wallet  -> balance (ANB + INR + USDT) + last 20 transactions */
       /** GET /api/wallet  -> balance (ANB + INR) + last 20 transactions */
    public function wallet(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();
        $balance = (string) $user->balance;          // users table se ANB balance
        $withdrawable = (string) $user->withdrawal_balance;
        $rate = CoinRate::current();                             // coin_rates table se current rate (null ho sakta hai)
        $usdtbalance = $rate ? $rate->anbToUsdt($balance) : null; // ANB value USDT mein

        $txns = WalletTransaction::where('user_id', $user->id)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map->toApi();

        return $this->ok('Wallet details.', [
            'balance' => $balance,                                       // ANB
            'balance_inr' => $rate ? $rate->anbToInr($balance) : null,   // ₹
            'balance_usdt' => $usdtbalance , // USDT
            'withdrawable' => $withdrawable,  
            'currency' => 'ANB',
            'rate' => $rate?->toApi(),
            'transactions' => $txns,
        ]);
    }

    /** POST /api/deposit   { "amount": 100 } */
    public function createDeposit(Request $request, DepositService $deposits): JsonResponse
    {
        $anb = config('ultrapay.anb');
        if (! $anb['company_address'] || ! $anb['token_contract']) {
            return $this->fail('Deposits are currently unavailable. Please try again later.', 503);
        }

        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/', 'numeric', 'min:' . $anb['min_deposit']],
        ], [
            'amount.regex' => 'Please enter a valid amount with no more than 2 decimal places.',
            'amount.min' => 'The minimum deposit amount is ' . $anb['min_deposit'] . ' ANB.',
        ]);

        try {
            $deposit = $deposits->createRequest($request->user(), (string) $data['amount']);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 503);
        }

        $info = $deposit->toApi();

        return $this->ok(
            "Send exactly {$info['amount_to_send']} ANB to the company address. Sending even slightly less or more will prevent automatic crediting.",
            ['deposit' => $info],
            201
        );
    }

        /**
     * GET /api/deposit/{id}
     * App ise har 5-10 second mein call kare. Payment pending ho to server
     * usi waqt blockchain check karta hai, taaki balance turant add ho.
     */
    public function showDeposit(Request $request, int $id, DepositScanner $scanner): JsonResponse
    {
        $deposit = DepositRequest::where('user_id', $request->user()->id)->find($id);
        if (! $deposit) {
            return $this->fail('Deposit request not found.', 404);
        }

        // Turant check: latest N block scan (poore server par 5 second mein max ek baar)
        if ($deposit->status === 'pending' && Cache::add('anb:quick_scan', 1, 5)) {
            try {
                $scanner->scanRecent((int) config('ultrapay.anb.recent_scan_blocks', 1000));
            } catch (Throwable $e) {
                report($e);   // RPC fail ho to bhi app ko jawab do, scheduler baad mein pakad lega
            }
            $deposit->refresh();
        }

        $message = match ($deposit->displayStatus()) {
            'completed' => 'Payment received. ' . rtrim(rtrim((string) $deposit->amount_received, '0'), '.') . ' ANB has been added to your balance.',
            'timeout' => 'The timer has expired. If you have sent the payment, it will be credited automatically within 24 hours.',
            'expired' => 'This deposit request has expired.',
            default => 'Waiting for payment. Once sent, it will be added to your balance within a few seconds.',
        };

        $data = ['deposit' => $deposit->toApi()];
        if ($deposit->status === 'completed') {
            $data['balance'] = $request->user()->fresh()->balance;
        }

        return $this->ok($message, $data);
    }

    /** GET /api/deposits  -> user ki saari deposit requests */
    public function deposits(Request $request): JsonResponse
    {
        $list = DepositRequest::where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map->toApi();

        return $this->ok('Deposit history.', ['deposits' => $list]);
    }
}