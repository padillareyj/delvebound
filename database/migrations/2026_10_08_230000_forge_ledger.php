<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The forge spends gold, so the shop ledger can record a craft (DESIGN.md §6.9). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE shop_transactions
            MODIFY COLUMN action ENUM('buy','sell','use','premium','craft') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM shop_transactions WHERE action = 'craft'");
        DB::statement("ALTER TABLE shop_transactions
            MODIFY COLUMN action ENUM('buy','sell','use','premium') NOT NULL");
    }
};
