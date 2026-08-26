<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('status', 32);
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('destination_type', 32);
            $table->string('destination_value');
            $table->string('external_id')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'external_id']);
            $table->index(['partner_id', 'status']);
        });

        Schema::create('payment_splits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('party', 32);
            $table->bigInteger('amount');
            $table->timestamps();

            $table->unique(['payment_id', 'party']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_splits');
        Schema::dropIfExists('payouts');
    }
};
