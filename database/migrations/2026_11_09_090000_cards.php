<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** RO-style random card slots and monster cards (DESIGN.md §6.10). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('item_instances', 'card_slots')) {
            DB::statement('ALTER TABLE item_instances
                ADD COLUMN card_slots TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER sell_value');
        }

        DB::statement("CREATE TABLE IF NOT EXISTS card_instances (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            character_id INT UNSIGNED NULL,
            status ENUM('owned','socketed','sold','destroyed','lost') NOT NULL DEFAULT 'owned',
            rarity ENUM('common','uncommon','rare','epic','legendary') NOT NULL DEFAULT 'common',
            display_name VARCHAR(96) NOT NULL,
            monster_code VARCHAR(40) NULL,
            item_level SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            bonuses JSON NOT NULL,
            sell_value INT UNSIGNED NOT NULL DEFAULT 1,
            source ENUM('drop','boss','admin') NOT NULL DEFAULT 'drop',
            created_run_id INT UNSIGNED NULL,
            created_trip_id INT UNSIGNED NULL,
            acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_cards_owner (character_id, status),
            CONSTRAINT fk_cards_owner FOREIGN KEY (character_id) REFERENCES characters(id),
            CONSTRAINT fk_cards_run FOREIGN KEY (created_run_id) REFERENCES simulation_runs(id),
            CONSTRAINT fk_cards_trip FOREIGN KEY (created_trip_id) REFERENCES trips(id)
        ) ENGINE=InnoDB");

        DB::statement('CREATE TABLE IF NOT EXISTS item_instance_cards (
            item_instance_id INT UNSIGNED NOT NULL,
            slot_index TINYINT UNSIGNED NOT NULL,
            card_instance_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (item_instance_id, slot_index),
            UNIQUE KEY uq_iic_card (card_instance_id),
            CONSTRAINT fk_iic_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            CONSTRAINT fk_iic_card FOREIGN KEY (card_instance_id) REFERENCES card_instances(id)
        ) ENGINE=InnoDB');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS item_instance_cards');
        DB::statement('DROP TABLE IF EXISTS card_instances');
        if (Schema::hasColumn('item_instances', 'card_slots')) {
            DB::statement('ALTER TABLE item_instances DROP COLUMN card_slots');
        }
    }
};
