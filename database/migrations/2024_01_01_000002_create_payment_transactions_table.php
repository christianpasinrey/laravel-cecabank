<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_transactions')) {
            return;
        }

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('payable');
            $table->foreignId('payment_gateway_id')->constrained()->cascadeOnDelete();
            $table->string('operation_number', 50)->unique();
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending');
            $table->string('environment', 16)->default('production');
            $table->boolean('is_sandbox')->default(false);
            $table->string('authorization_number')->nullable();
            $table->string('reference')->nullable();
            $table->string('signature_sent')->nullable();
            $table->string('signature_response')->nullable();
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('payment_gateway_id');
            $table->index('environment');
            $table->index(['is_sandbox', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
