<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Daily quests + MU2-style upgrade tracks (DESIGN.md §17). State lives on characters. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('characters', 'daily')) {
            DB::statement('ALTER TABLE characters
                ADD COLUMN daily JSON NULL AFTER premium,
                ADD COLUMN systems JSON NULL AFTER daily');
        }

        DB::statement("ALTER TABLE material_templates MODIFY kind ENUM('dust','shard','essence','system') NOT NULL");

        DB::unprepared(<<<'SQL'
INSERT INTO material_templates (code, name, kind, glyph, description, sort_order)
SELECT v.code, v.name, v.kind, v.glyph, v.description, v.sort_order
FROM (
    SELECT 'jewel_dust' AS code, 'Jewel Dust' AS name, 'system' AS kind, '💎' AS glyph,
           'Powder for the five elemental jewels. Daily quests drop it.' AS description, 300 AS sort_order
    UNION ALL SELECT 'excellent_stone', 'Excellent Stone', 'system', '🔶',
           'A stone that raises Excellency. Daily chests and longer quests drop it.', 310
    UNION ALL SELECT 'wing_feather', 'Wing Feather', 'system', '🪶',
           'Feathers that add Wing Stars. Clear places and activity chests drop them.', 320
) v
WHERE NOT EXISTS (SELECT 1 FROM material_templates WHERE code = v.code)
SQL);
    }

    public function down(): void
    {
        DB::table('material_templates')->whereIn('code', ['jewel_dust', 'excellent_stone', 'wing_feather'])->delete();
        DB::statement("ALTER TABLE material_templates MODIFY kind ENUM('dust','shard','essence') NOT NULL");
        if (Schema::hasColumn('characters', 'daily')) {
            DB::statement('ALTER TABLE characters DROP COLUMN daily, DROP COLUMN systems');
        }
    }
};
