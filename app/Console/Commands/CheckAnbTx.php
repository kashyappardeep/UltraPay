<?php

namespace App\Console\Commands;

use App\Services\AnbChain;
use App\Services\DepositService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Ek transaction hash check karo:
 *   php artisan anb:check-tx 0xabc...            (sirf dekho)
 *   php artisan anb:check-tx 0xabc... --credit   (sahi ho to balance mein add karo)
 */
class CheckAnbTx extends Command
{
    protected $signature = 'anb:check-tx {hash} {--credit}';

    protected $description = 'Ek tx hash blockchain se padh ke batao ki ANB deposit match hota hai ya nahi';

    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function handle(DepositService $deposits): int
    {
        $anb = config('ultrapay.anb');
        $hash = strtolower(trim($this->argument('hash')));

        $res = Http::timeout(20)->post($anb['rpc_url'], [
            'jsonrpc' => '2.0', 'id' => 1,
            'method' => 'eth_getTransactionReceipt',
            'params' => [$hash],
        ]);
        $receipt = $res->json('result');

        if (! $receipt) {
            $this->error('Transaction nahi mila. Hash sahi hai? Network BSC hai? RPC: ' . $anb['rpc_url']);

            return self::FAILURE;
        }

        $this->line('Status       : ' . ($receipt['status'] === '0x1' ? 'SUCCESS' : 'FAILED'));
        $this->line('Block        : ' . hexdec($receipt['blockNumber']));
        $this->line('Config token : ' . $anb['token_contract']);
        $this->line('Config to    : ' . $anb['company_address']);
        $this->newLine();

        $found = false;
        foreach ($receipt['logs'] as $log) {
            if (($log['topics'][0] ?? null) !== self::TRANSFER_TOPIC || count($log['topics']) < 3) {
                continue;
            }

            $token = strtolower($log['address']);
            $to = '0x' . strtolower(substr($log['topics'][2], -40));
            $from = '0x' . strtolower(substr($log['topics'][1], -40));
            $amount = AnbChain::rawToAmount(AnbChain::hexToDecimal($log['data']), $anb['decimals']);

            $tokenOk = $token === $anb['token_contract'];
            $toOk = $to === $anb['company_address'];

            $this->line("Transfer log #" . hexdec($log['logIndex']));
            $this->line("  Token  : {$token}  " . ($tokenOk ? '✔ match' : '✘ ALAG TOKEN'));
            $this->line("  From   : {$from}");
            $this->line("  To     : {$to}  " . ($toOk ? '✔ match' : '✘ company address nahi'));
            $this->line("  Amount : {$amount} (decimals {$anb['decimals']} maan ke)");

            if ($tokenOk && $toOk) {
                $found = true;
                if ($this->option('credit')) {
                    $result = $deposits->handleTransfer([
                        'tx_hash' => $hash,
                        'log_index' => (int) hexdec($log['logIndex']),
                        'block_number' => (int) hexdec($receipt['blockNumber']),
                        'from_address' => $from,
                        'amount' => $amount,
                    ]);
                    $this->info("  Result : {$result}");
                }
            }
            $this->newLine();
        }

        if (! $found) {
            $this->warn('Is tx mein config wale token se company address par koi transfer nahi mila.');
        }

        return self::SUCCESS;
    }
}