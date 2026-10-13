<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UltraPay users table.
 * Ye Laravel ki default users migration ki jagah lega (same file name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id(); // isi id se hd_index banega
            $table->string('name');
            $table->string('mobile', 15)->unique();
            $table->string('email')->unique();
            $table->string('password');

            // Payment PIN (hash)
            $table->string('pin');
            $table->unsignedTinyInteger('pin_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();

            // Wallet
            $table->string('wallet_id', 20)->unique();          // jaise ultra7788
            $table->unsignedBigInteger('hd_index')->nullable()->unique();
            $table->string('anb_address', 64)->nullable()->unique();

            // Referral
            $table->string('referral_code', 20)->unique();      // is user ka apna code
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('kyc_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->enum('status', ['active', 'blocked'])->default('active');
            $table->string('device_id')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
