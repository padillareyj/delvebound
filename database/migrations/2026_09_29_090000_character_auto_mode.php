<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Humans can follow a bot personality at the start of a run (DESIGN.md §8.2). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE characters ADD COLUMN auto_mode BOOLEAN NOT NULL DEFAULT FALSE AFTER auto_repeat');
        DB::statement('ALTER TABLE characters ADD COLUMN auto_profile_id SMALLINT UNSIGNED NULL AFTER auto_mode');
        DB::statement('ALTER TABLE characters ADD CONSTRAINT fk_char_auto_profile FOREIGN KEY (auto_profile_id) REFERENCES bot_profiles (id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE characters DROP FOREIGN KEY fk_char_auto_profile');
        DB::statement('ALTER TABLE characters DROP COLUMN auto_profile_id');
        DB::statement('ALTER TABLE characters DROP COLUMN auto_mode');
    }
};
