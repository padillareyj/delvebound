<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Sigil Circle material (DESIGN.md §17.3). */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
INSERT INTO material_templates (code, name, kind, glyph, description, sort_order)
SELECT 'sigil_ink', 'Sigil Ink', 'system', '🖌',
       'Ink for the Sigil Circle. Daily kills, clears, arena, and activity chests drop it.', 330
WHERE NOT EXISTS (SELECT 1 FROM material_templates WHERE code = 'sigil_ink')
SQL);
    }

    public function down(): void
    {
        DB::table('material_templates')->where('code', 'sigil_ink')->delete();
    }
};
