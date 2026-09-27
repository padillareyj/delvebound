-- Delvebound schema. Mirrors DESIGN.md §2.3.
-- The database itself is created by db/bootstrap.sql.

CREATE TABLE schema_migrations (
  version      VARCHAR(64)  NOT NULL PRIMARY KEY,
  applied_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE bot_profiles (
  id                     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                   VARCHAR(32)  NOT NULL,
  name                   VARCHAR(64)  NOT NULL,
  archetype              ENUM('tank','rogue','mage','berserker','ranger','gambler') NOT NULL,
  description            VARCHAR(255) NULL,
  w_str                  DECIMAL(4,2) NOT NULL DEFAULT 0,
  w_dex                  DECIMAL(4,2) NOT NULL DEFAULT 0,
  w_int                  DECIMAL(4,2) NOT NULL DEFAULT 0,
  w_vit                  DECIMAL(4,2) NOT NULL DEFAULT 0,
  w_luk                  DECIMAL(4,2) NOT NULL DEFAULT 0,
  preferred_scaling      ENUM('str','dex','int') NOT NULL,
  gear_weights           JSON NOT NULL,
  risk_offset            TINYINT NOT NULL DEFAULT 0,
  retreat_threshold_pct  TINYINT UNSIGNED NOT NULL DEFAULT 30,
  randomness             DECIMAL(3,2) NOT NULL DEFAULT 0.20,
  rarity_preference      DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  memory_window          TINYINT UNSIGNED NOT NULL DEFAULT 8,
  fail_threshold         TINYINT UNSIGNED NOT NULL DEFAULT 2,
  fail_avoid_runs        TINYINT UNSIGNED NOT NULL DEFAULT 3,
  stepup_after_wins      TINYINT UNSIGNED NOT NULL DEFAULT 2,
  stepup_hp_pct          TINYINT UNSIGNED NOT NULL DEFAULT 60,
  rest_when_injured      BOOLEAN NOT NULL DEFAULT TRUE,
  sell_junk              BOOLEAN NOT NULL DEFAULT TRUE,
  gold_reserve_pct       TINYINT UNSIGNED NOT NULL DEFAULT 30,
  potion_target          TINYINT UNSIGNED NOT NULL DEFAULT 2,
  buff_preference        ENUM('none','might','stoneskin','luck') NOT NULL DEFAULT 'none',
  shop_min_gain_pct      TINYINT UNSIGNED NOT NULL DEFAULT 5,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bot_profiles_code (code),
  CONSTRAINT chk_bp_retreat CHECK (retreat_threshold_pct <= 90),
  CONSTRAINT chk_bp_random  CHECK (randomness BETWEEN 0 AND 1)
) ENGINE=InnoDB;

CREATE TABLE accounts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(32)  NOT NULL,
  email           VARCHAR(190) NULL,
  password_hash   VARCHAR(255) NOT NULL,
  is_admin        BOOLEAN      NOT NULL DEFAULT FALSE,
  failed_logins   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME     NULL,
  last_login_at   DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_accounts_username (username),
  UNIQUE KEY uq_accounts_email (email)
) ENGINE=InnoDB;

CREATE TABLE loot_tables (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(32)  NOT NULL,
  name         VARCHAR(64)  NOT NULL,
  description  VARCHAR(255) NULL,
  UNIQUE KEY uq_loot_tables_code (code)
) ENGINE=InnoDB;

CREATE TABLE item_templates (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(40)  NOT NULL,
  name           VARCHAR(64)  NOT NULL,
  slot           ENUM('weapon','offhand','head','chest','hands','feet','ring','amulet') NOT NULL,
  item_type      VARCHAR(20)  NOT NULL,
  scaling_stat   ENUM('str','dex','int') NULL,
  two_handed     BOOLEAN      NOT NULL DEFAULT FALSE,
  base_power     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  base_armor     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  base_mres      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  implicit_mods  JSON NULL,
  req_level      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  base_value     INT UNSIGNED NOT NULL DEFAULT 1,
  description    VARCHAR(255) NULL,
  icon           VARCHAR(64)  NULL,
  UNIQUE KEY uq_item_templates_code (code),
  KEY ix_item_templates_slot (slot, req_level)
) ENGINE=InnoDB;

CREATE TABLE loot_table_entries (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loot_table_id      SMALLINT UNSIGNED NOT NULL,
  entry_type         ENUM('item','nothing') NOT NULL DEFAULT 'item',
  item_template_id   SMALLINT UNSIGNED NULL,
  weight             SMALLINT UNSIGNED NOT NULL,
  KEY ix_lte_table (loot_table_id),
  CONSTRAINT fk_lte_table    FOREIGN KEY (loot_table_id)    REFERENCES loot_tables(id)    ON DELETE CASCADE,
  CONSTRAINT fk_lte_template FOREIGN KEY (item_template_id) REFERENCES item_templates(id),
  CONSTRAINT chk_lte_item CHECK (entry_type = 'nothing' OR item_template_id IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE affixes (
  id               SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code             VARCHAR(32) NOT NULL,
  name             VARCHAR(32) NOT NULL,
  kind             ENUM('prefix','suffix') NOT NULL,
  stat_key         VARCHAR(20) NOT NULL,
  slot_groups      SET('weapon','offhand','armor','jewelry') NOT NULL,
  min_ilvl         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  weight           SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  value_min_base   DECIMAL(6,2) NOT NULL,
  value_max_base   DECIMAL(6,2) NOT NULL,
  value_per_ilvl   DECIMAL(6,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_affixes_code (code)
) ENGINE=InnoDB;

CREATE TABLE monsters (
  id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(32) NOT NULL,
  name            VARCHAR(64) NOT NULL,
  level           SMALLINT UNSIGNED NOT NULL,
  is_boss         BOOLEAN NOT NULL DEFAULT FALSE,
  hp              INT UNSIGNED NOT NULL,
  attack          SMALLINT UNSIGNED NOT NULL,
  defense         SMALLINT UNSIGNED NOT NULL,
  mres            SMALLINT UNSIGNED NOT NULL,
  speed           SMALLINT UNSIGNED NOT NULL,
  crit_pct        DECIMAL(4,1) NOT NULL DEFAULT 5.0,
  dodge_pct       DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  damage_type     ENUM('physical','magic') NOT NULL DEFAULT 'physical',
  xp              INT UNSIGNED NOT NULL,
  gold_min        INT UNSIGNED NOT NULL DEFAULT 0,
  gold_max        INT UNSIGNED NOT NULL DEFAULT 0,
  loot_table_id   SMALLINT UNSIGNED NULL,
  drop_chance     DECIMAL(4,3) NOT NULL DEFAULT 0.150,
  description     VARCHAR(255) NULL,
  UNIQUE KEY uq_monsters_code (code),
  CONSTRAINT fk_monsters_loot FOREIGN KEY (loot_table_id) REFERENCES loot_tables(id),
  CONSTRAINT chk_monsters_gold CHECK (gold_max >= gold_min)
) ENGINE=InnoDB;

CREATE TABLE places (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(32) NOT NULL,
  name                  VARCHAR(64) NOT NULL,
  place_type            ENUM('rest','forest','mine','dungeon','mountain','cave') NOT NULL,
  min_level             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  max_level             SMALLINT UNSIGNED NULL,
  rec_min_level         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  rec_max_level         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  rec_level             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  encounter_min         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  encounter_max         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  boss_monster_id       SMALLINT UNSIGNED NULL,
  boss_chance           DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  rarity_bonus          DECIMAL(4,2) NOT NULL DEFAULT 0.00,
  gold_mult             DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  chest_loot_table_id   SMALLINT UNSIGNED NULL,
  map_x                 SMALLINT NOT NULL DEFAULT 0,
  map_y                 SMALLINT NOT NULL DEFAULT 0,
  description           TEXT NULL,
  is_active             BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE KEY uq_places_code (code),
  CONSTRAINT fk_places_boss  FOREIGN KEY (boss_monster_id)     REFERENCES monsters(id),
  CONSTRAINT fk_places_chest FOREIGN KEY (chest_loot_table_id) REFERENCES loot_tables(id),
  CONSTRAINT chk_places_enc CHECK (encounter_max >= encounter_min),
  CONSTRAINT chk_places_levels CHECK (
        rec_min_level >= min_level
    AND rec_max_level >= rec_min_level
    AND rec_level BETWEEN rec_min_level AND rec_max_level
    AND (max_level IS NULL OR max_level >= rec_max_level))
) ENGINE=InnoDB;

CREATE TABLE place_monsters (
  place_id     SMALLINT UNSIGNED NOT NULL,
  monster_id   SMALLINT UNSIGNED NOT NULL,
  weight       SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  min_group    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  max_group    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (place_id, monster_id),
  CONSTRAINT fk_pm_place   FOREIGN KEY (place_id)   REFERENCES places(id)   ON DELETE CASCADE,
  CONSTRAINT fk_pm_monster FOREIGN KEY (monster_id) REFERENCES monsters(id),
  CONSTRAINT chk_pm_group CHECK (min_group >= 1 AND max_group >= min_group AND max_group <= 3)
) ENGINE=InnoDB;

CREATE TABLE characters (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id             INT UNSIGNED NULL,
  is_bot                 BOOLEAN NOT NULL DEFAULT FALSE,
  bot_profile_id         SMALLINT UNSIGNED NULL,
  name                   VARCHAR(32) NOT NULL,
  level                  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  xp                     INT UNSIGNED NOT NULL DEFAULT 0,
  total_xp               BIGINT UNSIGNED NOT NULL DEFAULT 0,
  gold                   INT UNSIGNED NOT NULL DEFAULT 0,
  stat_str               SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  stat_dex               SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  stat_int               SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  stat_vit               SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  stat_luk               SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  unspent_points         SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  retreat_threshold_pct  TINYINT UNSIGNED NOT NULL DEFAULT 25,
  potion_threshold_pct   TINYINT UNSIGNED NOT NULL DEFAULT 40,
  auto_repeat            BOOLEAN NOT NULL DEFAULT TRUE,
  injury_severity        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  injury_runs_left       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_place_id          SMALLINT UNSIGNED NULL,
  best_place_id          SMALLINT UNSIGNED NULL,
  trips_total            INT UNSIGNED NOT NULL DEFAULT 0,
  trips_cleared          INT UNSIGNED NOT NULL DEFAULT 0,
  trips_retreated        INT UNSIGNED NOT NULL DEFAULT 0,
  defeats                INT UNSIGNED NOT NULL DEFAULT 0,
  kills                  INT UNSIGNED NOT NULL DEFAULT 0,
  boss_kills             INT UNSIGNED NOT NULL DEFAULT 0,
  is_active              BOOLEAN NOT NULL DEFAULT TRUE,
  row_version            INT UNSIGNED NOT NULL DEFAULT 0,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_characters_name (name),
  UNIQUE KEY uq_characters_account (account_id),
  KEY ix_characters_leader (level DESC, total_xp DESC),
  CONSTRAINT fk_char_account FOREIGN KEY (account_id)     REFERENCES accounts(id),
  CONSTRAINT fk_char_profile FOREIGN KEY (bot_profile_id) REFERENCES bot_profiles(id),
  CONSTRAINT fk_char_last    FOREIGN KEY (last_place_id)  REFERENCES places(id),
  CONSTRAINT fk_char_best    FOREIGN KEY (best_place_id)  REFERENCES places(id),
  CONSTRAINT chk_char_retreat CHECK (retreat_threshold_pct <= 90),
  CONSTRAINT chk_char_owner CHECK (
        (is_bot = TRUE  AND account_id IS NULL     AND bot_profile_id IS NOT NULL)
     OR (is_bot = FALSE AND account_id IS NOT NULL AND bot_profile_id IS NULL))
) ENGINE=InnoDB;

CREATE TABLE simulation_runs (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  status             ENUM('running','completed','failed','abandoned') NOT NULL DEFAULT 'running',
  seed               BIGINT UNSIGNED NOT NULL,
  started_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  heartbeat_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at        DATETIME NULL,
  host               VARCHAR(64) NULL,
  pid                INT UNSIGNED NULL,
  args               JSON NULL,
  code_version       VARCHAR(40) NULL,
  characters_total   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  characters_done    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  resume_count       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error_text         TEXT NULL,
  running_guard      TINYINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'running', 1, NULL)) STORED,
  UNIQUE KEY uq_runs_one_running (running_guard),
  KEY ix_runs_status (status)
) ENGINE=InnoDB;

CREATE TABLE trip_orders (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  character_id          INT UNSIGNED NOT NULL,
  place_id              SMALLINT UNSIGNED NOT NULL,
  submitted_by          ENUM('web','bot','auto_repeat','system') NOT NULL,
  submitted_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status                ENUM('pending','consumed','cancelled') NOT NULL DEFAULT 'pending',
  consumed_run_id       INT UNSIGNED NULL,
  pending_character_id  INT UNSIGNED GENERATED ALWAYS AS (IF(status = 'pending', character_id, NULL)) STORED,
  UNIQUE KEY uq_orders_one_pending (pending_character_id),
  KEY ix_orders_char (character_id, submitted_at),
  CONSTRAINT fk_orders_char  FOREIGN KEY (character_id)    REFERENCES characters(id),
  CONSTRAINT fk_orders_place FOREIGN KEY (place_id)        REFERENCES places(id),
  CONSTRAINT fk_orders_run   FOREIGN KEY (consumed_run_id) REFERENCES simulation_runs(id)
) ENGINE=InnoDB;

CREATE TABLE trips (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id              INT UNSIGNED NOT NULL,
  character_id        INT UNSIGNED NOT NULL,
  place_id            SMALLINT UNSIGNED NOT NULL,
  order_id            INT UNSIGNED NULL,
  outcome             ENUM('cleared','retreated','defeated','rested','blocked_injured','order_rejected') NOT NULL,
  encounters_planned  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  encounters_won      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  boss_encountered    BOOLEAN NOT NULL DEFAULT FALSE,
  boss_killed         BOOLEAN NOT NULL DEFAULT FALSE,
  max_hp              INT UNSIGNED NOT NULL,
  hp_end              INT UNSIGNED NOT NULL,
  xp_gained           INT UNSIGNED NOT NULL DEFAULT 0,
  gold_gained         INT UNSIGNED NOT NULL DEFAULT 0,
  level_before        SMALLINT UNSIGNED NOT NULL,
  level_after         SMALLINT UNSIGNED NOT NULL,
  rng_seed            VARCHAR(64) NOT NULL,
  snapshot            JSON NOT NULL,
  consumables_used    JSON NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_trips_run_char (run_id, character_id),
  KEY ix_trips_char (character_id, created_at),
  CONSTRAINT fk_trips_run   FOREIGN KEY (run_id)       REFERENCES simulation_runs(id),
  CONSTRAINT fk_trips_char  FOREIGN KEY (character_id) REFERENCES characters(id),
  CONSTRAINT fk_trips_place FOREIGN KEY (place_id)     REFERENCES places(id),
  CONSTRAINT fk_trips_order FOREIGN KEY (order_id)     REFERENCES trip_orders(id)
) ENGINE=InnoDB;

CREATE TABLE trip_encounters (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  trip_id         INT UNSIGNED NOT NULL,
  seq             TINYINT UNSIGNED NOT NULL,
  is_boss         BOOLEAN NOT NULL DEFAULT FALSE,
  monsters        JSON NOT NULL,
  result          ENUM('won','fled','lost','exhausted','left_early') NOT NULL,
  rounds          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  damage_dealt    INT UNSIGNED NOT NULL DEFAULT 0,
  damage_taken    INT UNSIGNED NOT NULL DEFAULT 0,
  hp_before       INT UNSIGNED NOT NULL,
  hp_after        INT UNSIGNED NOT NULL,
  xp              INT UNSIGNED NOT NULL DEFAULT 0,
  gold            INT UNSIGNED NOT NULL DEFAULT 0,
  log             JSON NOT NULL,
  UNIQUE KEY uq_enc_trip_seq (trip_id, seq),
  CONSTRAINT fk_enc_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE item_instances (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id        SMALLINT UNSIGNED NOT NULL,
  character_id       INT UNSIGNED NULL,
  status             ENUM('owned','sold','lost','destroyed') NOT NULL DEFAULT 'owned',
  rarity             ENUM('common','uncommon','rare','epic','legendary') NOT NULL DEFAULT 'common',
  item_level         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  display_name       VARCHAR(96) NOT NULL,
  power              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  armor              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  mres               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  sell_value         INT UNSIGNED NOT NULL DEFAULT 1,
  source             ENUM('starter','drop','chest','boss','shop','admin','craft') NOT NULL DEFAULT 'drop',
  created_run_id     INT UNSIGNED NULL,
  created_trip_id    INT UNSIGNED NULL,
  acquired_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_items_owner (character_id, status),
  CONSTRAINT fk_items_template FOREIGN KEY (template_id)     REFERENCES item_templates(id),
  CONSTRAINT fk_items_owner    FOREIGN KEY (character_id)    REFERENCES characters(id),
  CONSTRAINT fk_items_run      FOREIGN KEY (created_run_id)  REFERENCES simulation_runs(id),
  CONSTRAINT fk_items_trip     FOREIGN KEY (created_trip_id) REFERENCES trips(id)
) ENGINE=InnoDB;

CREATE TABLE item_instance_affixes (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_instance_id  INT UNSIGNED NOT NULL,
  affix_id          SMALLINT UNSIGNED NOT NULL,
  stat_key          VARCHAR(20) NOT NULL,
  value             SMALLINT NOT NULL,
  UNIQUE KEY uq_iia (item_instance_id, affix_id),
  CONSTRAINT fk_iia_item  FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
  CONSTRAINT fk_iia_affix FOREIGN KEY (affix_id)         REFERENCES affixes(id)
) ENGINE=InnoDB;

CREATE TABLE character_equipment (
  character_id      INT UNSIGNED NOT NULL,
  slot              ENUM('weapon','offhand','head','chest','hands','feet','ring1','ring2','amulet') NOT NULL,
  item_instance_id  INT UNSIGNED NOT NULL,
  equipped_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (character_id, slot),
  UNIQUE KEY uq_equip_item (item_instance_id),
  CONSTRAINT fk_equip_char FOREIGN KEY (character_id)     REFERENCES characters(id),
  CONSTRAINT fk_equip_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id)
) ENGINE=InnoDB;

CREATE TABLE trip_loot (
  trip_id           INT UNSIGNED NOT NULL,
  item_instance_id  INT UNSIGNED NOT NULL,
  encounter_seq     TINYINT UNSIGNED NULL,
  kept              BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (trip_id, item_instance_id),
  CONSTRAINT fk_tl_trip FOREIGN KEY (trip_id)          REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_tl_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id)
) ENGINE=InnoDB;

CREATE TABLE mail (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  character_id   INT UNSIGNED NOT NULL,
  run_id         INT UNSIGNED NULL,
  trip_id        INT UNSIGNED NULL,
  mail_type      ENUM('trip_report','level_up','system') NOT NULL DEFAULT 'trip_report',
  subject        VARCHAR(160) NOT NULL,
  body_text      MEDIUMTEXT NOT NULL,
  payload        JSON NULL,
  is_read        BOOLEAN NOT NULL DEFAULT FALSE,
  deleted_at     DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_trip (trip_id),
  KEY ix_mail_inbox (character_id, deleted_at, created_at),
  CONSTRAINT fk_mail_char FOREIGN KEY (character_id) REFERENCES characters(id),
  CONSTRAINT fk_mail_run  FOREIGN KEY (run_id)       REFERENCES simulation_runs(id),
  CONSTRAINT fk_mail_trip FOREIGN KEY (trip_id)      REFERENCES trips(id)
) ENGINE=InnoDB;

CREATE TABLE bot_decisions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id         INT UNSIGNED NOT NULL,
  character_id   INT UNSIGNED NOT NULL,
  decision       JSON NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_botdec_run_char (run_id, character_id),
  CONSTRAINT fk_botdec_run  FOREIGN KEY (run_id)       REFERENCES simulation_runs(id),
  CONSTRAINT fk_botdec_char FOREIGN KEY (character_id) REFERENCES characters(id)
) ENGINE=InnoDB;

CREATE TABLE consumable_templates (
  id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(40) NOT NULL,
  name            VARCHAR(64) NOT NULL,
  effect_type     ENUM('heal_pct','buff_attack_pct','buff_defense_pct','flee_bonus','luck_bonus','cure_injury') NOT NULL,
  effect_value    DECIMAL(6,2) NOT NULL,
  use_trigger     ENUM('auto_low_hp','trip_start','on_flee','manual') NOT NULL,
  req_level       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  base_price      INT UNSIGNED NOT NULL,
  sell_price      INT UNSIGNED NOT NULL,
  description     VARCHAR(255) NULL,
  UNIQUE KEY uq_consumables_code (code)
) ENGINE=InnoDB;

CREATE TABLE character_consumables (
  character_id    INT UNSIGNED NOT NULL,
  consumable_id   SMALLINT UNSIGNED NOT NULL,
  quantity        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  belt_qty        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (character_id, consumable_id),
  CONSTRAINT fk_cc_char FOREIGN KEY (character_id)  REFERENCES characters(id),
  CONSTRAINT fk_cc_cons FOREIGN KEY (consumable_id) REFERENCES consumable_templates(id),
  CONSTRAINT chk_cc_belt CHECK (belt_qty <= quantity)
) ENGINE=InnoDB;

CREATE TABLE shop_listings (
  id                 SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind               ENUM('gear','consumable') NOT NULL,
  item_template_id   SMALLINT UNSIGNED NULL,
  consumable_id      SMALLINT UNSIGNED NULL,
  price              INT UNSIGNED NOT NULL,
  min_level          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  stock              SMALLINT UNSIGNED NULL,
  sort_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active          BOOLEAN NOT NULL DEFAULT TRUE,
  KEY ix_listings_kind (kind, is_active, min_level),
  CONSTRAINT fk_sl_template FOREIGN KEY (item_template_id) REFERENCES item_templates(id),
  CONSTRAINT fk_sl_cons     FOREIGN KEY (consumable_id)    REFERENCES consumable_templates(id),
  CONSTRAINT chk_sl_target CHECK (
        (kind = 'gear'       AND item_template_id IS NOT NULL AND consumable_id IS NULL)
     OR (kind = 'consumable' AND consumable_id IS NOT NULL    AND item_template_id IS NULL))
) ENGINE=InnoDB;

CREATE TABLE shop_transactions (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  character_id       INT UNSIGNED NOT NULL,
  actor              ENUM('web','bot','sim_autosell') NOT NULL,
  run_id             INT UNSIGNED NULL,
  action             ENUM('buy','sell','use') NOT NULL,
  listing_id         SMALLINT UNSIGNED NULL,
  item_instance_id   INT UNSIGNED NULL,
  consumable_id      SMALLINT UNSIGNED NULL,
  quantity           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  gold_delta         INT NOT NULL,
  gold_after         INT UNSIGNED NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_st_char (character_id, created_at),
  CONSTRAINT fk_st_char    FOREIGN KEY (character_id)     REFERENCES characters(id),
  CONSTRAINT fk_st_run     FOREIGN KEY (run_id)           REFERENCES simulation_runs(id),
  CONSTRAINT fk_st_listing FOREIGN KEY (listing_id)       REFERENCES shop_listings(id),
  CONSTRAINT fk_st_item    FOREIGN KEY (item_instance_id) REFERENCES item_instances(id),
  CONSTRAINT fk_st_cons    FOREIGN KEY (consumable_id)    REFERENCES consumable_templates(id)
) ENGINE=InnoDB;

CREATE VIEW v_inventory AS
SELECT ii.*
FROM item_instances ii
LEFT JOIN character_equipment ce ON ce.item_instance_id = ii.id
WHERE ii.status = 'owned' AND ce.item_instance_id IS NULL;

CREATE VIEW v_leaderboard AS
SELECT c.id, c.name, c.is_bot, c.level, c.total_xp, c.gold, c.kills, c.boss_kills,
       c.trips_cleared, c.defeats, bp.name AS best_place
FROM characters c
LEFT JOIN places bp ON bp.id = c.best_place_id
WHERE c.is_active = TRUE;
