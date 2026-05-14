<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_gateways')) {
            return;
        }

        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider')->default('cecabank');
            $table->string('merchant_id', 9);
            $table->string('acquirer_bin', 10);
            $table->string('terminal_id', 8);
            $table->text('encryption_key_test')->nullable();
            $table->text('encryption_key_prod')->nullable();
            $table->string('environment')->default('test');
            $table->string('currency', 3)->default('978');
            $table->string('language', 1)->default('1');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
