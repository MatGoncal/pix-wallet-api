<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 64);
            $table->string('event_id');
            $table->string('type', 64);
            $table->json('payload');
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
