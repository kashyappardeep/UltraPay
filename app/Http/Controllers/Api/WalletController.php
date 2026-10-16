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
        $rate = CoinRate::current(); 
        $usdtbalance =$balance * $rate->usdt_inr;                // coin_rates table se current rate

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
            return $this->fail('Deposit abhi band hai. Thodi der baad try karein.', 503);
        }

        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/', 'numeric', 'min:' . $anb['min_deposit']],
        ], [
            'amount.regex' => 'Amount sahi daalein (zyada se zyada 2 decimal).',
            'amount.min' => 'Kam se kam ' . $anb['min_deposit'] . ' ANB deposit karein.',
        ]);

        try {
            $deposit = $deposits->createRequest($request->user(), (string) $data['amount']);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 503);
        }

        $info = $deposit->toApi();

        return $this->ok(
            "Company address par EXACT {$info['amount_to_send']} ANB bhejein. Thoda bhi kam ya zyada bheja to automatic add nahi hoga.",
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
            return $this->fail('Deposit request nahi mili.', 404);
        }

        // Turant check (poore server par 8 second mein max ek baar, taaki RPC limit na lage)
                // Turant check: sirf latest 100 block (poore server par 5 second mein max ek baar)
        if ($deposit->status === 'pending' && Cache::add('anb:quick_scan', 1, 5)) {
            try {
                $scanner->scanRecent(100);
            } catch (Throwable $e) {
                report($e);   // RPC fail ho to bhi app ko jawab do, scheduler baad mein pakad lega
            }
            $deposit->refresh();
        }

        $message = match ($deposit->displayStatus()) {
            'completed' => 'Payment mil gaya. ' . rtrim(rtrim((string) $deposit->amount_received, '0'), '.') . ' ANB balance mein add ho gaya.',
            'timeout' => 'Timer khatam ho gaya. Agar aapne payment bhej diya hai to wo 24 ghante tak automatic add ho jaayega.',
            'expired' => 'Ye deposit request band ho chuki hai.',
            default => 'Payment ka intezaar hai. Bhejte hi kuch second mein balance mein add ho jaayega.',
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