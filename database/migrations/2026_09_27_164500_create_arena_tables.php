<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Arena signups, tournaments, and matches (DESIGN.md §15). */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE arena_tournaments (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  status           ENUM('running','completed','failed','abandoned') NOT NULL DEFAULT 'running',
  seed             BIGINT UNSIGNED NOT NULL,
  started_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  heartbeat_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at      DATETIME NULL,
  host             VARCHAR(64) NULL,
  pid              INT UNSIGNED NULL,
  champion_id      INT UNSIGNED NULL,
  entrant_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  error_text       TEXT NULL,
  running_guard    TINYINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'running', 1, NULL)) STORED,
  UNIQUE KEY uq_arena_one_running (running_guard),
  KEY ix_arena_status (status),
  CONSTRAINT fk_arena_champion FOREIGN KEY (champion_id) REFERENCES characters(id)
) ENGINE=InnoDB;

CREATE TABLE arena_signups (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  character_id            INT UNSIGNED NOT NULL,
  status                  ENUM('waiting','consumed','withdrawn') NOT NULL DEFAULT 'waiting',
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  consumed_tournament_id  INT UNSIGNED NULL,
  waiting_character_id    INT UNSIGNED GENERATED ALWAYS AS (IF(status = 'waiting', character_id, NULL)) STORED,
  UNIQUE KEY uq_arena_one_waiting (waiting_character_id),
  KEY ix_arena_signups_status (status, character_id),
  CONSTRAINT fk_signup_char FOREIGN KEY (character_id) REFERENCES characters(id),
  CONSTRAINT fk_signup_tournament FOREIGN KEY (consumed_tournament_id) REFERENCES arena_tournaments(id)
) ENGINE=InnoDB;

CREATE TABLE arena_matches (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tournament_id    INT UNSIGNED NOT NULL,
  round_no         TINYINT UNSIGNED NOT NULL,
  slot_no          TINYINT UNSIGNED NOT NULL,
  character_a_id   INT UNSIGNED NOT NULL,
  character_b_id   INT UNSIGNED NULL,
  winner_id        INT UNSIGNED NOT NULL,
  rounds           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  a_hp_end         INT UNSIGNED NOT NULL DEFAULT 0,
  b_hp_end         INT UNSIGNED NULL,
  reason           ENUM('knockout','time','bye') NOT NULL,
  log              JSON NULL,
  UNIQUE KEY uq_arena_match_slot (tournament_id, round_no, slot_no),
  CONSTRAINT fk_match_tournament FOREIGN KEY (tournament_id) REFERENCES arena_tournaments(id),
  CONSTRAINT fk_match_a FOREIGN KEY (character_a_id) REFERENCES characters(id),
  CONSTRAINT fk_match_b FOREIGN KEY (character_b_id) REFERENCES characters(id),
  CONSTRAINT fk_match_winner FOREIGN KEY (winner_id) REFERENCES characters(id)
) ENGINE=InnoDB;

ALTER TABLE mail
  MODIFY mail_type ENUM('trip_report','level_up','system','arena_report') NOT NULL DEFAULT 'trip_report',
  ADD COLUMN arena_tournament_id INT UNSIGNED NULL AFTER trip_id,
  ADD UNIQUE KEY uq_mail_arena (arena_tournament_id, character_id),
  ADD CONSTRAINT fk_mail_arena FOREIGN KEY (arena_tournament_id) REFERENCES arena_tournaments(id);
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mail DROP FOREIGN KEY fk_mail_arena');
        DB::statement('ALTER TABLE mail DROP INDEX uq_mail_arena');
        DB::statement('ALTER TABLE mail DROP COLUMN arena_tournament_id');
        DB::statement("ALTER TABLE mail MODIFY mail_type ENUM('trip_report','level_up','system') NOT NULL DEFAULT 'trip_report'");
        Schema::dropIfExists('arena_matches');
        Schema::dropIfExists('arena_signups');
        Schema::dropIfExists('arena_tournaments');
    }
};
