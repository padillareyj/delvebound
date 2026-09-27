<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Sealed clear-chests. Contents are rolled when a player or bot opens the box (DESIGN.md §6.1). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('loot_boxes')) {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE TABLE loot_boxes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  character_id  INT UNSIGNED NOT NULL,
  trip_id       INT UNSIGNED NOT NULL,
  place_id      SMALLINT UNSIGNED NOT NULL,
  seed          INT UNSIGNED NOT NULL,
  luck_factor   DECIMAL(6,3) NOT NULL,
  source_level  SMALLINT UNSIGNED NOT NULL,
  rolls         TINYINT UNSIGNED NOT NULL,
  status        ENUM('sealed','opened') NOT NULL DEFAULT 'sealed',
  opened_at     DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_loot_boxes_trip (trip_id),
  KEY ix_loot_boxes_owner (character_id, status),
  CONSTRAINT fk_lb_char  FOREIGN KEY (character_id) REFERENCES characters(id),
  CONSTRAINT fk_lb_trip  FOREIGN KEY (trip_id)      REFERENCES trips(id),
  CONSTRAINT fk_lb_place FOREIGN KEY (place_id)     REFERENCES places(id)
) ENGINE=InnoDB;
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS loot_boxes');
    }
};
