<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->bigInteger('available')->default(0);
            $table->bigInteger('pending')->default(0);
            $table->timestamps();

            $table->unique(['partner_id', 'currency']);
        });

        Schema::create('balance_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->string('direction', 16);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('reference_type', 64);
            $table->uuid('reference_id');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'currency', 'created_at']);
        });

        Schema::create('fx_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('source_currency', 3);
            $table->string('target_currency', 3);
            $table->bigInteger('source_amount');
            $table->bigInteger('target_amount');
            $table->string('rate', 32);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_quotes');
        Schema::dropIfExists('balance_ledger');
        Schema::dropIfExists('partner_balances');
    }
};
