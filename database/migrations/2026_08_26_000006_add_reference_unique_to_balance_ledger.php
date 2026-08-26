<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A credit or debit is fully identified by what it settles. Checking for an
     * existing row before inserting one is a race; only the database can make
     * "at most one entry per reference" true under concurrency.
     */
    public function up(): void
    {
        Schema::table('balance_ledger', function (Blueprint $table) {
            $table->unique(
                ['reference_type', 'reference_id', 'direction'],
                'balance_ledger_reference_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('balance_ledger', function (Blueprint $table) {
            $table->dropUnique('balance_ledger_reference_unique');
        });
    }
};
