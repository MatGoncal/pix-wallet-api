<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('status', 32);
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('external_id')->nullable();
            $table->string('description', 140)->nullable();
            $table->text('qr_code');
            $table->text('copy_paste');
            $table->string('provider', 64);
            $table->string('provider_tx_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'external_id']);
            $table->index(['partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
