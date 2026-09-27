<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Humans opt into the arena with a flag the website can already update (DESIGN.md §15.1). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE characters ADD COLUMN arena_entered BOOLEAN NOT NULL DEFAULT FALSE AFTER auto_repeat');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE characters DROP COLUMN arena_entered');
    }
};
