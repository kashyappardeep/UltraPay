<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recharges', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('number', 10);
            $table->string('circle_code', 50);
            $table->string('operator_code', 50);
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('processing');
            $table->string('provider_status', 100)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->string('provider_message', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recharges');
    }
};
