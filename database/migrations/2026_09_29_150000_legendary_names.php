<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Legendary item titles, combined from weapon or armor and the strongest stat (DESIGN.md §6.4). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legendary_names')) {
            DB::unprepared(<<<'SQL'
CREATE TABLE legendary_names (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40) NOT NULL,
  kind         ENUM('unique','base','epithet') NOT NULL,
  name         VARCHAR(48) NOT NULL,
  item_type    VARCHAR(20) NULL,
  slot         ENUM('weapon','offhand','head','chest','hands','feet','ring','amulet') NULL,
  stat_key     VARCHAR(20) NULL,
  placement    ENUM('prefix','suffix') NULL,
  min_ilvl     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  weight       SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  UNIQUE KEY uq_legendary_names_code (code),
  CONSTRAINT chk_legendary_names_shape CHECK (
        (kind = 'unique' AND item_type IS NOT NULL AND slot IS NOT NULL AND stat_key IS NULL AND placement IS NULL)
     OR (kind = 'base' AND item_type IS NOT NULL AND slot IS NOT NULL AND stat_key IS NULL AND placement IS NULL)
     OR (kind = 'epithet' AND item_type IS NULL AND slot IS NULL AND stat_key IS NOT NULL AND placement IS NOT NULL)
  )
) ENGINE=InnoDB;
SQL);
        }

        DB::unprepared(file_get_contents(database_path('seeders/sql/040_legendary_names.sql')));
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS legendary_names');
    }
};
