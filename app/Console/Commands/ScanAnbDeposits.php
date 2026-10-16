<?php

namespace App\Console\Commands;

use App\Services\DepositScanner;
use Illuminate\Console\Command;
use Throwable;

/** Har minute chalta hai (backup): app band ho to bhi deposit add ho jaaye */
class ScanAnbDeposits extends Command
{
    protected $signature = 'anb:scan-deposits';

    protected $description = 'Check ANB deposits received at the company address and add them to balances';

    public function handle(DepositScanner $scanner): int
    {
        $anb = config('ultrapay.anb');
        if (! $anb['token_contract'] || ! $anb['company_address']) {
            $this->error('Set ANB_TOKEN_CONTRACT and ANB_COMPANY_ADDRESS.');

            return self::FAILURE;
        }

        try {
             $result = $scanner->scan(200);
        } catch (Throwable $e) {
            $this->error('Scan fail: ' . $e->getMessage());
            report($e);

            return self::FAILURE;
        }

        if ($result === null) {
            $this->warn('Another scan is already running. Skipping.');

            return self::SUCCESS;
        }

        foreach ($result['lines'] as $line) {
            $this->line($line);
        }
        $this->info("Scanned up to block {$result['last_block']}. {$result['credited']} deposit(s) credited.");

        return self::SUCCESS;
    }
}