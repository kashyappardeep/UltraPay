<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PinController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(ForceJsonResponse::class)->group(function () {

    // ---------- Register (bina login) ----------
    Route::post('/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:5,1');
    Route::post('/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

    // ---------- Login ----------
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // ---------- Forgot password ----------
    Route::post('/password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('/password/verify-otp', [PasswordController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('/password/reset', [PasswordController::class, 'reset'])->middleware('throttle:5,1');

    // ---------- Login ke baad (Bearer token) ----------
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/pin/verify', [PinController::class, 'verify'])->middleware('throttle:10,1');
        Route::post('/pin/change', [PinController::class, 'change'])->middleware('throttle:5,1');

        // Wallet + Add fund
        Route::get('/wallet', [WalletController::class, 'wallet']);
        Route::post('/deposit', [WalletController::class, 'createDeposit'])->middleware('throttle:10,1');
        Route::get('/deposit/{id}', [WalletController::class, 'showDeposit'])->whereNumber('id');
        Route::get('/deposits', [WalletController::class, 'deposits']);
    });   // <- auth:sanctum group yahan band

});   // <- ForceJson group yahan band