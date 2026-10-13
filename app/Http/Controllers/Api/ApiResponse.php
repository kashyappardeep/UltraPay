<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

/** Har API ka jawab ek hi format mein: success, message, data */
trait ApiResponse
{
    protected function ok(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    protected function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}