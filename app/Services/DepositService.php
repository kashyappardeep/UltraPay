<?php

namespace App\Services;

use App\Models\ChainDeposit;
use App\Models\DepositRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Illuminate\Database\UniqueConstraintViolationException;

class DepositService
{
    /** User ke liye unique amount banao. Jaise 100 -> 100.0347 */
    public function createRequest(User $user, string $amount): DepositRequest
    {
        // Amount ko 0.01 ki units mein, taaki float ki galti na ho
        [$int, $frac] = array_pad(explode('.', $amount, 2), 2, '');
        $baseCents = (int) $int * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');

        $window = now()->subHours(config('ultrapay.anb.match_window_hours'));

        for ($try = 0; $try < 50; $try++) {
            $suffix = random_int(1, 99);                       // 0.0001 se 0.0099
            $expected = self::formatUnits($baseCents * 100 + $suffix);

            $taken = DepositRequest::where('status', 'pending')
                ->where('created_at', '>=', $window)
                ->where('amount_expected', $expected)
                ->exists();

            if (! $taken) {
                // Isi user ki purani pending request band karo
                DepositRequest::where('user_id', $user->id)->where('status', 'pending')
                    ->update(['status' => 'expired']);

                return DepositRequest::create([
                    'user_id' => $user->id,
                    'amount_expected' => $expected,
                    'status' => 'pending',
                    'expires_at' => now()->addMinutes(config('ultrapay.anb.deposit_expiry_minutes')),
                ]);
            }
        }

        throw new RuntimeException('Could not create the deposit request. Please try again later.');
    }

    /** Blockchain se aaya ek transfer. Match hua to balance badhao. Do baar credit kabhi nahi. */
    public function handleTransfer(array $t): string
    {
        return DB::transaction(function () use ($t) {
            $exists = ChainDeposit::where('tx_hash', $t['tx_hash'])
                ->where('log_index', $t['log_index'])
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                return 'duplicate';
            }

            $request = DepositRequest::where('status', 'pending')
                ->where('amount_expected', $t['amount'])
                ->where('created_at', '>=', now()->subHours(config('ultrapay.anb.match_window_hours')))
                ->oldest('id')
                ->lockForUpdate()
                ->first();

            try {
                $deposit = ChainDeposit::create([
                    'tx_hash' => $t['tx_hash'],
                    'log_index' => $t['log_index'],
                    'from_address' => $t['from_address'],
                    'amount' => $t['amount'],
                    'block_number' => $t['block_number'],
                    'deposit_request_id' => $request?->id,
                    'status' => $request ? 'credited' : 'unmatched',
                ]);
            } catch (UniqueConstraintViolationException) {
                return 'duplicate'; // doosra scan isi waqt isi transfer ko process kar chuka
            }

            if (! $request) {
                Log::warning('ANB deposit match nahi hua', $t);

                return 'unmatched';
            }

            // Balance badhao (database khud decimal jod deta hai, koi rounding galti nahi)
            $user = User::whereKey($request->user_id)->lockForUpdate()->first();
            User::whereKey($user->id)->increment('balance', $t['amount']);
            $balanceAfter = User::whereKey($user->id)->value('balance');

            WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'deposit',
                'amount' => $t['amount'],
                'balance_after' => $balanceAfter,
                'reference' => $t['tx_hash'],
                'description' => 'ANB deposit',
            ]);

            $request->update([
                'status' => 'completed',
                'amount_received' => $t['amount'],
                'tx_hash' => $t['tx_hash'],
                'completed_at' => now(),
            ]);

            Log::info("ANB deposit credited: user {$user->id}, {$t['amount']} ANB, deposit #{$deposit->id}");

            return 'credited';
        });
    }

    /** 1000347 (0.0001 units) -> "100.03470000" */
    private static function formatUnits(int $units): string
    {
        return intdiv($units, 10000) . '.' . str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT) . '0000';
    }
}