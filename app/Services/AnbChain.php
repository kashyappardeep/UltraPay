<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Blockchain (EVM: BSC / Ethereum / Polygon) se ANB token ke transfer padhna.
 * Sirf RPC se padhta hai, koi private key nahi chahiye.
 */
class AnbChain
{
    /** keccak256("Transfer(address,address,uint256)") */
    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function latestBlock(): int
    {
        return (int) hexdec($this->rpc('eth_blockNumber', []));
    }

    /** Company address par aaye ANB transfers, block $from se $to tak. */
    public function transfersToCompany(int $from, int $to): array
    {
        $anb = config('ultrapay.anb');
        $companyTopic = '0x' . str_pad(substr($anb['company_address'], 2), 64, '0', STR_PAD_LEFT);

        $logs = $this->rpc('eth_getLogs', [[
            'fromBlock' => '0x' . dechex($from),
            'toBlock' => '0x' . dechex($to),
            'address' => $anb['token_contract'],
            'topics' => [self::TRANSFER_TOPIC, null, $companyTopic],
        ]]);

        $result = [];
        foreach ($logs as $log) {
            if (! empty($log['removed'])) {
                continue; // chain reorg mein hata hua log
            }
            $result[] = [
                'tx_hash' => strtolower($log['transactionHash']),
                'log_index' => (int) hexdec($log['logIndex']),
                'block_number' => (int) hexdec($log['blockNumber']),
                'from_address' => '0x' . strtolower(substr($log['topics'][1], -40)),
                'amount' => self::rawToAmount(self::hexToDecimal($log['data']), $anb['decimals']),
            ];
        }

        return $result;
    }

    private function rpc(string $method, array $params): mixed
    {
        $res = Http::timeout(20)->post(config('ultrapay.anb.rpc_url'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);

        if (! $res->successful()) {
            throw new RuntimeException("RPC {$method} HTTP " . $res->status());
        }
        if ($res->json('error')) {
            throw new RuntimeException("RPC {$method} error: " . json_encode($res->json('error')));
        }

        return $res->json('result');
    }

    /** "0x1bc16d674ec80000" -> "2000000000000000000" (bade numbers ke liye string math) */
    public static function hexToDecimal(string $hex): string
    {
        $hex = ltrim(strtolower(preg_replace('/^0x/i', '', $hex)), '0');
        if ($hex === '') {
            return '0';
        }

        $digits = [0]; // decimal digits, ulte kram mein
        foreach (str_split($hex) as $char) {
            $carry = hexdec($char);
            foreach ($digits as $i => $d) {
                $v = $d * 16 + $carry;
                $digits[$i] = $v % 10;
                $carry = intdiv($v, 10);
            }
            while ($carry > 0) {
                $digits[] = $carry % 10;
                $carry = intdiv($carry, 10);
            }
        }

        return implode('', array_reverse($digits));
    }

    /** "2000000000000000000" + 18 decimals -> "2.00000000" (8 decimal tak) */
    public static function rawToAmount(string $raw, int $decimals): string
    {
        $raw = str_pad($raw, $decimals + 1, '0', STR_PAD_LEFT);
        $int = substr($raw, 0, strlen($raw) - $decimals);
        $frac = $decimals > 0 ? substr($raw, -$decimals) : '';
        $frac = substr(str_pad($frac, 8, '0'), 0, 8);

        return ltrim($int, '0') === '' ? '0.' . $frac : ltrim($int, '0') . '.' . $frac;
    }
}