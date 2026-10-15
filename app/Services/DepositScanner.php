<?php

namespace App\Services;

use App\Models\DepositRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Blockchain scan.
 *  scanRecent() -> sirf latest N block (app ke turant check ke liye, tez)
 *  scan()       -> jahan ruka tha wahan se aage (scheduler, koi block miss nahi hota)
 * Ek hi transfer dono ko mile to bhi sirf ek baar credit hota hai.
 */
class DepositScanner
{
    private const CACHE_KEY = 'anb:last_scanned_block';

    public function __construct(
        private AnbChain $chain,
        private DepositService $deposits,
    ) {}

    /** Sirf latest $blocks block check karo. Cursor nahi badalta. */
    public function scanRecent(int $blocks = 100): ?array
    {
        $lock = Cache::lock('anb:recent-scan-lock', 30);
        if (! $lock->get()) {
            return null;
        }

        try {
            $safeBlock = $this->chain->latestBlock() - config('ultrapay.anb.confirmations');
            $from = $safeBlock - $blocks + 1;

            $result = $this->scanRange($from, $safeBlock);

            return ['from' => $from, 'to' => $safeBlock] + $result;
        } finally {
            $lock->release();
        }
    }

    /** Jahan pichli baar ruka tha wahan se aage scan (backup, kuch miss nahi hota) */
    public function scan(int $maxChunks = 200): ?array
    {
        $lock = Cache::lock('anb:scan-lock', 300);
        if (! $lock->get()) {
            return null;
        }

        try {
            $anb = config('ultrapay.anb');

            // 24 ghante purani pending requests band karo
            DepositRequest::where('status', 'pending')
                ->where('created_at', '<', now()->subHours($anb['match_window_hours']))
                ->update(['status' => 'expired']);

            $safeBlock = $this->chain->latestBlock() - $anb['confirmations'];

            $last = Cache::get(self::CACHE_KEY);
            if ($last === null) {
                $last = $anb['start_block'] ? ((int) $anb['start_block'] - 1) : ($safeBlock - 200);
            }

            $credited = 0;
            $lines = [];
            $chunks = 0;
            while ($last < $safeBlock && $chunks < $maxChunks) {
                $from = $last + 1;
                $to = min($from + $anb['block_chunk'] - 1, $safeBlock);

                $r = $this->scanRange($from, $to);
                $credited += $r['credited'];
                $lines = array_merge($lines, $r['lines']);

                Cache::forever(self::CACHE_KEY, $to);
                $last = $to;
                $chunks++;
            }

            return ['last_block' => $last, 'credited' => $credited, 'lines' => $lines];
        } finally {
            $lock->release();
        }
    }

    /** Block $from se $to tak (chunk mein) transfers padho aur credit karo */
    private function scanRange(int $from, int $to): array
    {
        $chunk = max(1, (int) config('ultrapay.anb.block_chunk'));
        $credited = 0;
        $lines = [];

        for ($start = $from; $start <= $to; $start += $chunk) {
            $end = min($start + $chunk - 1, $to);

            foreach ($this->chain->transfersToCompany($start, $end) as $transfer) {
                $result = $this->deposits->handleTransfer($transfer);
                $lines[] = "{$transfer['tx_hash']}  {$transfer['amount']} ANB  -> {$result}";
                if ($result === 'credited') {
                    $credited++;
                }
            }
        }

        return ['credited' => $credited, 'lines' => $lines];
    }
}