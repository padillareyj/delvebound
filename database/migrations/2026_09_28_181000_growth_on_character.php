<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Keep stacks, skill ranks, and the horcrux on characters so the website user can update them. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('characters', 'materials')) {
            DB::statement('ALTER TABLE characters
                ADD COLUMN materials JSON NULL AFTER gold,
                ADD COLUMN skills JSON NULL AFTER materials,
                ADD COLUMN horcrux_tier TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER skills,
                ADD COLUMN horcrux_progress INT UNSIGNED NOT NULL DEFAULT 0 AFTER horcrux_tier');
        }
        DB::statement('DROP TABLE IF EXISTS character_materials');
        DB::statement('DROP TABLE IF EXISTS character_skills');
        DB::statement('DROP TABLE IF EXISTS character_horcrux');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE characters
            DROP COLUMN horcrux_progress,
            DROP COLUMN horcrux_tier,
            DROP COLUMN skills,
            DROP COLUMN materials');
    }
};
