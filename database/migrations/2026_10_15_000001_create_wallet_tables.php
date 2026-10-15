<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // User ka ANB balance
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('balance', 36, 8)->default(0)->after('anb_address');
        });

        // User ne "Add Fund" dabaya: kitna bhejna hai (unique amount)
        Schema::create('deposit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount_expected', 36, 8)->index();
            $table->decimal('amount_received', 36, 8)->nullable();
            $table->enum('status', ['pending', 'completed', 'expired'])->default('pending')->index();
            $table->string('tx_hash', 100)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Blockchain par company address par aaya har transfer
        Schema::create('chain_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('tx_hash', 100);
            $table->unsignedInteger('log_index');
            $table->string('from_address', 64);
            $table->decimal('amount', 36, 8);
            $table->unsignedBigInteger('block_number');
            $table->foreignId('deposit_request_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['credited', 'unmatched'])->index();
            $table->timestamps();

            $table->unique(['tx_hash', 'log_index']);   // ek transfer do baar credit na ho
        });

        // Balance ka poora hisaab (history screen ke liye)
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                 // deposit, recharge, send, receive, bill
            $table->decimal('amount', 36, 8);           // + credit, - debit
            $table->decimal('balance_after', 36, 8);
            $table->string('reference', 120)->nullable(); // tx hash ya order id
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('chain_deposits');
        Schema::dropIfExists('deposit_requests');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance');
        });
    }
};