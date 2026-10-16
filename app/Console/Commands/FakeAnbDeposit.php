<?php

namespace App\Console\Commands;

use App\Models\DepositRequest;
use App\Services\DepositService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** SIRF TESTING: php artisan anb:fake-deposit 5   (5 = deposit request id) */
class FakeAnbDeposit extends Command
{
    protected $signature = 'anb:fake-deposit {id : deposit request id}';

    protected $description = 'Testing: simulate a payment received for a deposit request (local only)';

    public function handle(DepositService $deposits): int
    {
        if (! app()->isLocal()) {
            $this->error('This command only runs when APP_ENV=local.');

            return self::FAILURE;
        }

        $req = DepositRequest::find($this->argument('id'));
        if (! $req) {
            $this->error('Deposit request not found.');

            return self::FAILURE;
        }

        $result = $deposits->handleTransfer([
            'tx_hash' => '0xfake' . Str::lower(Str::random(58)),
            'log_index' => 0,
            'block_number' => 0,
            'from_address' => '0x000000000000000000000000000000000000dead',
            'amount' => $req->getRawOriginal('amount_expected'),
        ]);

        $this->info("Result: {$result}");

        return self::SUCCESS;
    }
}