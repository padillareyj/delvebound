<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The base schema (DESIGN.md §2.3) lives in database/schema/001_schema.sql.
 * Databases created before the Laravel move already have these tables; for them this migration only
 * records itself as applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('characters')) {
            return;
        }
        DB::unprepared(file_get_contents(database_path('schema/001_schema.sql')));
        DB::table('schema_migrations')->insertOrIgnore(['version' => '001_schema.sql']);
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['v_leaderboard', 'v_inventory'] as $view) {
            DB::statement("DROP VIEW IF EXISTS `{$view}`");
        }
        foreach ([
            'shop_transactions', 'shop_listings', 'character_consumables', 'consumable_templates',
            'bot_decisions', 'mail', 'trip_loot', 'character_equipment', 'item_instance_affixes',
            'item_instances', 'trip_encounters', 'trips', 'trip_orders', 'simulation_runs', 'characters',
            'place_monsters', 'places', 'monsters', 'affixes', 'loot_table_entries', 'item_templates',
            'loot_tables', 'accounts', 'bot_profiles', 'schema_migrations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
};
