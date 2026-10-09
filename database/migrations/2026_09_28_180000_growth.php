<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Stackable materials, skill ranks, and the horcrux (DESIGN.md §6.7–§6.8). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('material_templates')) {
            DB::unprepared(<<<'SQL'
CREATE TABLE material_templates (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40)  NOT NULL,
  name         VARCHAR(64)  NOT NULL,
  kind         ENUM('dust','shard','essence') NOT NULL,
  glyph        VARCHAR(8)   NOT NULL DEFAULT '✦',
  description  VARCHAR(255) NULL,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_material_templates_code (code)
) ENGINE=InnoDB;

CREATE TABLE place_materials (
  place_id     SMALLINT UNSIGNED NOT NULL,
  material_id  SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (place_id, material_id),
  CONSTRAINT fk_pmats_place FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE CASCADE,
  CONSTRAINT fk_pmats_mat   FOREIGN KEY (material_id) REFERENCES material_templates(id)
) ENGINE=InnoDB;

CREATE TABLE character_materials (
  character_id INT UNSIGNED NOT NULL,
  material_id  SMALLINT UNSIGNED NOT NULL,
  qty          INT UNSIGNED NOT NULL,
  PRIMARY KEY (character_id, material_id),
  CONSTRAINT fk_cmats_char FOREIGN KEY (character_id) REFERENCES characters(id),
  CONSTRAINT fk_cmats_mat  FOREIGN KEY (material_id) REFERENCES material_templates(id),
  CONSTRAINT chk_cmats_qty CHECK (qty > 0)
) ENGINE=InnoDB;

CREATE TABLE character_skills (
  character_id INT UNSIGNED NOT NULL,
  skill_code   VARCHAR(32) NOT NULL,
  skill_rank   TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (character_id, skill_code),
  CONSTRAINT fk_cskills_char FOREIGN KEY (character_id) REFERENCES characters(id),
  CONSTRAINT chk_cskills_rank CHECK (skill_rank >= 1)
) ENGINE=InnoDB;

CREATE TABLE character_horcrux (
  character_id INT UNSIGNED NOT NULL PRIMARY KEY,
  tier         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  progress     INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_chorcrux_char FOREIGN KEY (character_id) REFERENCES characters(id)
) ENGINE=InnoDB;

CREATE TABLE trip_materials (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  trip_id      INT UNSIGNED NOT NULL,
  material_id  SMALLINT UNSIGNED NOT NULL,
  qty          SMALLINT UNSIGNED NOT NULL,
  kept         BOOLEAN NOT NULL,
  KEY ix_trip_materials_trip (trip_id),
  CONSTRAINT fk_tmats_trip FOREIGN KEY (trip_id) REFERENCES trips(id),
  CONSTRAINT fk_tmats_mat  FOREIGN KEY (material_id) REFERENCES material_templates(id)
) ENGINE=InnoDB;
SQL);
        }

        DB::unprepared(file_get_contents(database_path('seeders/sql/030_growth.sql')));
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS trip_materials');
        DB::statement('DROP TABLE IF EXISTS character_horcrux');
        DB::statement('DROP TABLE IF EXISTS character_skills');
        DB::statement('DROP TABLE IF EXISTS character_materials');
        DB::statement('DROP TABLE IF EXISTS place_materials');
        DB::statement('DROP TABLE IF EXISTS material_templates');
    }
};
