<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoinRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RateController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/rate
     * GET /api/rate?inr=199      -> ₹199 ke kitne ANB
     * GET /api/rate?anb=100      -> 100 ANB kitne ₹ aur USDT
     * GET /api/rate?usdt=10      -> 10 USDT ke kitne ANB
     */
    public function current(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inr' => ['nullable', 'numeric', 'min:0'],
            'anb' => ['nullable', 'numeric', 'min:0'],
            'usdt' => ['nullable', 'numeric', 'min:0'],
        ]);

        $rate = CoinRate::current();
        if (! $rate) {
            return $this->fail('ANB ka rate abhi set nahi hua hai.', 503);
        }

        $result = ['rate' => $rate->toApi()];

        if (isset($data['inr'])) {
            $result['convert'] = ['inr' => (string) $data['inr'], 'anb' => $rate->inrToAnb((string) $data['inr'])];
        } elseif (isset($data['anb'])) {
            $anb = (string) $data['anb'];
            $result['convert'] = ['anb' => $anb, 'inr' => $rate->anbToInr($anb), 'usdt' => $rate->anbToUsdt($anb)];
        } elseif (isset($data['usdt'])) {
            $result['convert'] = ['usdt' => (string) $data['usdt'], 'anb' => $rate->usdtToAnb((string) $data['usdt'])];
        }

        return $this->ok('ANB ka current rate.', $result);
    }

    /** GET /api/rate/history -> pichle 30 rate badlaav */
    public function history(): JsonResponse
    {
        $list = CoinRate::where('symbol', 'ANB')->latest('id')->limit(30)->get()->map->toApi();

        return $this->ok('Rate history.', ['rates' => $list]);
    }
}