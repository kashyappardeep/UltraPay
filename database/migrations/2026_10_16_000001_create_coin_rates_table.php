<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ANB coin ka rate. Har badlaav ek nayi row: sabse nayi row = current rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_rates', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10)->default('ANB')->index();
            $table->decimal('usdt_rate', 30, 10);       // 1 ANB = kitne USDT
            $table->decimal('usdt_inr', 20, 4);         // 1 USDT = kitne INR
            $table->decimal('inr_rate', 30, 10);        // 1 ANB = kitne INR (usdt_rate x usdt_inr)
            $table->string('source', 30)->default('manual'); // manual / api
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_rates');
    }
};