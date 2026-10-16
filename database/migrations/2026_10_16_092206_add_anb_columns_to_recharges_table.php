<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recharges', function (Blueprint $table) {
            $table->decimal('anb_amount', 36, 8)->nullable();   // kitne ANB kate
            $table->decimal('anb_rate', 30, 10)->nullable();    // us waqt 1 ANB = kitne ₹
            $table->timestamp('refunded_at')->nullable();       // fail hone par kab wapas hua
        });
    }

    public function down(): void
    {
        Schema::table('recharges', function (Blueprint $table) {
            $table->dropColumn(['anb_amount', 'anb_rate', 'refunded_at']);
        });
    }
};