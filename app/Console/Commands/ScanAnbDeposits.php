<?php

namespace App\Console\Commands;

use App\Services\DepositScanner;
use Illuminate\Console\Command;
use Throwable;

/** Har minute chalta hai (backup): app band ho to bhi deposit add ho jaaye */
class ScanAnbDeposits extends Command
{
    protected $signature = 'anb:scan-deposits';

    protected $description = 'Company address par aaye ANB deposits check karke balance mein add karo';

    public function handle(DepositScanner $scanner): int
    {
        $anb = config('ultrapay.anb');
        if (! $anb['token_contract'] || ! $anb['company_address']) {
            $this->error('ANB_TOKEN_CONTRACT aur ANB_COMPANY_ADDRESS set karein.');

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
            $this->warn('Doosra scan pehle se chal raha hai. Skip.');

            return self::SUCCESS;
        }

        foreach ($result['lines'] as $line) {
            $this->line($line);
        }
        $this->info("Block {$result['last_block']} tak scan ho gaya. {$result['credited']} deposit credit hue.");

        return self::SUCCESS;
    }
}