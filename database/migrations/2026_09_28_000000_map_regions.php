<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Regions (maps) plus The Salt Marches and The Ashen Crown (DESIGN.md §2, §7.1). */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS regions (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(32) NOT NULL,
  name        VARCHAR(64) NOT NULL,
  blurb       VARCHAR(255) NULL,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_regions_code (code)
) ENGINE=InnoDB;

ALTER TABLE places
  MODIFY place_type ENUM('rest','forest','mine','dungeon','mountain','cave','marsh','coast','ruins','temple') NOT NULL;
SQL);

        if (! Schema::hasColumn('places', 'region_id')) {
            DB::statement('ALTER TABLE places ADD COLUMN region_id SMALLINT UNSIGNED NULL AFTER id');
        }

        $hasTown = DB::table('places')->where('code', 'town')->exists();
        $hasBrine = DB::table('places')->where('code', 'brinefen')->exists();
        if ($hasTown && ! $hasBrine) {
            DB::unprepared(file_get_contents(database_path('seeders/sql/012_later_maps.sql')));
        }

        $fk = DB::selectOne(
            "SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'places' AND CONSTRAINT_NAME = 'fk_places_region'"
        );
        if ($fk === null) {
            DB::statement('ALTER TABLE places MODIFY region_id SMALLINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE places ADD CONSTRAINT fk_places_region FOREIGN KEY (region_id) REFERENCES regions(id)');
        }
    }

    public function down(): void
    {
        $codes = [
            'brinefen', 'wreckers_cove', 'tidevault', 'saltglass_spire', 'sunken_court',
            'cinder_wastes', 'fallen_citadel', 'ash_monastery', 'black_stair', 'crown_of_embers',
        ];
        $placeIds = DB::table('places')->whereIn('code', $codes)->pluck('id');
        if ($placeIds->isNotEmpty()) {
            DB::table('place_monsters')->whereIn('place_id', $placeIds)->delete();
            DB::table('places')->whereIn('id', $placeIds)->update([
                'boss_monster_id' => null,
                'chest_loot_table_id' => null,
            ]);
            DB::table('places')->whereIn('id', $placeIds)->delete();
        }

        $monsterCodes = [
            'marsh_leech', 'fen_lurker', 'brine_hag', 'salt_wretch', 'tide_reaver', 'drowned_captain',
            'vault_eel', 'tide_priest', 'tide_warden', 'glass_shardling', 'spire_sentinel', 'glass_siren',
            'court_guard', 'brine_oracle', 'queen_of_brine', 'ash_hound', 'cinder_cultist', 'cinder_matron',
            'ash_knight', 'citadel_wraith', 'castellan_of_ash', 'cinder_monk', 'ash_cantor', 'abbot_of_cinders',
            'stair_gargoyle', 'ember_acolyte', 'stair_warden', 'sovereign_guard', 'crown_phoenix', 'ashen_sovereign',
        ];
        DB::table('monsters')->whereIn('code', $monsterCodes)->delete();

        $tables = [
            'lt_brinefen', 'lt_cove', 'lt_tidevault', 'lt_spire', 'lt_court',
            'lt_wastes', 'lt_citadel', 'lt_monastery', 'lt_stair', 'lt_crown',
        ];
        $tableIds = DB::table('loot_tables')->whereIn('code', $tables)->pluck('id');
        if ($tableIds->isNotEmpty()) {
            DB::table('loot_table_entries')->whereIn('loot_table_id', $tableIds)->delete();
            DB::table('loot_tables')->whereIn('id', $tableIds)->delete();
        }

        $items = [
            'tide_cutlass', 'reef_bow', 'saltglass_wand', 'siren_blade', 'ashen_greataxe', 'crown_staff',
            'brine_hauberk', 'spire_plate', 'sovereign_plate', 'tide_helm', 'ash_helm', 'brine_kite',
            'crown_aegis', 'marsh_boots', 'cinder_gauntlets', 'pearl_ring', 'sovereign_amulet',
        ];
        $itemIds = DB::table('item_templates')->whereIn('code', $items)->pluck('id');
        if ($itemIds->isNotEmpty()) {
            $instanceIds = DB::table('item_instances')->whereIn('item_template_id', $itemIds)->pluck('id');
            if ($instanceIds->isNotEmpty()) {
                DB::table('item_instance_affixes')->whereIn('item_instance_id', $instanceIds)->delete();
                DB::table('character_equipment')->whereIn('item_instance_id', $instanceIds)->delete();
                DB::table('trip_loot')->whereIn('item_instance_id', $instanceIds)->delete();
                DB::table('item_instances')->whereIn('id', $instanceIds)->delete();
            }
            DB::table('loot_table_entries')->whereIn('item_template_id', $itemIds)->delete();
            DB::table('item_templates')->whereIn('id', $itemIds)->delete();
        }

        $fk = DB::selectOne(
            "SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'places' AND CONSTRAINT_NAME = 'fk_places_region'"
        );
        if ($fk !== null) {
            DB::statement('ALTER TABLE places DROP FOREIGN KEY fk_places_region');
        }
        if (Schema::hasColumn('places', 'region_id')) {
            DB::statement('ALTER TABLE places DROP COLUMN region_id');
        }
        Schema::dropIfExists('regions');
        DB::statement("ALTER TABLE places MODIFY place_type ENUM('rest','forest','mine','dungeon','mountain','cave') NOT NULL");
    }
};
