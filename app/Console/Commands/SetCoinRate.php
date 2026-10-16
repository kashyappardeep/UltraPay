<?php

namespace App\Console\Commands;

use App\Models\CoinRate;
use Illuminate\Console\Command;

/**
 *   php artisan rate:set 0.05 88.50     (1 ANB = 0.05 USDT, 1 USDT = 88.50 INR)
 *   php artisan rate:set 0.06           (sirf ANB/USDT badlo, USDT/INR purana rahega)
 */
class SetCoinRate extends Command
{
    protected $signature = 'rate:set {usdt_rate : 1 ANB = kitne USDT} {usdt_inr? : 1 USDT = kitne INR} {--note=}';

    protected $description = 'ANB coin ka USDT aur INR rate set karo';

    public function handle(): int
    {
        $usdtRate = (string) $this->argument('usdt_rate');
        $usdtInr = $this->argument('usdt_inr');

        if ($usdtInr === null) {
            $last = CoinRate::current();
            if (! $last) {
                $this->error('Pehli baar dono rate dein. Jaise: php artisan rate:set 0.05 88.50');

                return self::FAILURE;
            }
            $usdtInr = (string) $last->usdt_inr;
        }

        if (! is_numeric($usdtRate) || (float) $usdtRate <= 0 || ! is_numeric($usdtInr) || (float) $usdtInr <= 0) {
            $this->error('Rate 0 se bada number hona chahiye.');

            return self::FAILURE;
        }

        $rate = CoinRate::create([
            'symbol' => 'ANB',
            'usdt_rate' => $usdtRate,
            'usdt_inr' => $usdtInr,
            'inr_rate' => CoinRate::multiply($usdtRate, (string) $usdtInr),
            'source' => 'manual',
            'note' => $this->option('note'),
        ]);

        $this->info('Naya rate set ho gaya:');
        $this->line("  1 ANB  = {$rate->usdt_rate} USDT");
        $this->line("  1 USDT = {$rate->usdt_inr} INR");
        $this->line("  1 ANB  = {$rate->inr_rate} INR");
        $this->line('  ₹199 recharge = ' . $rate->inrToAnb('199') . ' ANB');

        return self::SUCCESS;
    }
}