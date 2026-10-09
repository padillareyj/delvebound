<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Auto mode can pick the hardest place the character can clear (DESIGN.md §8.7). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE characters ADD COLUMN auto_place ENUM('profile', 'best') NOT NULL DEFAULT 'profile' AFTER auto_profile_id");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE characters DROP COLUMN auto_place');
    }
};
