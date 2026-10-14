# Delvebound: Game Design Document

**Game:** Delvebound, an idle "expedition" RPG simulator
**Status:** Design v3: M1–M4 built; M6 moved everything to one Laravel codebase; arena (PvP) added
**Owner:** Rey
**Last updated:** 2026-09-27
**Stack:** Laravel 12 (PHP 8.2+) for both the website and the simulation (`php artisan delve:run`) ·
Inertia.js 2 + React 19 + TypeScript + Tailwind CSS 4 (UI) · MySQL 8.0 · Apache · WSL2 Ubuntu 22.04
**Repo root:** `/var/www/vhosts/delvebound` · **Site:** `http://delvebound.host`

> All numbers marked **[T]** are *tunable starting balance values*. They are expected to change after
> playtesting and live in `config/balance.json` (see §12), not in code.

---

## Table of Contents

1. [Overview and the Run Loop](#1-overview-and-the-run-loop)
2. [MySQL Schema](#2-mysql-schema)
3. [Stats and Derived Stats](#3-stats-and-derived-stats)
4. [Combat Resolution](#4-combat-resolution)
5. [XP Curve and Level-Up Rewards](#5-xp-curve-and-level-up-rewards)
6. [Loot](#6-loot)
7. [Starter Content and Shop](#7-starter-content-and-shop)
8. [Bot AI](#8-bot-ai)
9. [Mail Report Format](#9-mail-report-format)
10. [Simulation Command (delve:run)](#10-simulation-command-delverun)
11. [Website (Laravel + Inertia + React)](#11-website-laravel--inertia--react)
12. [Project Layout, Config, and WSL Setup](#12-project-layout-config-and-wsl-setup)
13. [Build Milestones](#13-build-milestones)
14. [Decisions Log](#14-decisions-log)
15. [Arena (PvP)](#15-arena-pvp)
16. [Premium Store](#16-premium-store)

---

## 1. Overview and the Run Loop

### 1.1 Pitch

Delvebound is an asynchronous "send your hero on an expedition" RPG. You never steer a fight. You
prepare: spend stat points, pick gear, buy potions, choose where to go, and set how early to
retreat. Then the world ticks. After each tick you read a mail report of what happened: fights, loot,
level-ups, or a limp home in defeat. Human players (usually just Rey) compete with six rule-based bot
adventurers on a shared leaderboard.

### 1.2 Design principles

| Principle | Consequence |
|---|---|
| **The database is the only channel between the site and the simulation.** | Both live in one Laravel codebase and share the pure game code (`app/Game`), but a web request never runs, queues, or triggers a simulation, and the simulation never calls the website. The site writes *intentions* (stats, equipment, purchases, orders); the simulation (`php artisan delve:run`) reads them and writes *results* (trips, loot, mail). |
| **Deterministic, reproducible runs.** | Every run has a seed. The same seed, DB state, and character always give the same result. This makes `--dry-run` and debugging reliable. |
| **Pure core, thin I/O.** | Combat, loot, and progression are pure functions (input data + RNG → result). Only the runner touches the DB. |
| **Atomic per character.** | Each character's trip result is committed in one transaction, so a crash can never half-apply a trip. |
| **Bots play by the same rules.** | Bots use the same tables, the same shop, and the same simulation path as humans. The only difference is who fills in the decisions. No LLM or external calls. |

### 1.3 The run loop

Runs are **manual only**. Rey opens a terminal and runs `php artisan delve:run` whenever he wants,
as often as he wants. Each invocation is one **run** with its own run id. There is no schedule, no
cron, and no catch-up logic.

```
 ┌──────────────────────────── PLANNING (any time between runs) ─────────────────────────┐
 │ Humans (website): spend stat points → equip/unequip → shop (buy/sell) → fill potion   │
 │                   belt → pick destination (order)                                      │
 │ Bots:             nothing yet (they decide at the start of the run)                    │
 └────────────────────────────────────────────────────────────────────────────────────────┘
                                   │  Rey: php artisan delve:run
                                   ▼
 ┌──────────────────────────────── delve:run ─────────────────────────────────────────────┐
 │ 0. GET_LOCK + insert simulation_runs row (new run id; only one 'running' run allowed)  │
 │    Open the next map if someone has reached the frontier. Then, unless this is a     │
 │    resume, a newcomer is born on spawn_chance (until the cap) and humans get a letter│
 │ 1. BOT PHASE: for each bot: read own mail → allocate points → equip best gear →        │
 │    sell junk → choose destination → shop (upgrades, potions, buffs) → write order      │
 │ 2. SNAPSHOT: collect pending orders submitted before the run started;                  │
 │    no order → auto-repeat last place, or rest in Town                                  │
 │ 3. RESOLVE (per character, own RNG): encounters → combat (+potions) → retreat/defeat → │
 │    loot rolls                                                                          │
 │ 4. APPLY (per character, one transaction): trip + encounters + items + consumables +   │
 │    XP/level-ups + gold + injury + consume order + mail                                 │
 │ 5. Mark run completed, release lock, print summary                                     │
 └────────────────────────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
               Players read mail, check the leaderboard, shop, plan again
```

Pacing is entirely up to Rey. Nothing in the game depends on wall-clock time between runs.

The **arena** is a second manual command, `php artisan delve:arena` (§15). It does not start an expedition.
Humans sign up on the website; every active bot steps in, including newcomers born on expedition runs. The bracket is random and single-elimination.
Fights go until one side reaches 0 HP.

### 1.4 Glossary

| Term | Meaning |
|---|---|
| **Run** | One invocation of `php artisan delve:run` (a `simulation_runs` row, identified by its run id). |
| **Account** | A website login (human only). One account owns exactly one character. |
| **Order** | A character's chosen destination for the next run (`trip_orders`). |
| **Trip** | One character's expedition within a run: a sequence of encounters. |
| **Encounter** | One fight vs. a group of 1–3 monsters (or a boss). |
| **Place** | A destination: Town (rest), forest, mine, dungeon, etc. |
| **Template / Instance** | Static item definition vs. an owned item with rolled rarity and affixes. |
| **Consumable** | A stackable item (potion, tonic, bandage) bought in the shop; used during trips or from the inventory. |
| **Belt** | The consumables a character takes on the next trip (limited slots). |
| **Arena** | One invocation of `php artisan delve:arena`: a random knockout bracket among signed-up humans and every active bot. |

---

## 2. MySQL Schema

### 2.1 Conventions

- Engine InnoDB, charset `utf8mb4`, collation `utf8mb4_0900_ai_ci`.
- All `DATETIME` columns are **UTC** (the connection sets `time_zone = '+00:00'`). The website converts
  to Asia/Bangkok for display.
- Primary stats are stored as `stat_str`, `stat_dex`, `stat_int`, `stat_vit`, `stat_luk`, because
  `INT` is a reserved word.
- **Stat keys** used by affixes and implicit modifiers (one shared vocabulary for PHP, TypeScript, and JSON):
  `str, dex, int, vit, luk, hp, attack, armor, mres, crit_pct, crit_dmg_pct, dodge_pct, speed,
  gold_find_pct, magic_find_pct`.
- **Bonus keys** used by skills, the horcrux, and premium pieces (`App\Game\Stats::BONUS_KEYS`, §3.2.1):
  `hp, attack, defense, mres, crit_pct, crit_dmg_pct, dodge_pct, speed, magic_find_pct, gold_find_pct`
  (flat) and `hp_pct, attack_pct, defense_pct, lifesteal_pct, penetration_pct, dmg_reduction_pct, xp_pct`
  (percent layer and premium-only stats).
- **Accounts vs. characters:** `accounts` holds human logins only. `characters.account_id` is `UNIQUE`,
  so one account has exactly one character; bots have `account_id = NULL` (a UNIQUE index allows many
  NULLs), `is_bot = TRUE`, and a `bot_profile_id`. A CHECK constraint enforces this.
- **Inventory** means `item_instances` rows with `character_id = X AND status = 'owned'` that are *not*
  referenced by `character_equipment`. A view `v_inventory` is provided. Consumables are stacks in
  `character_consumables`, not instances.
- **Gold changes** from the shop are written to `shop_transactions` (an audit ledger) in the same
  transaction as the `characters.gold` update.
- Three DB users, one Laravel connection each: `delve_admin` (connection `admin`: migrations and seeds),
  `delve_sim` (connection `sim`: `delve:run` DML), and `delve_web` (connection `mysql`, the default: web
  requests, limited DML). See §12.
- **Schema source:** this section's SQL lives in `database/schema/001_schema.sql` and is applied by the
  Laravel migration `0001_01_01_000001_delvebound_schema`. That migration is a no-op on a database that
  already has the tables (databases created before M6 are adopted as-is). Every later change is a new
  Laravel migration in `database/migrations/`. The old `schema_migrations` table is kept for history;
  Laravel tracks migrations in its own `migrations` table.

### 2.2 Entity overview

```
accounts 1─1 characters *─1 bot_profiles          (bots: account_id NULL)
characters 1─* item_instances *─1 item_templates
item_instances 1─* item_instance_affixes *─1 affixes
characters 1─* character_equipment (slot) ─1 item_instances
characters 1─* character_consumables *─1 consumable_templates
shop_listings ─ item_templates | consumable_templates;  characters 1─* shop_transactions
regions 1─* places *─* monsters (place_monsters);  places ─ boss monster;  places ─ chest loot_table
monsters ─ loot_tables 1─* loot_table_entries ─ item_templates
legendary_names (titles for legendary drops; not a foreign key on the instance)
simulation_runs 1─* trips 1─* trip_encounters;  trips 1─* trip_loot;  trips 1─1 mail;  trips 1─0..1 loot_boxes
characters 1─* trip_orders;  simulation_runs 1─* bot_decisions
characters 1─* arena_signups;  arena_tournaments 1─* arena_matches;  arena_tournaments 1─* mail (arena_report)
premium_items (catalog; owned/worn codes live in characters.premium);  shop_transactions ─0..1 premium_items
```

### 2.3 CREATE TABLE statements

```sql
-- 001_schema.sql
CREATE DATABASE IF NOT EXISTS delvebound CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE delvebound;

CREATE TABLE schema_migrations (
  version      VARCHAR(64)  NOT NULL PRIMARY KEY,
  applied_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ───────────────────────────── Bots & accounts ─────────────────────────────

CREATE TABLE bot_profiles (
  id                     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                   VARCHAR(32)  NOT NULL,
  name                   VARCHAR(64)  NOT NULL,
  archetype              ENUM('tank','rogue','mage','berserker','ranger','gambler') NOT NULL,
  description            VARCHAR(255) NULL,
  -- stat allocation weights (relative; normalised at runtime)
  w_str                  DECIMAL(4,2) NOT NULL DEFAULT 0,
  w_dex                  DECIMAL(4,2) NOT NULL DEFAULT 0,
  w_int                  DECIMAL(4,2) NOT NULL DEFAULT 0,
  w_vit                  DECIMAL(4,2) NOT NULL DEFAULT 0,
  w_luk                  DECIMAL(4,2) NOT NULL DEFAULT 0,
  preferred_scaling      ENUM('str','dex','int') NOT NULL,
  -- weights on *derived* stats for gear scoring, e.g. {"hp":1.0,"attack":0.8,...}
  gear_weights           JSON NOT NULL,
  -- risk & behaviour
  risk_offset            TINYINT NOT NULL DEFAULT 0,          -- target place level = own level + this
  retreat_threshold_pct  TINYINT UNSIGNED NOT NULL DEFAULT 30,
  randomness             DECIMAL(3,2) NOT NULL DEFAULT 0.20,  -- 0 = robotic, 1 = chaotic
  rarity_preference      DECIMAL(3,2) NOT NULL DEFAULT 0.00,  -- attraction to places with rarity_bonus
  -- mail-based memory
  memory_window          TINYINT UNSIGNED NOT NULL DEFAULT 8,  -- how many recent reports to read
  fail_threshold         TINYINT UNSIGNED NOT NULL DEFAULT 2,  -- defeats at a place within window…
  fail_avoid_runs        TINYINT UNSIGNED NOT NULL DEFAULT 3,  -- …then avoid it for N runs
  stepup_after_wins      TINYINT UNSIGNED NOT NULL DEFAULT 2,  -- consecutive easy clears to step up
  stepup_hp_pct          TINYINT UNSIGNED NOT NULL DEFAULT 60, -- "easy" = cleared with >= this HP%
  rest_when_injured      BOOLEAN NOT NULL DEFAULT TRUE,
  sell_junk              BOOLEAN NOT NULL DEFAULT TRUE,
  -- shop behaviour (section 8.6)
  gold_reserve_pct       TINYINT UNSIGNED NOT NULL DEFAULT 30, -- never spend below this share of gold
  potion_target          TINYINT UNSIGNED NOT NULL DEFAULT 2,  -- healing potions to keep on the belt
  buff_preference        ENUM('none','might','stoneskin','luck') NOT NULL DEFAULT 'none',
  shop_min_gain_pct      TINYINT UNSIGNED NOT NULL DEFAULT 5,  -- min loadout-score gain to buy gear
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bot_profiles_code (code),
  CONSTRAINT chk_bp_retreat CHECK (retreat_threshold_pct <= 90),
  CONSTRAINT chk_bp_random  CHECK (randomness BETWEEN 0 AND 1)
) ENGINE=InnoDB;

CREATE TABLE accounts (                              -- human logins only; bots have none
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(32)  NOT NULL,
  email           VARCHAR(190) NULL,
  password_hash   VARCHAR(255) NOT NULL,           -- password_hash(PASSWORD_DEFAULT) output
  is_admin        BOOLEAN      NOT NULL DEFAULT FALSE,   -- first account (Rey); sees runs/admin pages
  failed_logins   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME     NULL,               -- short lockout after repeated failures
  last_login_at   DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_accounts_username (username),
  UNIQUE KEY uq_accounts_email (email)
) ENGINE=InnoDB;

-- ───────────────────────────── Static content ─────────────────────────────

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
  item_type      VARCHAR(20)  NOT NULL,          -- sword, dagger, staff, wand, bow, axe, shield, cloth, leather, mail, jewelry
  scaling_stat   ENUM('str','dex','int') NULL,   -- weapons only; int => magic damage
  two_handed     BOOLEAN      NOT NULL DEFAULT FALSE,
  base_power     SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- weapon damage
  base_armor     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  base_mres      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  implicit_mods  JSON NULL,                       -- e.g. {"crit_pct":3}
  req_level      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  base_value     INT UNSIGNED NOT NULL DEFAULT 1, -- gold, before rarity/ilvl multipliers
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
  name             VARCHAR(32) NOT NULL,              -- "Sturdy" / "of the Fox"
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

CREATE TABLE legendary_names (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40) NOT NULL,
  kind         ENUM('unique','base','epithet') NOT NULL,
  name         VARCHAR(48) NOT NULL,             -- "Leeching" / "Edge" / "Excalibur"
  item_type    VARCHAR(20) NULL,                 -- unique and base: sword, mail, jewelry, …
  slot         ENUM('weapon','offhand','head','chest','hands','feet','ring','amulet') NULL,
  stat_key     VARCHAR(20) NULL,                 -- epithet only: hp, str, …
  placement    ENUM('prefix','suffix') NULL,     -- epithet only: "Leeching Edge" vs "Edge of the Leech"
  min_ilvl     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  weight       SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  UNIQUE KEY uq_legendary_names_code (code),
  CONSTRAINT chk_legendary_names_shape CHECK (
        (kind IN ('unique','base') AND item_type IS NOT NULL AND slot IS NOT NULL AND stat_key IS NULL AND placement IS NULL)
     OR (kind = 'epithet' AND item_type IS NULL AND slot IS NULL AND stat_key IS NOT NULL AND placement IS NOT NULL)
  )
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
  drop_chance     DECIMAL(4,3) NOT NULL DEFAULT 0.150,   -- chance of one item roll per kill
  description     VARCHAR(255) NULL,
  UNIQUE KEY uq_monsters_code (code),
  CONSTRAINT fk_monsters_loot FOREIGN KEY (loot_table_id) REFERENCES loot_tables(id),
  CONSTRAINT chk_monsters_gold CHECK (gold_max >= gold_min)
) ENGINE=InnoDB;

CREATE TABLE regions (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(32) NOT NULL,
  name        VARCHAR(64) NOT NULL,
  blurb       VARCHAR(255) NULL,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_regions_code (code)
) ENGINE=InnoDB;

CREATE TABLE places (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  region_id             SMALLINT UNSIGNED NOT NULL,
  code                  VARCHAR(32) NOT NULL,
  name                  VARCHAR(64) NOT NULL,
  place_type            ENUM('rest','forest','mine','dungeon','mountain','cave','marsh','coast','ruins','temple') NOT NULL,
  min_level             SMALLINT UNSIGNED NOT NULL DEFAULT 1,   -- HARD gate: below this, web + sim refuse
  max_level             SMALLINT UNSIGNED NULL,                 -- optional HARD cap (starter areas); NULL = none
  rec_min_level         SMALLINT UNSIGNED NOT NULL DEFAULT 1,   -- recommended range (soft, shown on map)
  rec_max_level         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  rec_level             SMALLINT UNSIGNED NOT NULL DEFAULT 1,   -- single "target" level used by bots
  encounter_min         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  encounter_max         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  boss_monster_id       SMALLINT UNSIGNED NULL,
  boss_chance           DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  rarity_bonus          DECIMAL(4,2) NOT NULL DEFAULT 0.00,     -- added to luck factor (section 6)
  gold_mult             DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  chest_loot_table_id   SMALLINT UNSIGNED NULL,                 -- clear bonus
  map_x                 SMALLINT NOT NULL DEFAULT 0,
  map_y                 SMALLINT NOT NULL DEFAULT 0,
  description           TEXT NULL,
  is_active             BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE KEY uq_places_code (code),
  CONSTRAINT fk_places_region FOREIGN KEY (region_id) REFERENCES regions(id),
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

-- ───────────────────────────── Characters ─────────────────────────────

CREATE TABLE characters (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id             INT UNSIGNED NULL,            -- human owner; NULL for bots
  is_bot                 BOOLEAN NOT NULL DEFAULT FALSE,
  bot_profile_id         SMALLINT UNSIGNED NULL,       -- set iff is_bot
  name                   VARCHAR(32) NOT NULL,
  level                  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  xp                     INT UNSIGNED NOT NULL DEFAULT 0,      -- progress toward next level
  total_xp               BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- lifetime, for leaderboard
  gold                   INT UNSIGNED NOT NULL DEFAULT 0,
  stat_str               SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  stat_dex               SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  stat_int               SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  stat_vit               SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  stat_luk               SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  unspent_points         SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  retreat_threshold_pct  TINYINT UNSIGNED NOT NULL DEFAULT 25,
  potion_threshold_pct   TINYINT UNSIGNED NOT NULL DEFAULT 40, -- drink a healing potion below this HP%
  auto_repeat            BOOLEAN NOT NULL DEFAULT TRUE,        -- no order => repeat last place
  auto_mode              BOOLEAN NOT NULL DEFAULT FALSE,       -- human runs the bot planner at the start of a run
  auto_profile_id        SMALLINT UNSIGNED NULL,               -- bot_profiles row used while auto_mode is on
  auto_place             ENUM('profile','best') NOT NULL DEFAULT 'profile', -- profile: personality risk; best: hardest clearable place
  injury_severity        TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 none, 1 wounded, 2 badly wounded
  injury_runs_left       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_place_id          SMALLINT UNSIGNED NULL,
  best_place_id          SMALLINT UNSIGNED NULL,               -- highest rec_level cleared
  trips_total            INT UNSIGNED NOT NULL DEFAULT 0,
  trips_cleared          INT UNSIGNED NOT NULL DEFAULT 0,
  trips_retreated        INT UNSIGNED NOT NULL DEFAULT 0,
  defeats                INT UNSIGNED NOT NULL DEFAULT 0,
  kills                  INT UNSIGNED NOT NULL DEFAULT 0,
  boss_kills             INT UNSIGNED NOT NULL DEFAULT 0,
  is_active              BOOLEAN NOT NULL DEFAULT TRUE,
  row_version            INT UNSIGNED NOT NULL DEFAULT 0,      -- optimistic locking for web edits
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_characters_name (name),
  UNIQUE KEY uq_characters_account (account_id),       -- one account = one character (NULLs allowed for bots)
  KEY ix_characters_leader (level DESC, total_xp DESC),
  CONSTRAINT fk_char_account FOREIGN KEY (account_id)     REFERENCES accounts(id),
  CONSTRAINT fk_char_profile FOREIGN KEY (bot_profile_id) REFERENCES bot_profiles(id),
  CONSTRAINT fk_char_auto_profile FOREIGN KEY (auto_profile_id) REFERENCES bot_profiles(id),
  CONSTRAINT fk_char_last    FOREIGN KEY (last_place_id)  REFERENCES places(id),
  CONSTRAINT fk_char_best    FOREIGN KEY (best_place_id)  REFERENCES places(id),
  CONSTRAINT chk_char_retreat CHECK (retreat_threshold_pct <= 90),
  CONSTRAINT chk_char_owner CHECK (
        (is_bot = TRUE  AND account_id IS NULL     AND bot_profile_id IS NOT NULL)
     OR (is_bot = FALSE AND account_id IS NOT NULL AND bot_profile_id IS NULL))
) ENGINE=InnoDB;

-- ───────────────────────────── Runs, orders, trips ─────────────────────────────

CREATE TABLE simulation_runs (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,   -- the run id; every invocation gets a new one
  status             ENUM('running','completed','failed','abandoned') NOT NULL DEFAULT 'running',
  seed               BIGINT UNSIGNED NOT NULL,
  started_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  heartbeat_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at        DATETIME NULL,
  host               VARCHAR(64) NULL,
  pid                INT UNSIGNED NULL,
  args               JSON NULL,
  code_version       VARCHAR(40) NULL,                 -- git short SHA if available
  characters_total   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  characters_done    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  resume_count       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error_text         TEXT NULL,
  -- at most ONE run may be 'running' at a time (NULLs never collide in a UNIQUE index)
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
  -- at most one pending order per character (NULLs never collide in a UNIQUE index)
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
  rng_seed            VARCHAR(64) NOT NULL,           -- "<run seed>:<character id>"
  snapshot            JSON NOT NULL,                  -- stats, derived stats, equipped gear, belt at trip start
  consumables_used    JSON NULL,                      -- [{"code":"minor_healing_potion","qty":1}, ...]
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_trips_run_char (run_id, character_id),   -- the idempotency guard
  KEY ix_trips_char (character_id, created_at),
  CONSTRAINT fk_trips_run   FOREIGN KEY (run_id)       REFERENCES simulation_runs(id),
  CONSTRAINT fk_trips_char  FOREIGN KEY (character_id) REFERENCES characters(id),
  CONSTRAINT fk_trips_place FOREIGN KEY (place_id)     REFERENCES places(id),
  CONSTRAINT fk_trips_order FOREIGN KEY (order_id)     REFERENCES trip_orders(id)
) ENGINE=InnoDB;

CREATE TABLE trip_encounters (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  trip_id         INT UNSIGNED NOT NULL,
  seq             TINYINT UNSIGNED NOT NULL,          -- 1..n
  is_boss         BOOLEAN NOT NULL DEFAULT FALSE,
  monsters        JSON NOT NULL,                      -- [{"monster_id":8,"name":"Skeleton Warrior","hp":104}, ...]
  result          ENUM('won','fled','lost','exhausted','left_early') NOT NULL,
  rounds          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  damage_dealt    INT UNSIGNED NOT NULL DEFAULT 0,
  damage_taken    INT UNSIGNED NOT NULL DEFAULT 0,
  hp_before       INT UNSIGNED NOT NULL,
  hp_after        INT UNSIGNED NOT NULL,
  xp              INT UNSIGNED NOT NULL DEFAULT 0,
  gold            INT UNSIGNED NOT NULL DEFAULT 0,
  log             JSON NOT NULL,                      -- round-by-round events (section 4.6)
  UNIQUE KEY uq_enc_trip_seq (trip_id, seq),
  CONSTRAINT fk_enc_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ───────────────────────────── Items ─────────────────────────────

CREATE TABLE item_instances (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id        SMALLINT UNSIGNED NOT NULL,
  character_id       INT UNSIGNED NULL,               -- owner; NULL once sold/lost
  status             ENUM('owned','sold','lost','destroyed') NOT NULL DEFAULT 'owned',
  rarity             ENUM('common','uncommon','rare','epic','legendary') NOT NULL DEFAULT 'common',
  item_level         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  display_name       VARCHAR(96) NOT NULL,            -- "Sturdy Iron Helm of the Fox"
  power              SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- final values after rarity/ilvl scaling
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
  stat_key          VARCHAR(20) NOT NULL,             -- copied from affix for simple reads
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
  UNIQUE KEY uq_equip_item (item_instance_id),          -- an item can be in one slot only
  CONSTRAINT fk_equip_char FOREIGN KEY (character_id)     REFERENCES characters(id),
  CONSTRAINT fk_equip_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id)
) ENGINE=InnoDB;

CREATE TABLE trip_loot (
  trip_id           INT UNSIGNED NOT NULL,
  item_instance_id  INT UNSIGNED NOT NULL,
  encounter_seq     TINYINT UNSIGNED NULL,              -- NULL = opened clear chest
  kept              BOOLEAN NOT NULL DEFAULT TRUE,      -- FALSE if lost on defeat or auto-sold
  PRIMARY KEY (trip_id, item_instance_id),
  CONSTRAINT fk_tl_trip FOREIGN KEY (trip_id)          REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_tl_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id)
) ENGINE=InnoDB;

CREATE TABLE loot_boxes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  character_id  INT UNSIGNED NOT NULL,
  trip_id       INT UNSIGNED NOT NULL,
  place_id      SMALLINT UNSIGNED NOT NULL,
  seed          INT UNSIGNED NOT NULL,                 -- Rng::chest(seed); drawn during the trip
  luck_factor   DECIMAL(6,3) NOT NULL,                 -- snapshotted: luck + place rarity + chest bonus
  source_level  SMALLINT UNSIGNED NOT NULL,            -- place.rec_level at the time
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

-- ───────────────────────────── Mail & bot logs ─────────────────────────────

CREATE TABLE mail (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  character_id   INT UNSIGNED NOT NULL,
  run_id         INT UNSIGNED NULL,
  trip_id        INT UNSIGNED NULL,
  mail_type      ENUM('trip_report','level_up','system') NOT NULL DEFAULT 'trip_report',
  subject        VARCHAR(160) NOT NULL,
  body_text      MEDIUMTEXT NOT NULL,           -- pre-rendered plain text (section 9)
  payload        JSON NULL,                     -- structured summary; bots read this for memory
  is_read        BOOLEAN NOT NULL DEFAULT FALSE,
  deleted_at     DATETIME NULL,                 -- soft delete (bot memory ignores deletion)
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_trip (trip_id),            -- one report per trip
  KEY ix_mail_inbox (character_id, deleted_at, created_at),
  CONSTRAINT fk_mail_char FOREIGN KEY (character_id) REFERENCES characters(id),
  CONSTRAINT fk_mail_run  FOREIGN KEY (run_id)       REFERENCES simulation_runs(id),
  CONSTRAINT fk_mail_trip FOREIGN KEY (trip_id)      REFERENCES trips(id)
) ENGINE=InnoDB;

CREATE TABLE bot_decisions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id         INT UNSIGNED NOT NULL,
  character_id   INT UNSIGNED NOT NULL,
  decision       JSON NOT NULL,   -- {"points":{"vit":3,"str":2},"equipped":[..],"sold":[..],"place":"sunken_crypt","why":{..}}
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_botdec_run_char (run_id, character_id),
  CONSTRAINT fk_botdec_run  FOREIGN KEY (run_id)       REFERENCES simulation_runs(id),
  CONSTRAINT fk_botdec_char FOREIGN KEY (character_id) REFERENCES characters(id)
) ENGINE=InnoDB;

-- ───────────────────────────── Shop & consumables ─────────────────────────────

CREATE TABLE consumable_templates (
  id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(40) NOT NULL,
  name            VARCHAR(64) NOT NULL,
  effect_type     ENUM('heal_pct','buff_attack_pct','buff_defense_pct','flee_bonus','luck_bonus','cure_injury') NOT NULL,
  effect_value    DECIMAL(6,2) NOT NULL,
  use_trigger     ENUM('auto_low_hp','trip_start','on_flee','manual') NOT NULL,
  req_level       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  base_price      INT UNSIGNED NOT NULL,              -- shop buy price
  sell_price      INT UNSIGNED NOT NULL,              -- what the shop pays back
  description     VARCHAR(255) NULL,
  UNIQUE KEY uq_consumables_code (code)
) ENGINE=InnoDB;

CREATE TABLE character_consumables (
  character_id    INT UNSIGNED NOT NULL,
  consumable_id   SMALLINT UNSIGNED NOT NULL,
  quantity        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  belt_qty        TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- how many to carry on the next trip
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
  min_level          SMALLINT UNSIGNED NOT NULL DEFAULT 1,  -- listing visible/buyable from this level
  stock              SMALLINT UNSIGNED NULL,                -- NULL = unlimited (v1: all unlimited)
  sort_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active          BOOLEAN NOT NULL DEFAULT TRUE,
  KEY ix_listings_kind (kind, is_active, min_level),
  CONSTRAINT fk_sl_template FOREIGN KEY (item_template_id) REFERENCES item_templates(id),
  CONSTRAINT fk_sl_cons     FOREIGN KEY (consumable_id)    REFERENCES consumable_templates(id),
  CONSTRAINT chk_sl_target CHECK (
        (kind = 'gear'       AND item_template_id IS NOT NULL AND consumable_id IS NULL)
     OR (kind = 'consumable' AND consumable_id IS NOT NULL    AND item_template_id IS NULL))
) ENGINE=InnoDB;

CREATE TABLE shop_transactions (                   -- append-only gold ledger for shop activity
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  character_id       INT UNSIGNED NOT NULL,
  actor              ENUM('web','bot','sim_autosell') NOT NULL,
  run_id             INT UNSIGNED NULL,               -- set for bot / autosell actions
  action             ENUM('buy','sell','use') NOT NULL,
  listing_id         SMALLINT UNSIGNED NULL,
  item_instance_id   INT UNSIGNED NULL,
  consumable_id      SMALLINT UNSIGNED NULL,
  quantity           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  gold_delta         INT NOT NULL,                    -- negative for buys
  gold_after         INT UNSIGNED NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_st_char (character_id, created_at),
  CONSTRAINT fk_st_char    FOREIGN KEY (character_id)     REFERENCES characters(id),
  CONSTRAINT fk_st_run     FOREIGN KEY (run_id)           REFERENCES simulation_runs(id),
  CONSTRAINT fk_st_listing FOREIGN KEY (listing_id)       REFERENCES shop_listings(id),
  CONSTRAINT fk_st_item    FOREIGN KEY (item_instance_id) REFERENCES item_instances(id),
  CONSTRAINT fk_st_cons    FOREIGN KEY (consumable_id)    REFERENCES consumable_templates(id)
) ENGINE=InnoDB;

-- ───────────────────────────── Views ─────────────────────────────

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
```

### 2.4 Growth tables

Added in migration `2026_09_28_180000_growth`. Content is `database/seeders/sql/030_growth.sql`.
Skill definitions and every tunable (costs, drop chances, feed values, power weights) stay in
`config/balance.json` → `growth`.

```sql
CREATE TABLE material_templates (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(64) NOT NULL,
  kind ENUM('dust','shard','essence') NOT NULL,
  glyph VARCHAR(8) NOT NULL DEFAULT '✦',
  description VARCHAR(255) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_material_templates_code (code)
) ENGINE=InnoDB;

CREATE TABLE place_materials (
  place_id SMALLINT UNSIGNED NOT NULL,
  material_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (place_id, material_id)
) ENGINE=InnoDB;

-- On characters (so the website user can update them with the existing characters grant):
--   materials JSON NULL          -- {"spark_dust": 2, "verdant_sap": 1}
--   skills JSON NULL             -- {"keen_edge": 1, "iron_hide": 2}
--   horcrux_tier TINYINT UNSIGNED NOT NULL DEFAULT 0
--   horcrux_progress INT UNSIGNED NOT NULL DEFAULT 0

CREATE TABLE trip_materials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  trip_id INT UNSIGNED NOT NULL,
  material_id SMALLINT UNSIGNED NOT NULL,
  qty SMALLINT UNSIGNED NOT NULL,
  kept BOOLEAN NOT NULL
) ENGINE=InnoDB;
```

### 2.5 Premium store tables

Added in migration `2026_10_08_120000_premium_store`. Content is `database/seeders/sql/050_premium.sql`.
Like the growth columns, a character's premium state sits on `characters`, so the website user's
existing `UPDATE` grant covers it and no new grant is needed. Purchases reuse the `shop_transactions`
ledger (the website already has `INSERT` there).

```sql
CREATE TABLE premium_items (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40)  NOT NULL,
  name         VARCHAR(64)  NOT NULL,
  category     ENUM('title','wings','aura','pet','frame','bundle') NOT NULL,
  tier         ENUM('rare','epic','legendary','mythic') NOT NULL DEFAULT 'rare',
  glyph        VARCHAR(8)   NOT NULL DEFAULT '✦',
  style        VARCHAR(20)  NOT NULL DEFAULT 'lantern',   -- UI colour family
  description  VARCHAR(255) NULL,
  price_cents  INT UNSIGNED NOT NULL,                      -- play money
  bonuses      JSON NULL,                                  -- Stats::BONUS_KEYS only
  power        SMALLINT UNSIGNED NOT NULL DEFAULT 0,       -- added to power level while worn
  grant_gold   INT UNSIGNED NOT NULL DEFAULT 0,            -- bundles only
  is_active    BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_premium_items_code (code),
  CONSTRAINT chk_premium_price CHECK (price_cents > 0),
  CONSTRAINT chk_premium_shape CHECK (
        (category = 'bundle' AND grant_gold > 0 AND bonuses IS NULL)
     OR (category <> 'bundle' AND grant_gold = 0))
) ENGINE=InnoDB;

-- On characters:
--   premium_cash INT UNSIGNED NULL   -- cents; NULL = never touched, read as balance.json premium.starting_cash_cents
--   premium JSON NULL                -- {"owned": ["wings_void"], "active": ["wings_void"], "equipped": {"wings": "wings_void"}}

-- On shop_transactions:
--   action ENUM('buy','sell','use','premium')
--   premium_item_id SMALLINT UNSIGNED NULL  (FK premium_items)
--   cash_delta INT NOT NULL DEFAULT 0, cash_after INT UNSIGNED NULL
```

**Retention:** `trip_encounters.log` grows fastest. Keep it for `LOG_RETENTION_DAYS=60` **[T]**; the
`--prune-logs` flag replaces old logs with `[]` and keeps the summary columns.

---

## 3. Stats and Derived Stats

### 3.1 Primary stats

| Stat | Short | Role |
|---|---|---|
| Strength | STR | Damage with STR weapons (swords, axes); a little defense |
| Dexterity | DEX | Damage with DEX weapons (daggers, bows); crit, dodge, speed |
| Intelligence | INT | Damage with INT weapons (staves, wands; magic damage); magic resist |
| Vitality | VIT | Max HP, defense, regen between encounters |
| Luck | LUK | Rarity and drop chance, gold, a little crit/dodge |

**New character:** level 1, every stat at 5, plus **5 unspent points** **[T]**.
**Gear bonuses** (`str`, `dex`, and so on from implicit mods and affixes) add to primary stats *before*
the derived formulas are applied. Spent points are permanent (respec costs gold, see §11).

### 3.2 Derived stats (explicit formulas)

Let `S, D, I, V, L` = total STR, DEX, INT, VIT, LUK (base + gear), `lvl` = character level,
`G(key)` = sum of that stat key across equipped items (implicit + affixes), and `W` = weapon power
(`1` if unarmed; unarmed scales with STR).

| Derived | Formula **[T]** | Cap |
|---|---|---|
| **Max HP** | `40 + 8·V + 4·lvl + G(hp)` | none |
| **Attack** (STR weapon) | `W + S + floor(D/4) + G(attack)` | none |
| **Attack** (DEX weapon) | `W + D + floor(S/4) + G(attack)` | none |
| **Attack** (INT weapon, *magic*) | `W + I + floor(L/4) + G(attack)` | none |
| **Defense** (vs physical) | `Σ item armor + floor(V/2) + floor(S/5) + G(armor)` | none |
| **Magic resist** (vs magic) | `Σ item mres + floor(I/2) + floor(V/5) + G(mres)` | none |
| **Crit chance %** | `5 + 0.5·D + 0.25·L + G(crit_pct)` | 50 |
| **Crit multiplier** | `1.5 + G(crit_dmg_pct)/100` | 3.0 |
| **Dodge %** | `2 + 0.4·D + 0.1·L + G(dodge_pct)` | 35 |
| **Speed** | `10 + D + G(speed)` | none |
| **Regen % between encounters** | `15 + 0.5·V` | 40 |
| **Luck factor** (loot) | `0.01·L + G(magic_find_pct)/100` | 1.5 |
| **Gold find mult** | `1 + 0.01·L + G(gold_find_pct)/100` | 3.0 |
| **Lifesteal %** | `B(lifesteal_pct)` | 20 |
| **Armor pierce %** | `B(penetration_pct)` | 50 |
| **Damage reduction %** | `B(dmg_reduction_pct)` | 30 |
| **XP mult** | `1 + B(xp_pct)/100` | ×2.0 (`xp_bonus_cap` 100) |

`B(key)` is the bonus from skills, the horcrux, and premium pieces (§3.2.1). Gear does not roll the last four.

#### 3.2.1 The bonus layer (skills, horcrux, premium)

`Stats::derived($stats, $level, $equipment, $balance, $injury, $buffs, $bonus)` takes one merged bonus
map (`Premium::combinedBonus` = `Growth::bonuses` + `Premium::bonuses`). It is applied in this order:

1. **Flat** keys add after the gear formulas above: `hp`, `attack`, `defense`, `mres`, `speed`,
   `crit_pct`, `dodge_pct`, `crit_dmg_pct` (÷100 onto the crit multiplier), `magic_find_pct`
   (÷100 onto luck), `gold_find_pct` (÷100 onto gold find).
2. **Percent** keys scale the result, rounded half up: `hp_pct` → max HP, `attack_pct` → attack,
   `defense_pct` → defense **and** magic resist. Example: 84 HP with `hp_pct 10` → 92.
3. **Caps** (the table above), then **injury**, then **belt buffs** (unchanged from before).

So *Wounded* and Elixir of Might multiply the already-bonused numbers. Every number in the caps
lives in `balance.json` → `derived`. The fixture file has a case for each step.

*Damage type:* INT-scaling weapons deal **magic** damage (reduced by the target's magic resist); all
others deal **physical** damage (reduced by defense). Mages trade HP and defense for reaching the
weak magic resist of armored monsters (golems, skeletons).

**Injury modifiers** (§4.5): *Wounded* gives −20% max HP and −10% attack for the trip.

**Consumable buffs** (§7.7), applied at trip start and lasting the whole trip: Elixir of Might +15% attack,
Stoneskin Tonic +15% defense and magic resist, Lucky Charm +0.20 luck factor. At most one of each.

### 3.3 Worked example: Rey at level 1

STR 7, DEX 6, INT 5, VIT 7, LUK 5 (5 starting points: +2 STR, +1 DEX, +2 VIT); gear: Rusty Sword (W=4),
Cloth Tunic (armor 2, mres 1), Soft Boots (armor 1, +1 speed).

| Stat | Calculation | Value |
|---|---|---|
| Max HP | 40 + 56 + 4 | **100** |
| Attack | 4 + 7 + floor(6/4)=1 | **12** (physical) |
| Defense | 3 + floor(7/2)=3 + floor(7/5)=1 | **7** |
| Magic resist | 1 + floor(5/2)=2 + floor(7/5)=1 | **4** |
| Crit | 5 + 3 + 1.25 | **9.25%** ×1.5 |
| Dodge | 2 + 2.4 + 0.5 | **4.9%** |
| Speed | 10 + 6 + 1 | **17** |

### 3.4 Shared implementation

There is **one implementation**, `App\Game\Stats::derived()`. The character sheet, the bots' gear scoring,
and combat all call it, and it reads its constants from **`config/balance.json`**. PHPUnit checks it against
the fixture file `tests/fixtures/derived_stats_cases.json` (inputs → expected outputs). Before M6 the
formulas were mirrored in Python and PHP; the fixtures are unchanged, so the numbers are identical.

---

## 4. Combat Resolution

### 4.0 Place access (level gates)

Every place is clearly level-specific. It has three level properties (§2.3, §7.1):

| Property | Column(s) | Kind | Meaning |
|---|---|---|---|
| Minimum level | `min_level` | **hard** | A character below it can't be sent there. |
| Recommended range | `rec_min_level`–`rec_max_level` (+ `rec_level` as the single bot target) | soft | Shown on the map; bots aim for it. Going above it is risky; going below it pays little XP (§5.3) but still drops materials (§6.7). |
| Maximum level | `max_level` (nullable) | recorded only | Kept on starter areas as a note of the old cap. It is **not** a gate. A higher-level character can go back to farm materials. |

There is **one rule**, `App\Game\Rules::canEnter()`, used by the website, the bots, and the simulation. It is
tested against the fixture `tests/fixtures/place_access_cases.json`:

```
can_enter(level, place):
    if not place.is_active:                                      return (False, 'inactive')
    if place.place_type == 'rest':                               return (True,  None)      # Town is always open
    if level < place.min_level:                                  return (False, 'too_low')
    return (True, None)                                          # max_level does not block (decision 22)
```

**Where it is enforced:**

1. **Website orders flow** (`POST /map/order`, §11.1): checked server-side before the `trip_orders` insert.
   A failure returns an error and writes nothing. Locked places are also greyed out with the reason
   ("Requires Lv 5"), but that UI hint is never the authority. A place above its recommended range stays
   open, with a low-XP warning, so materials can be farmed.
2. **Bot phase:** the destination candidates are filtered with `can_enter` (§8.7), so bots never even
   submit an invalid order.
3. **Simulation snapshot** (the final authority, at trip start): every order, including auto-repeats, is
   re-checked against the character's *current* level. A failure means outcome **`order_rejected`**: the
   character rests in Town, the order is cancelled, and the mail explains why and lists the places
   that are open. This catches orders to a place the character is still too low for, and any
   rows edited by hand in the DB. `max_level` is not re-checked.
4. The level at **trip start** is what counts. A level-up during the trip never invalidates it
   retroactively.

### 4.1 Trip setup

1. **Snapshot** the character: primary stats, equipped items, derived stats (with injury modifiers).
   Store it in `trips.snapshot`.
2. `hp = max_hp` (characters always start a trip at full HP; injuries lower `max_hp` instead).
3. `n = rng.randint(place.encounter_min, place.encounter_max)`.
4. `threshold = character.retreat_threshold_pct` (humans set this on the character sheet; bots copy it
   from their profile). `potion_threshold = character.potion_threshold_pct`.
5. **Belt:** load `belt_qty` of each consumable (max `BELT_SLOTS = 4` units in total **[T]**). Apply
   `trip_start` consumables now (buffs, Lucky Charm). A Bandage Kit is `manual` and never goes on the belt.

### 4.2 Building an encounter

For `seq = 1..n`:

- **Boss check:** if `seq == n` and the place has a boss and `rng.random() < boss_chance`, the
  encounter is the boss alone.
- **Otherwise**, pick a monster from `place_monsters` by `weight`. Group size `k ∈ [min_group, max_group]`
  is chosen with `P(k) ∝ 1/k` (small groups are more common).
- Monsters use their fixed stats from the `monsters` table (no per-instance scaling in v1).

### 4.3 Round loop (per encounter)

```
MAX_ROUNDS = 30                                   # [T]
for round in 1..MAX_ROUNDS:
    # 1. Initiative: each living actor rolls  speed + randint(0, 5); sort descending;
    #    ties → character first, then monsters in group order.
    for actor in turn_order:
        if actor dead: continue
        if actor is character:
            if character.hp < potion_threshold_pct/100 · max_hp and belt has a healing potion:
                drink the weakest potion that lifts HP above potion_threshold, else the strongest
                continue                                  # drinking uses the character's turn
            target = living monster with lowest current HP (ties → first)
        else:
            target = character
        resolve_attack(actor, target)
        if all monsters dead: → VICTORY
        if character.hp <= 0: → DEFEAT

    # 2. End-of-round retreat check (only while monsters remain)
    if character.hp < threshold_pct/100 · max_hp:
        p_flee = clamp(0.50 + 0.02 · (char.speed − max(monster.speed)), 0.25, 0.90)   # [T]
        if belt has a Smoke Bomb: p_flee = min(0.95, p_flee + 0.30); consume it
        if rng.random() < p_flee: → FLED (trip ends: outcome 'retreated')
        else:
            each living monster makes one free attack ("cut off while fleeing")
            if character.hp <= 0: → DEFEAT
            # the character tries again at the end of the next round
if MAX_ROUNDS reached: → EXHAUSTED (counts as a successful retreat; trip ends 'retreated')
```

### 4.4 Attack resolution

```
resolve_attack(A, T):
    # 1. Dodge
    if rng.random()·100 < T.dodge_pct:            → MISS (log "dodged")
    # 2. Mitigation: pick defense by damage type
    Def  = T.defense if A.damage_type == physical else T.mres
    Def ×= 1 − A.penetration/100                 # armor pierce (0 for monsters)
    base = A.attack² / (A.attack + Def)          # smooth diminishing returns
    # 3. Variance
    dmg = base × rng.uniform(0.90, 1.10)
    # 4. Crit
    if rng.random()·100 < A.crit_pct: dmg ×= A.crit_mult ; crit = True
    dmg ×= 1 − T.dmg_reduction/100               # damage reduction (0 for monsters)
    dmg = max(1, round(dmg))
    T.hp -= dmg
    # 5. Lifesteal: heal A by round(dmg × A.lifesteal/100), never above A.max_hp
```

Pierce, reduction, and lifesteal use no extra rolls, so a seed replays the same when they are 0. A
lifesteal heal is logged on the hit as `heal` and `ahp` (the attacker's HP after). The trip page and
the arena log show it. The best-map preview (§8.7) uses the same three rules at the average roll.

*Why `A²/(A+D)`:* it scales at every level without magic constants. When Def equals Attack, damage is
halved. When Def is 0, damage equals Attack. Example: Rey (attack 12) vs Giant Rat (def 2) gives
144/14 ≈ 10 per hit (3 hits to kill 26 HP). Giant Rat (attack 7) vs Rey (def 7) gives 49/14 ≈ 3.5 per hit.

### 4.5 Between encounters, end of trip, retreat and injury

**After a won encounter:**
1. Award XP (with the level-difference modifier, §5.3), gold, and item drops (§6) *into the trip's
   pouch*. Nothing is applied to the character until the end of the trip.
2. Regen: `hp = min(max_hp, hp + round(max_hp · regen_pct/100))`.
3. **Potion top-up:** if `hp < potion_threshold · max_hp` and a healing potion is on the belt, drink one.
4. **Voluntary leave:** if more encounters remain and `hp < threshold · max_hp` *after* regen and
   potions, the character goes home safely (encounter result `left_early`, trip outcome `retreated`).

**Trip outcomes:**

| Outcome | When | XP | Gold | Items | Injury | Extra |
|---|---|---|---|---|---|---|
| `cleared` | all `n` encounters won | 100% + **20% clear bonus** | 100% | all + **1 chest roll** (place chest table, luck +0.25) | none | `best_place_id` updated |
| `retreated` | fled, exhausted, or left early | 100% of won encounters | 100% | all | none | – |
| `defeated` | HP reached 0 | **50%** | **50%** | **all trip items lost** (`status='lost'`, `trip_loot.kept=0`) | yes (below) | – |
| `rested` | chose Town / no order and no auto-repeat | 0 | `5·lvl` odd-job gold | – | **all injury cleared** | – |
| `blocked_injured` | badly wounded; order ignored, forced rest | 0 | 0 | – | cleared afterwards | mail explains |
| `order_rejected` | destination fails the level gate at trip start (§4.0) | 0 | `5·lvl` | – | cleared (counts as rest) | order cancelled; mail lists open places |

**Injury rule [T]:**

| Situation on defeat | New state | Effect on next run(s) |
|---|---|---|
| Not injured | *Wounded* (`severity=1, runs_left=1`) | next trip: −20% max HP, −10% attack |
| Already wounded | *Badly wounded* (`severity=2, runs_left=1`) | next run is a forced rest (`blocked_injured`) |

- `runs_left` goes down by 1 after each trip the character takes while injured. At 0, severity resets to 0.
- A `rested` or `blocked_injured` trip clears the injury.
- No permanent losses: no level loss, no gear loss beyond items found on that trip, no permadeath.
  There is **no durability** on gear.
- **Consumables:** used ones are gone whatever the outcome (even on defeat). Unused belt items stay in
  `character_consumables`. A **Bandage Kit** (manual use on the website or in the bot step) cures
  *Wounded* before the next run, but not *Badly wounded*.

### 4.6 Battle log format (`trip_encounters.log`)

Compact events so the website can replay a fight:

```json
[
  {"r":1,"a":"Rey","t":"Skeleton Warrior#1","ev":"hit","dmg":21,"crit":false,"thp":83},
  {"r":1,"a":"Skeleton Warrior#1","t":"Rey","ev":"dodge"},
  {"r":2,"a":"Rey","t":"Skeleton Warrior#1","ev":"hit","dmg":33,"crit":true,"thp":50},
  {"r":4,"ev":"flee_fail","p":0.62},
  {"r":4,"a":"Skeleton Warrior#2","t":"Rey","ev":"free_hit","dmg":18,"thp":51}
]
```

---

## 5. XP Curve and Level-Up Rewards

### 5.1 XP to next level

```
xp_to_next(L) = round_to_nearest_10( 100 · L^1.6 · 1.05^(L − 1) )    # [T]
```

`L^1.6` is the level's base cost. `1.05^(L − 1)` adds **5%** for every level already gained, so the
requirement keeps climbing by a compounding share (the way base EXP does in Ragnarok) instead of the
gap flattening out. Level 1 is still 100. By level 10 the cost is about half again the old power-only
curve, and by level 30 it is several times that. `xp.growth` in `balance.json` is that 5%.

Level cap v1: **30** **[T]**, the floor. When a generated region extends past that, the effective cap
becomes the highest `rec_max_level` in `places` (`Progression::levelCap`). Characters stop gaining
levels at that ceiling and start again when the next map opens.

### 5.2 Rewards per level

- **Stat points:** +5 per level, plus a **+2 bonus on every 5th level** (5, 10, 15, …) **[T]**.
- **Gold:** `20 · new_level` **[T]**.
- Max HP also rises through the `4·lvl` term.
- Several level-ups in one trip are allowed (loop until `xp < xp_to_next(level)`). Each one gets its own
  line in the mail report.

| Level | XP to next | Cumulative XP to reach | Stat points gained on reaching | Gold reward |
|---:|---:|---:|---:|---:|
| 1 | 100 | 0 | (start: 5) | – |
| 2 | 320 | 100 | 5 | 40 |
| 3 | 640 | 420 | 5 | 60 |
| 4 | 1,060 | 1,060 | 5 | 80 |
| 5 | 1,600 | 2,120 | **7** | 100 |
| 6 | 2,240 | 3,720 | 5 | 120 |
| 7 | 3,020 | 5,960 | 5 | 140 |
| 8 | 3,920 | 8,980 | 5 | 160 |
| 9 | 4,970 | 12,900 | 5 | 180 |
| 10 | 6,180 | 17,870 | **7** | 200 |

Total points spent by level 10: 5 + 7×5 + 2×7 = **54**, so total stats = 25 base + 54 = 79.

### 5.3 Monster XP and level difference

Base monster XP ≈ `10 · L^1.5` (×3 for bosses) **[T]**, already filled in on the monsters table.
The XP actually awarded per kill:

```
xp = round( monster.xp · clamp(1 + 0.10 · (monster.level − char.level), 0.20, 1.50) )
```

Farming far below your level pays little. Punching above your level pays up to +50%.
An XP bonus (`xp_mult`, §3.2.1) multiplies each won encounter's total, rounded half up, before the
clear bonus and the defeat penalty. Arena XP is not multiplied.
**Pacing target:** roughly 2 at-level trips per level early on, a few more by level 10, and longer
after that as the 5% compound pulls ahead of monster XP (`10 · L^1.5`). At 4 runs/day, level 10 is
still about a week of passive play.

---

## 6. Loot

### 6.1 When loot happens

| Source | Chance | Table | Notes |
|---|---|---|---|
| Normal monster kill | `min(0.95, drop_chance · (1 + 0.005·LUK))` | monster's `loot_table_id` | one roll, generated during the trip |
| Boss kill | 100% | boss's table | **min rarity uncommon**, luck factor +0.5, generated during the trip |
| Clear chest | 100% on `cleared` | `places.chest_loot_table_id` | a **sealed box**; contents are rolled when it is opened |

A kill roll picks one **entry** by weight (`nothing` entries allowed). An item entry then goes through
**rarity → item level → base scaling → affixes → name → value**. The battle log records a `loot` event
on that kill, so the trip replay shows the drop when the monster falls.

**Clear chest.** A cleared trip inserts one `loot_boxes` row and does not roll the gear yet. The box
stores a seed (drawn from the trip RNG), the luck factor at that moment
(`luck + place.rarity_bonus + 0.25`), the place's `rec_level`, and a roll count:

```
rolls = min(chest_rolls_cap, chest_rolls_base + floor((rec_level − 1) / chest_rolls_every))
```

With the starting numbers that is **2** rolls through recommended level 10, **3** through 20, and **4**
after that. Each roll is one weighted entry from the place's chest table (a `nothing` entry skips that
roll). Rarity, item level, and affixes use the same rules as a kill drop, with the snapshotted luck
factor. Opening does not care what the character looks like later.

Humans open a box from the Pack page (`POST /pack/chests/{id}/open`). Bots open every sealed box at the
start of their decision step, before they equip or shop, using `Rng::chest(seed)`. Either way the items
are written then, and a full pack auto-sells the same way as a kill drop (§6.6). Defeat and retreat
grant no chest. The same seed always yields the same contents.

### 6.2 Rarity tiers

| Tier (i) | Rarity | Base weight **[T]** | Base chance | Affixes | Base stat mult | Value mult | Color |
|---:|---|---:|---:|---:|---:|---:|---|
| 0 | Common | 600 | 60.0% | 0 | ×1.00 | ×1 | grey |
| 1 | Uncommon | 250 | 25.0% | 1 | ×1.10 | ×2 | green |
| 2 | Rare | 110 | 11.0% | 2 | ×1.20 | ×4 | blue |
| 3 | Epic | 35 | 3.5% | 3 | ×1.35 | ×10 | purple |
| 4 | Legendary | 5 | 0.5% | 4 (rolled high) | ×1.50 | ×30 | orange |

**Luck influence:**

```
luck_factor = 0.01·LUK + G(magic_find_pct)/100 + place.rarity_bonus (+0.5 boss, +0.25 chest)
w_i' = w_i · (1 + luck_factor · i)        for i >= 1   (common unchanged)
pick tier by w' (renormalised); for bosses, a 'common' result becomes 'uncommon'
```

Example: LUK 10, no magic find, Sunken Crypt (bonus 0.10), so factor = 0.20. Weights become
600 / 300 / 154 / 56 / 9, and legendary goes from 0.50% to **0.80%**.

### 6.3 Item level and base scaling

```
ilvl  = max(template.req_level, source_level + randint(−1, +1))   # monster level, or place.rec_level for chests
scale = rarity_mult · (1 + min(0.40, 0.04 · (ilvl − template.req_level)))
power = round(template.base_power · scale);  armor and mres likewise
```

The instance's equip requirement stays at the template `req_level` (ilvl only affects power, which
keeps equip rules simple).

### 6.4 Affix rolling rules

1. Number of affixes comes from the rarity tier (0–4). Luck shifts which tier you roll, so it changes
   how many mods an item gets. Place level changes item level (which mods are legal, and how strong
   they roll) and, for a chest, how many items the box contains. It does not add mods on its own.
2. **Eligible pool:** affixes whose `slot_groups` contain the item's group (weapon → `weapon`;
   offhand → `offhand`; head/chest/hands/feet → `armor`; ring/amulet → `jewelry`) and whose `min_ilvl <= ilvl`.
3. Constraints: **max 2 prefixes and 2 suffixes**, **no two affixes with the same stat_key**, and an affix
   cannot repeat the item's implicit stat key.
4. Pick by `weight` without replacement, filtering out newly invalid options after each pick. If the pool
   runs dry, the item simply has fewer affixes.
5. **Value:** `lo = value_min_base + value_per_ilvl·(ilvl−1)`, `hi = value_max_base + value_per_ilvl·(ilvl−1)`;
   `value = round(uniform(lo, hi))`, minimum 1. **Legendary:** roll twice and keep the higher.
6. **Name.** Common through epic: `[prefix] <template name> [suffix]`, using the highest-value prefix and
   suffix if more than one. Example: "Keen Worn Dagger of the Fox".
   **Legendary** replaces that string. `legendary_names` holds three kinds of words, and the roll combines them:
   - a **base** for this `item_type` and `slot` (a sword is an Edge or a Blade; mail on the chest is a Hauberk);
   - an **epithet** for the strongest positive stat on the item, affix or implicit, highest value first
     (life is `hp`, so a sword with life becomes "Leeching Edge" or "Edge of the Leech");
   - a **unique** (Excalibur, Ragnarok, and the rest of that short list) that replaces the whole name when
     `item_type`, `slot`, and `min_ilvl` fit. The chance is `legendary_unique_weight` against
     `legendary_forged_weight` (25 vs 100): about one legendary in five that *could* be a unique takes
     that name. Below the unique's item level, every legendary of that type is a combined name.
   The chosen string is stored on `item_instances.display_name` and does not change later. The row is still
   that template: power, slot, and affixes come from the roll. The UI still marks legendary with a ★.
7. **Sell value:** `round(template.base_value · value_mult · (1 + 0.05·ilvl))`.

### 6.5 Gold drops

```
gold_per_kill = round( randint(gold_min, gold_max) · place.gold_mult · gold_find_mult )
```

Gold pays for the shop (§7.7), respec, and the forge (§6.9). The leaderboard shows it too.

### 6.6 Inventory limits

- Capacity **40** items **[T]** (equipped items excluded).
- If a drop would exceed capacity, the lowest-sell-value *unequipped common* item is auto-sold. If there
  is none, the new drop is auto-sold. Either way the mail says so, and the sale is written to
  `shop_transactions` (actor `sim_autosell`). Nothing is silently deleted.

### 6.7 Materials

Materials are stacks, not gear. They do not use inventory slots. A kill rolls each material linked to
the place (`place_materials`) after the gear roll, using the trip RNG. Chances live in `balance.json`
under `growth.drops`, keyed by kind. A boss multiplies the chance by `boss_mult` (capped at 0.95).

| Kind | Examples | Where | Used for |
|---|---|---|---|
| `dust` | Spark Dust | every combat place | skill ranks 1–2 |
| `shard` | Rune Shard | every combat place, rarer | skill ranks 3–4 |
| `essence` | Verdant Sap, Grave Salt, Sovereign Ember, … | **one place each** | skill rank 5 (any essence works) |

Defeat loses the materials found on that trip, the same way it loses gear. Retreat and a clear keep them.
The mail lists each stack.

Going back to a low place is allowed (§4.0). XP there is still poor (§5.3). The reason to return is the
essence that only that place drops.

### 6.8 Skills, the horcrux, and power level

Two upgrades. Numbers are in `balance.json` → `growth`.

**Skills.** Three skills, ranks 0–5: Keen Edge (attack), Iron Hide (defense and magic res), Fortune
(crit and magic find). The bonus is `per-rank value × rank`, added inside `Stats::derived` before caps,
injury, and buffs. Rank 1–2 cost Spark Dust, 3–4 cost Rune Shards, rank 5 costs one essence from any
place, plus gold. A human trains from the hero page (`POST /character/skills/{code}`).

**Horcrux.** One per character (`character_horcrux`). Feeding an unequipped item destroys it
(`status = 'destroyed'`) and adds progress from its rarity and item level. Thresholds raise the tier
(0–5). Each tier adds HP, attack, and defense. A full horcrux refuses more gear.
`POST /character/horcrux`.

**Power level** is a label, not a combat stat:

```
power = power_per_level × level + power_per_skill_rank × (sum of skill ranks) + power_per_horcrux_tier × tier
      + Σ premium_items.power of the active premium pieces (§16)
```

The header, the hero page, and the leaderboard show it. Combat uses the skill, horcrux, and premium bonuses, not the power number.

### 6.9 The forge

Two unequipped pieces of the **same template slot** (two weapons, two helms, two rings) go into the forge.
The attempt always spends gold and consumes both pieces. There are three results:

| Result | What you get |
|---|---|
| Crack | Nothing. Both pieces are destroyed. |
| Yield | A new piece of that slot, at the **better** input's rarity. Item level is the average of the two, rounded half up. |
| Sing | The same, one rarity tier higher (legendary stays legendary) and `masterpiece_ilvl_bonus` item levels higher. Legendary affixes still roll twice and keep the higher value. |

The new piece uses a template of that slot whose `req_level` is at most the result's item level. Templates closer to that
level are more likely (`template_near / (1 + distance)`). If none qualify, the lowest-level template of the slot is used.
Rarity, affixes, and the name use the same rules as a drop (§6.2–§6.4). `source = 'craft'`.

```
gold = round((base_gold + gold_per_ilvl · max(ilvl)) · rarity_gold_mult[better rarity])
quality = lower rarity tier index (common 0 … legendary 4)
luck = min(luck_cap, luck_per_luk · LUK)          # the LUK stat only; magic find does not move the forge
fail        = fail_weight · max(fail_floor, 1 − quality · fail_cut_per_tier)
craft       = craft_weight · (1 + luck · craft_luck)
masterpiece = masterpiece_weight · (1 + quality · masterpiece_per_tier) · (1 + luck · masterpiece_luck)
```

Starting numbers (`balance.json` → `forge`): two common level-1 pieces cost **23** gold and crack **50%** / yield **42%** /
sing **8%** at LUK 0. Two epics are much more likely to sing, and a short purse or a slot mismatch refuses before any
roll. Equipped gear has to come off first. The same seed replays the same piece.

A human strikes from the hero page's Forge section (`/character?tab=forge`, `POST /pack/forge`). Clicking an empty slot
lists the spare pieces that fit; once one slot is filled, the other only offers the same slot. The odds and the gold cost on that panel are computed by
`Forge::quote`; the page only displays them. The strike is confirmed in the game's own dialog (`resources/js/lib/dialog.ts`,
which replaces every `window.confirm` on the site), and the result comes back as the `forged` flash prop (outcome, gold, and the
new piece) that plays a short hammer swing before revealing what came out. The gold line is a `shop_transactions` row with
`action='craft'`. Bots do not forge. The roll's seed is drawn in `ForgeService`, not in `app/Game`.

---

## 7. Starter Content and Shop

> Every number in this section is **[T] starting balance**. Monster stats were drafted from
> `HP=(20+12L)·m_hp`, `ATK=(5+2.5L)·m_atk`, `DEF=(1+1.2L)·m_def`, `MRES=(1+L)·m_mres`,
> `SPD=8+L+mod`, `XP≈10·L^1.5` (×3 boss), `gold=2L–4L` (×4 boss), then hand-adjusted.
> This formula is for authoring only; the DB stores final values.

### 7.1 Places

| # | Code | Name | Type | **Min lvl** (hard) | **Recommended** | **Max lvl** (hard) | Bot target | Encounters | Boss (chance) | Rarity bonus | Gold × | Chest table |
|---|---|---|---|---:|---|---:|---:|---|---|---:|---:|---|
| 0 | `town` | Lanternford (Town) | rest | 1 | – | – | – | 0 | – | – | – | – |
| 1 | `greenwood` | Greenwood Forest | forest | **1** | **Lv 1–3** | **6** | 2 | 3–4 | Thornback Boar (30%) | 0.00 | 1.0 | lt_forest |
| 2 | `copper_mine` | Old Copper Mine | mine | **2** | **Lv 3–6** | **9** | 4 | 4–5 | Kobold Foreman (30%) | 0.05 | 1.3 | lt_mine |
| 3 | `sunken_crypt` | Sunken Crypt | dungeon | **5** | **Lv 6–9** | – | 8 | 4–6 | Bone Lord (40%) | 0.10 | 1.1 | lt_crypt |
| 4 | `frostfang` | Frostfang Peaks | mountain | **8** | **Lv 9–12** | – | 11 | 5–6 | Yeti Chieftain (35%) | 0.10 | 1.0 | lt_peaks |
| 5 | `obsidian_depths` | Obsidian Depths | cave | **11** | **Lv 12–16** | – | 14 | 5–7 | Ember Drake (40%) | 0.20 | 1.2 | lt_depths |

Places belong to a **region** (a map). The website shows one region at a time. Coordinates are percentages on that region's map, not a shared world map.

| Code | Name | Places |
|---|---|---|
| `wilds` | The Wilds | Town, Greenwood, Copper Mine, Sunken Crypt, Frostfang, Obsidian Depths (Lv 1–16) |
| `salt_marches` | The Salt Marches | Brinefen, Wrecker's Cove, Tidevault, Saltglass Spire, The Sunken Court (Lv 14–25) |
| `ashen_crown` | The Ashen Crown | Cinder Wastes, Fallen Citadel, Ash Monastery, The Black Stair, Crown of Embers (Lv 24–30) |

**The Salt Marches**

| # | Code | Name | Type | **Min lvl** | **Recommended** | **Max lvl** | Bot target | Encounters | Boss (chance) | Rarity bonus | Gold × | Chest table |
|---|---|---|---|---:|---|---:|---:|---|---|---:|---:|---|
| 6 | `brinefen` | Brinefen | marsh | **13** | **Lv 14–17** | – | 15 | 5–6 | Brine Hag (35%) | 0.12 | 1.1 | lt_brinefen |
| 7 | `wreckers_cove` | Wrecker's Cove | coast | **15** | **Lv 16–19** | – | 17 | 5–7 | Drowned Captain (35%) | 0.15 | 1.25 | lt_cove |
| 8 | `tidevault` | Tidevault | dungeon | **17** | **Lv 18–21** | – | 19 | 5–7 | Tide Warden (40%) | 0.18 | 1.15 | lt_tidevault |
| 9 | `saltglass_spire` | Saltglass Spire | ruins | **19** | **Lv 20–23** | – | 21 | 6–7 | Glass Siren (40%) | 0.20 | 1.20 | lt_spire |
| 10 | `sunken_court` | The Sunken Court | dungeon | **21** | **Lv 22–25** | – | 23 | 6–8 | Queen of Brine (45%) | 0.25 | 1.40 | lt_court |

**The Ashen Crown** (the level cap is 30)

| # | Code | Name | Type | **Min lvl** | **Recommended** | **Max lvl** | Bot target | Encounters | Boss (chance) | Rarity bonus | Gold × | Chest table |
|---|---|---|---|---:|---|---:|---:|---|---|---:|---:|---|
| 11 | `cinder_wastes` | Cinder Wastes | cave | **23** | **Lv 24–26** | – | 25 | 5–7 | Cinder Matron (35%) | 0.20 | 1.15 | lt_wastes |
| 12 | `fallen_citadel` | Fallen Citadel | ruins | **24** | **Lv 25–27** | – | 26 | 6–7 | Castellan of Ash (40%) | 0.22 | 1.25 | lt_citadel |
| 13 | `ash_monastery` | Ash Monastery | temple | **26** | **Lv 26–28** | – | 27 | 6–8 | Abbot of Cinders (40%) | 0.25 | 1.20 | lt_monastery |
| 14 | `black_stair` | The Black Stair | mountain | **27** | **Lv 27–29** | – | 28 | 6–8 | Stair Warden (40%) | 0.25 | 1.30 | lt_stair |
| 15 | `crown_of_embers` | Crown of Embers | cave | **28** | **Lv 29–30** | – | 30 | 7–8 | Ashen Sovereign (45%) | 0.30 | 1.50 | lt_crown |

The two starter areas are capped (Greenwood at Lv 6, Copper Mine at Lv 9), so high-level characters can't
farm them for easy loot. Min levels sit 1–2 below the recommended range, so a bold character can try a
place early. The map shows all three values, e.g. *"Sunken Crypt · Lv 6–9 · requires Lv 5"*.

**Generated regions.** The three maps above are authored. At the start of `delve:run` (not on `--resume`,
and rolled back with a `--dry-run`), if any active character's level is at least the highest
`rec_max_level` in the world, the simulation writes one new region before the trips. It keeps going,
up to `world.max_regions_per_run`, while that is still true. The new band overlaps the previous end by
`world.overlap` levels. Each map has `world.places_per_region` places. The first four stay a narrow
`world.band_width` band. The last place is a dungeon `world.dungeon_band_width` levels wide (11, so a
floor of 40 runs through a boss at 50). Inside every place the skirmishers sit on `rec_min`, the casters
a third of the way up, the brutes halfway, and the boss is always `rec_max`. The bot target (`rec_level`)
for that dungeon is the boss's level, so rivals aim to finish it at the top of the band.

Monster stats still start from the §7 authoring formula (`world.monster` and `world.roles`). A monster
above the place floor is then multiplied by depth pressure: trash uses
`1 + depth_pressure · (level − rec_min)`, and the boss uses the smaller `boss_depth_pressure`, so a
mid-band brute outfights a character who just walked in, and the boss stays the hardest fight in the
room. A clear at the bottom of a wide dungeon is a bad bet unless the gear is ahead of the level.
Each place has two loot tables. Trash rolls gear at or below the highest trash level. The boss and the
clear chest roll the place's better gear, and the top templates (required level = the boss) are not on
the trash table. Gear templates are not sold in town. Each place also drops one essence plus Spark Dust
and Rune Shard. Names come from a theme
(`App\Game\World\ThemeCatalog`): one adjective and noun for the region, then places, creatures, and the
end dungeon in that voice. The roll is `Rng::world`, seeded by the frontier place code and the new
`sort_order`, so the same frontier always opens the same map. A generated region code is `gen{n}_{theme}`.
The map page already lists every `regions` row. While that page is open, the whole interface uses
that map's palette (`html[data-theme]`, tokens in `resources/css/themes.css`): `wilds` is a daylight
wood, `salt_marches` a misty coast, and `ashen_crown` a dark ember cave. A generated region
`gen{n}_{theme}` uses the palette for that ThemeCatalog code (`gale`, `gilt`, `mycel`, `void`, `coil`,
`brass`, `dune`, `crimson`). Any other region cycles the three authored palettes. Everywhere else —
town, inventory, account, the sign-in screen — stays on the sunny meadow default. The painted
parchment follows the same palette.

**place_monsters (weight, group size):**

| Place | Monsters |
|---|---|
| Greenwood | Giant Rat (50, 1–2) · Forest Wolf (35, 1–2) · Goblin Scout (15, 1–2) |
| Copper Mine | Cave Bat (35, 1–3) · Kobold Miner (50, 1–2) · Goblin Scout (15, 1–2) |
| Sunken Crypt | Skeleton Warrior (45, 1–2) · Crypt Wraith (35, 1–2) · Cave Bat (20, 2–3) |
| Frostfang | Frost Wolf (50, 1–3) · Ice Witch (35, 1–1) · Skeleton Warrior (15, 2–3) |
| Obsidian Depths | Magma Imp (45, 1–3) · Obsidian Golem (40, 1–2) · Ice Witch (15, 1–2) |
| Brinefen | Marsh Leech (50, 1–3) · Fen Lurker (35, 1–2) · Salt Wretch (15, 1–2) |
| Wrecker's Cove | Salt Wretch (40, 1–2) · Tide Reaver (40, 1–2) · Vault Eel (20, 1–2) |
| Tidevault | Vault Eel (35, 1–3) · Tide Priest (45, 1–2) · Glass Shardling (20, 1–2) |
| Saltglass Spire | Glass Shardling (40, 1–3) · Spire Sentinel (40, 1–1) · Court Guard (20, 1–2) |
| The Sunken Court | Spire Sentinel (20, 1–1) · Court Guard (45, 1–2) · Brine Oracle (35, 1–2) |
| Cinder Wastes | Ash Hound (50, 1–3) · Cinder Cultist (35, 1–2) · Citadel Wraith (15, 1–2) |
| Fallen Citadel | Cinder Cultist (25, 1–2) · Ash Knight (45, 1–2) · Cinder Monk (30, 1–2) |
| Ash Monastery | Cinder Monk (40, 1–2) · Ash Cantor (40, 1–2) · Ember Acolyte (20, 1–2) |
| The Black Stair | Stair Gargoyle (40, 1–1) · Ember Acolyte (35, 1–2) · Sovereign Guard (25, 1–2) |
| Crown of Embers | Sovereign Guard (55, 1–2) · Crown Phoenix (45, 1–2) |

### 7.2 Monsters

The Wilds has 16 (12 regular + 4 bosses). The Salt Marches and the Ashen Crown add 30 more (20 regular + 10 bosses). Stats follow the authoring formula in the §7 header. Full rows for the later monsters live in `database/seeders/sql/012_later_maps.sql`.

**The Wilds (16)**

| Code | Name | Lvl | Boss | HP | ATK | DEF | MRES | SPD | Crit% | Dodge% | Type | XP | Gold | Drop% | Loot table |
|---|---|---:|:-:|---:|---:|---:|---:|---:|---:|---:|---|---:|---|---:|---|
| giant_rat | Giant Rat | 1 | | 26 | 7 | 2 | 2 | 9 | 5 | 5 | phys | 10 | 2–4 | 12 | lt_forest |
| forest_wolf | Forest Wolf | 2 | | 44 | 10 | 3 | 2 | 14 | 8 | 8 | phys | 28 | 4–8 | 15 | lt_forest |
| goblin_scout | Goblin Scout | 3 | | 56 | 12 | 5 | 3 | 13 | 6 | 10 | phys | 52 | 6–12 | 20 | lt_forest |
| thornback_boar | Thornback Boar | 4 | ✔ | 170 | 20 | 9 | 5 | 10 | 5 | 0 | phys | 240 | 32–64 | 100 | lt_forest |
| cave_bat | Cave Bat | 3 | | 39 | 11 | 3 | 4 | 17 | 5 | 20 | phys | 52 | 6–12 | 12 | lt_mine |
| kobold_miner | Kobold Miner | 5 | | 80 | 16 | 9 | 5 | 13 | 5 | 5 | phys | 112 | 10–20 | 18 | lt_mine |
| kobold_foreman | Kobold Foreman | 7 | ✔ | 260 | 29 | 14 | 8 | 15 | 8 | 5 | phys | 555 | 56–112 | 100 | lt_mine |
| skeleton_warrior | Skeleton Warrior | 7 | | 104 | 25 | 13 | 5 | 15 | 5 | 3 | phys | 185 | 14–28 | 15 | lt_crypt |
| crypt_wraith | Crypt Wraith | 8 | | 93 | 28 | 5 | 14 | 19 | 5 | 15 | magic | 226 | 16–32 | 18 | lt_crypt |
| bone_lord | Bone Lord | 10 | ✔ | 364 | 39 | 20 | 13 | 19 | 8 | 5 | phys | 948 | 80–160 | 100 | lt_crypt |
| frost_wolf | Frost Wolf | 10 | | 140 | 30 | 12 | 11 | 23 | 10 | 10 | phys | 316 | 20–40 | 15 | lt_peaks |
| ice_witch | Ice Witch | 11 | | 122 | 39 | 9 | 18 | 21 | 6 | 8 | magic | 365 | 22–44 | 20 | lt_peaks |
| yeti_chieftain | Yeti Chieftain | 13 | ✔ | 493 | 51 | 23 | 17 | 21 | 8 | 3 | phys | 1407 | 104–208 | 100 | lt_peaks |
| magma_imp | Magma Imp | 12 | | 131 | 40 | 11 | 18 | 25 | 6 | 15 | magic | 416 | 24–48 | 15 | lt_depths |
| obsidian_golem | Obsidian Golem | 14 | | 263 | 40 | 36 | 12 | 18 | 3 | 0 | phys | 524 | 28–56 | 20 | lt_depths |
| ember_drake | Ember Drake | 16 | ✔ | 636 | 63 | 30 | 24 | 26 | 10 | 5 | magic | 1920 | 128–256 | 100 | lt_depths |

### 7.3 Item templates (23, so every slot has a level-1 item)

| Code | Name | Slot | Type | Scaling | 2H | Power | Armor | MRes | Implicit | Req | Value |
|---|---|---|---|---|:-:|---:|---:|---:|---|---:|---:|
| rusty_sword | Rusty Sword | weapon | sword | STR | | 4 | | | – | 1 | 5 |
| worn_dagger | Worn Dagger | weapon | dagger | DEX | | 3 | | | crit_pct +3 | 1 | 5 |
| oak_staff | Oak Staff | weapon | staff | INT | ✔ | 6 | | 1 | – | 1 | 5 |
| iron_longsword | Iron Longsword | weapon | sword | STR | | 9 | | | – | 4 | 25 |
| hunters_shortbow | Hunter's Shortbow | weapon | bow | DEX | ✔ | 10 | | | speed +2 | 4 | 25 |
| apprentice_wand | Apprentice Wand | weapon | wand | INT | | 8 | | 2 | – | 4 | 25 |
| war_axe | War Axe | weapon | axe | STR | ✔ | 18 | | | crit_dmg_pct +10 | 8 | 70 |
| runed_staff | Runed Staff | weapon | staff | INT | ✔ | 17 | | 4 | int +2 | 8 | 70 |
| shadowfang | Shadowfang Dagger | weapon | dagger | DEX | | 14 | | | crit_pct +5 | 10 | 110 |
| frostbite_blade | Frostbite Blade | weapon | sword | STR | | 20 | | 3 | – | 12 | 160 |
| ember_staff | Ember Staff | weapon | staff | INT | ✔ | 27 | | 6 | int +3 | 13 | 200 |
| wooden_buckler | Wooden Buckler | offhand | shield | | | | 3 | 0 | – | 1 | 6 |
| iron_kite_shield | Iron Kite Shield | offhand | shield | | | | 8 | 2 | – | 6 | 45 |
| cloth_tunic | Cloth Tunic | chest | cloth | | | | 2 | 1 | – | 1 | 3 |
| leather_jerkin | Leather Jerkin | chest | leather | | | | 5 | 1 | dex +1 | 3 | 20 |
| chainmail | Chainmail | chest | mail | | | | 10 | 2 | speed −1 | 6 | 50 |
| drakescale_mail | Drakescale Mail | chest | mail | | | | 18 | 8 | – | 13 | 220 |
| leather_cap | Leather Cap | head | leather | | | | 2 | 0 | – | 1 | 4 |
| iron_helm | Iron Helm | head | mail | | | | 5 | 1 | – | 5 | 35 |
| soft_boots | Soft Boots | feet | cloth | | | | 1 | 0 | speed +1 | 1 | 4 |
| leather_gloves | Leather Gloves | hands | leather | | | | 1 | 0 | crit_pct +1 | 2 | 8 |
| copper_ring | Copper Ring | ring | jewelry | | | | | | luk +2 | 1 | 10 |
| amulet_of_vigor | Amulet of Vigor | amulet | jewelry | | | | | | vit +2, hp +10 | 5 | 40 |

*Rules:* equipping a two-handed weapon empties the offhand slot. Rings fit `ring1` or `ring2`.
`req_level` is a hard equip requirement. Admins create and edit templates at `/admin` (live
`item_templates` rows). A `migrate:fresh --seed` restores the table from this seed list.

**Starter kit** (source `starter`, common): Cloth Tunic, Soft Boots, and one weapon matching the build
(Rusty Sword / Worn Dagger / Oak Staff), plus **2 Minor Healing Potions** (1 on the belt) and **50 gold**
**[T]**. A new human picks the weapon on the sign-up form. Bots get the weapon matching their
`preferred_scaling`.

**Item art.** `App\Game\ItemArt` chooses a drawing from `resources/item-art/catalog.json`. The inputs
are the template slot, item type, two-handed flag, and name keywords on the code and template name (a
bow stays a bow; a two-handed sword, axe, or maul uses a two-handed drawing). When a family has several
icons, the file is a stable hash of the template code (otherwise the template name) into a window of
that list. The list runs plain to fancy, and a higher rarity shifts the window toward the fancy end, so
the same code always looks the same and a legendary of that code is never plainer than the common.
Changing the display name (affixes, legendary titles) does not change the icon. An `item_templates.icon`
value is used only when it names a catalog file. Empty slots fall back by slot; anything else falls
back to a backpack. Painted icons keep their colors; the rarity color is a glow and a frame. Two supply
drawings (smoke, bandage) stay SVGs and are tinted with the rarity color. Files live in
`public/item-art/` (128×128 WebP, plus those two SVGs) and are served at `/item-art/`. Sources are Flare
weapon icons 2, Flare armor by equipment slot, and OSARE weapon icons (CC0; Clint Bellanger, Justin
Nichol, Blarumyrran), Kit's 2D RPG item icons (CC0), Ravenmore's Fantasy Icon Pack 2.0 and Scrittl's
painterly weapon and armor icons (CC BY 3.0), and Lorc's game-icons.net drawings for the two SVGs
(CC BY 3.0). Do not put them under `/icons/`: Ubuntu's Apache aliases that path to
`/usr/share/apache2/icons/`, so `public/icons/` is never reached. The footer and `/credits` name the
authors. The React UI displays the filename; it does not re-implement the keyword rules.

### 7.4 Affixes (15)

| Code | Name | Kind | Stat | Groups | Min ilvl | Weight | Min base | Max base | Per ilvl |
|---|---|---|---|---|---:|---:|---:|---:|---:|
| sturdy | Sturdy | prefix | armor | armor, offhand | 1 | 100 | 1 | 3 | 0.5 |
| heavy | Heavy | prefix | attack | weapon | 1 | 100 | 1 | 3 | 0.6 |
| vital | Vital | prefix | hp | armor, offhand, jewelry | 1 | 100 | 5 | 10 | 2.0 |
| keen | Keen | prefix | crit_pct | weapon, jewelry | 2 | 70 | 1 | 3 | 0.15 |
| swift | Swift | prefix | speed | armor, jewelry | 2 | 70 | 1 | 2 | 0.15 |
| warded | Warded | prefix | mres | armor, offhand, jewelry | 1 | 80 | 1 | 3 | 0.4 |
| brutal | Brutal | prefix | crit_dmg_pct | weapon | 5 | 50 | 5 | 10 | 1.0 |
| of_bear | of the Bear | suffix | str | all | 1 | 100 | 1 | 2 | 0.25 |
| of_fox | of the Fox | suffix | dex | all | 1 | 100 | 1 | 2 | 0.25 |
| of_owl | of the Owl | suffix | int | all | 1 | 100 | 1 | 2 | 0.25 |
| of_ox | of the Ox | suffix | vit | all | 1 | 100 | 1 | 2 | 0.25 |
| of_fortune | of Fortune | suffix | luk | armor, jewelry | 1 | 70 | 1 | 3 | 0.25 |
| of_evasion | of Evasion | suffix | dodge_pct | armor, jewelry | 3 | 60 | 1 | 2 | 0.10 |
| of_plenty | of Plenty | suffix | gold_find_pct | jewelry | 1 | 50 | 5 | 10 | 0.5 |
| of_seeker | of the Seeker | suffix | magic_find_pct | armor, jewelry | 4 | 40 | 3 | 6 | 0.3 |

### 7.4.1 Legendary names

Seeded in `legendary_names` (`040_legendary_names.sql`). Ordinary drops keep the affix title above,
including "of the Owl". Only `rarity = legendary` reads this table.

| Kind | Matches | Example |
|---|---|---|
| base | `item_type` + `slot` | sword → Edge, Blade, Falchion; chest mail → Hauberk; ring → Band |
| epithet | `stat_key` of the strongest positive affix or implicit | `hp` → Leeching, Bloodbound, of the Leech |
| unique | `item_type` + `slot`, and `min_ilvl` | Excalibur (sword, 12), Ragnarok (axe, 20), Carnwennan, Laevateinn, Failnaught, Starfall, Ancile, Tarnhelm, Worldheart, Ouroboros |

A new item template needs a `base` row for its slot and item type at or below its `req_level`. A new
affix stat key needs at least one epithet. `delve:check-content` reports a miss.

### 7.5 Loot tables (entry weights)

| Table | Entries (template: weight) |
|---|---|
| **lt_forest** | nothing 10 · rusty_sword 10 · worn_dagger 10 · oak_staff 10 · wooden_buckler 12 · cloth_tunic 12 · leather_cap 14 · soft_boots 14 · leather_gloves 10 · copper_ring 6 · leather_jerkin 2 |
| **lt_mine** | nothing 10 · iron_longsword 10 · hunters_shortbow 10 · apprentice_wand 10 · leather_jerkin 14 · leather_gloves 12 · leather_cap 8 · iron_helm 8 · copper_ring 10 · wooden_buckler 8 · iron_kite_shield 4 · amulet_of_vigor 3 |
| **lt_crypt** | nothing 8 · iron_longsword 6 · hunters_shortbow 6 · apprentice_wand 6 · war_axe 8 · runed_staff 8 · shadowfang 3 · chainmail 12 · iron_kite_shield 10 · iron_helm 12 · amulet_of_vigor 8 · copper_ring 8 |
| **lt_peaks** | nothing 6 · war_axe 10 · runed_staff 10 · shadowfang 10 · frostbite_blade 6 · chainmail 10 · iron_kite_shield 10 · iron_helm 10 · amulet_of_vigor 10 · copper_ring 6 · drakescale_mail 2 |
| **lt_depths** | nothing 5 · frostbite_blade 10 · ember_staff 8 · shadowfang 10 · drakescale_mail 8 · iron_kite_shield 10 · amulet_of_vigor 10 · chainmail 6 · copper_ring 6 |

Bosses use their place's table (with the boss rules from §6.1). Items above the character's level can
drop; they wait in the inventory until the character can equip them. A later option is dedicated boss
tables with signature items.

### 7.6 Balance sanity targets (to confirm in M2 with the balance lab)

| Scenario | Expected **[T]** |
|---|---|
| Fresh L1 (build from §3.3) → Greenwood | clears or retreats ≥ 85% of trips; defeated ≤ 15% |
| L1 vs Thornback Boar | usually flees (boss is meant for L3–4) |
| L3 → Greenwood | clears ≥ 70% incl. boss |
| L5 with mine gear → Copper Mine | clears ~60%; the foreman usually forces a retreat until L6–7 |
| At-level trips per level-up | ~2 early, 3–4 around L10 |

### 7.7 Shop

A single always-open shop (the "Lanternford Market"), used from the website any time between runs and
by bots during their decision step. Everything is in `shop_listings`, so prices are data, not code.

**Rules**
- **Buy gear:** creates a **common**, affix-free `item_instances` row with `item_level = req_level` and
  `source='shop'`. Better gear comes only from loot. Gear listings are the level-1 to level-6 templates plus
  the Copper Ring. Each is buyable once `character.level >= listing.min_level` (the template's `req_level`).
- **Buy consumables:** add to `character_consumables.quantity`. Max stack **20** per consumable **[T]**.
- **Sell:** any owned, unequipped item sells for its `sell_value` (§6.4). Consumables sell for their
  `sell_price`. Equipped items must be unequipped first.
- **Stock:** unlimited in v1 (`stock = NULL`). Rotating or limited stock is a later option.
- **Atomicity:** every buy/sell is one transaction: lock the character row → check level, gold, and
  inventory space → move items → update `characters.gold` → insert into `shop_transactions`. Gold can
  never go negative (checked in code; `gold` is `UNSIGNED`, so a bug fails loudly).
- The shop refuses writes while a run is in progress (§10.3), like every other website POST.

**Gear prices** **[T]**: `price = round_to_5(base_value × 4 × (1 + 0.05 × req_level))`, minimum 10.
That's roughly 4× what the shop pays back for the same common item.

| Listing | Min lvl | Price | | Listing | Min lvl | Price |
|---|---:|---:|---|---|---:|---:|
| Rusty Sword | 1 | 20 | | Wooden Buckler | 1 | 25 |
| Worn Dagger | 1 | 20 | | Iron Kite Shield | 6 | 235 |
| Oak Staff | 1 | 20 | | Cloth Tunic | 1 | 15 |
| Iron Longsword | 4 | 120 | | Leather Jerkin | 3 | 90 |
| Hunter's Shortbow | 4 | 120 | | Chainmail | 6 | 260 |
| Apprentice Wand | 4 | 120 | | Leather Cap | 1 | 15 |
| Copper Ring | 1 | 40 | | Iron Helm | 5 | 175 |
| Leather Gloves | 2 | 35 | | Soft Boots | 1 | 15 |

**Consumables (`consumable_templates` + listings)** **[T]**:

| Code | Name | Effect | Trigger | Min lvl | Buy | Sell |
|---|---|---|---|---:|---:|---:|
| minor_healing_potion | Minor Healing Potion | heal 30% max HP | auto_low_hp | 1 | 25 | 6 |
| healing_potion | Healing Potion | heal 50% max HP | auto_low_hp | 6 | 80 | 20 |
| greater_healing_potion | Greater Healing Potion | heal 70% max HP | auto_low_hp | 11 | 200 | 50 |
| elixir_of_might | Elixir of Might | +15% attack for the trip | trip_start | 3 | 60 | 15 |
| stoneskin_tonic | Stoneskin Tonic | +15% defense & mres for the trip | trip_start | 3 | 60 | 15 |
| lucky_charm | Lucky Charm | +0.20 luck factor for the trip | trip_start | 5 | 100 | 25 |
| smoke_bomb | Smoke Bomb | +30% flee chance on one attempt | on_flee | 1 | 30 | 7 |
| bandage_kit | Bandage Kit | cures *Wounded* (not *Badly wounded*) | manual | 1 | 40 | 10 |

**Economy sanity check:** an at-level Greenwood trip yields ~20–40 gold (more with the boar). A
level-4 character in the Copper Mine earns ~100–150 gold per trip, so an Iron Longsword costs about
one good trip, and a potion refill about a quarter of one.

---

## 8. Bot AI

Bots are **ordinary characters without a login account** (`is_bot = TRUE`, `account_id = NULL`). Their
decisions are made by `app/Game/Bots/` at the start of every run. The code blocks in this section are
pseudocode; the PHP classes follow them step by step. Everything is rule-based, seeded
from the run seed, and logged to `bot_decisions`. There are no external AI/LLM calls and no network
access. There are six founder personalities. Each run has a `newcomers.spawn_chance` of birthing
one more, up to `newcomers.max_bots`, so the town grows slowly (§8.0).

### 8.0 New arrivals

At the start of `delve:run` (not on `--resume`, and rolled back with a `--dry-run`), if fewer than
`newcomers.max_bots` bots are active, the run rolls `newcomers.spawn_chance` (0.10, about one run in
ten). On a hit, one new bot is inserted before the bot phase. The roll is `Rng::arrival($seed, $activeBots)`.

| Roll | Rule |
|---|---|
| Birth | `spawn_chance`. A miss inserts nobody and sends no letter. |
| Personality | One of the six `bot_profiles`, chosen uniformly. |
| Name | A given name from `Newcomer`, and with probability `mononym_chance` (0.30) that is the whole name. Otherwise a surname is added. A surname already used in town is skipped until every surname has been used once. |
| Level | Level 1 with probability `fresh_chance`. Otherwise a level in `[highest − top_window, highest − 1]`. Never the current highest level. If the leader is still level 1, the newcomer is level 1. |
| Points and XP | Unspent points are the full pool for that level. `total_xp` is the XP required to reach it, so they rank behind anyone who actually earned that level. The bot phase spends the points on this same run. |
| Gear | Level 1 gets the ordinary starter kit. Above level 1, one item is rolled per kit slot (weapon matches their scaling; a two-handed weapon skips the offhand), `req_level` at most their level, and within `gear_lag` of it when such a template exists. |
| Letter | Every active human receives `mail_type='system'`, subject **A hero has been born**. Bot memory only reads `trip_report`. |
| Cap | **48**, not 1000. Every active bot delves on every run and enters every arena. A thousand of them would turn a manual run into a long batch and flood the bracket. Raise `newcomers.max_bots` if the town should be larger. |

### 8.1 Personalities (seed rows for `bot_profiles`)

| Code | Archetype | w STR/DEX/INT/VIT/LUK | Scaling | Risk | Retreat % | Rand | Rarity pref | Fail thr / avoid trips | Step-up (wins, HP%) |
|---|---|---|---|---:|---:|---:|---:|---|---|
| `bulwark` | tank | .30/.10/.00/.50/.10 | STR | 0 | 20 | .10 | 0.0 | 2 / 3 | 2, 50 |
| `shade` | rogue | .15/.50/.00/.15/.20 | DEX | +1 | 35 | .25 | 0.3 | 2 / 2 | 2, 60 |
| `arcanist` | mage | .00/.10/.60/.20/.10 | INT | 0 | 40 | .15 | 0.2 | 1 / 4 | 3, 65 |
| `berserker` | berserker | .60/.15/.00/.20/.05 | STR | +2 | 10 | .30 | 0.0 | 3 / 2 | 1, 40 |
| `ranger` | ranger | .10/.55/.00/.25/.10 | DEX | 0 | 30 | .15 | 0.1 | 2 / 3 | 2, 60 |
| `prospector` | gambler | .20/.20/.00/.25/.35 | STR | +1 | 30 | .35 | 1.0 | 2 / 2 | 2, 55 |

**Gear weights** (`gear_weights` JSON, keys: hp, attack, defense, mres, crit, dodge, speed, mf):

| Profile | hp | attack | defense | mres | crit | dodge | speed | mf |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| bulwark | 1.0 | 0.6 | 1.2 | 0.7 | 0.2 | 0.3 | 0.2 | 0.1 |
| shade | 0.5 | 1.0 | 0.4 | 0.3 | 1.0 | 0.9 | 0.6 | 0.3 |
| arcanist | 0.7 | 1.2 | 0.4 | 0.6 | 0.5 | 0.3 | 0.3 | 0.2 |
| berserker | 0.6 | 1.3 | 0.4 | 0.2 | 0.7 | 0.2 | 0.3 | 0.0 |
| ranger | 0.8 | 1.0 | 0.5 | 0.4 | 0.8 | 0.6 | 0.7 | 0.2 |
| prospector | 0.8 | 0.8 | 0.6 | 0.4 | 0.4 | 0.4 | 0.3 | 1.2 |

**Shop behaviour** **[T]**:

| Profile | Gold reserve % | Potion target | Buff preference | Min gain to buy gear | Rests when wounded? |
|---|---:|---:|---|---:|---|
| bulwark | 40 | 2 | stoneskin | 5% | yes |
| shade | 25 | 2 | might | 5% | no (buys a Bandage Kit) |
| arcanist | 35 | 3 | stoneskin | 4% | yes |
| berserker | 10 | 1 | might | 3% | no (buys a Bandage Kit) |
| ranger | 30 | 2 | none | 5% | yes |
| prospector | 20 | 2 | luck | 8% | no (buys a Bandage Kit) |

**Seed bots (v1):** Brakka the Wall (`bulwark`), Vex (`shade`), Old Minnow (`arcanist`),
Grul Redhand (`berserker`), Sable (`ranger`), Lucky Pim (`prospector`). They start like a new human:
level 1, starter kit matching their scaling stat, 2 Minor Healing Potions, 50 gold.

### 8.2 Bot phase (per bot, one transaction)

```
memory  = read_memory(bot)                          # 8.8: from own mail
allocate_stat_points(bot)                           # 8.3
equip_best_gear(bot)                                # 8.4: from current inventory
train_skills(bot)                                   # 6.8: one rank, lowest rank first, if gold and mats allow
feed_horcrux(bot)                                   # 6.8: up to bot_feed_per_run junk items, stop at the next tier
premium_step(bot)                                   # 16.5: wear best owned pieces, buy ≤ bot_buys_per_run (bots only)
sell_junk(bot)                                      # 8.5: via the shop ledger (fed items are already destroyed)
place   = choose_destination(bot, memory)           # 8.7: respects min_level; may farm an essence
shop_step(bot, place)                               # 8.6: bandage, gear upgrade, potions, buff; re-equip if bought
fill_belt(bot, place)                               # 8.6
cancel any pending order; insert trip_order(place, submitted_by='bot'); mark own mail is_read=1
insert bot_decisions(run_id, char_id, {points, equipped, sold, bought, belt, place, premium, reasons})
```

Bot RNG: `Rng::bot($runSeed, $characterId)` (§10.3). It is separate from the trip RNG, so bot
decisions don't shift combat rolls. Bots copy `profile.retreat_threshold_pct` into
`characters.retreat_threshold_pct` (and set `potion_threshold_pct = retreat + 15`), so combat has a
single code path for everyone.

A human can turn on **auto mode** on the hero page (`characters.auto_mode`, `auto_profile_id`). They
stay a human (`is_bot` stays false, they keep their account). At the start of the run they take the
same step as a bot, using the personality they picked: points, gear, skills, horcrux, junk sales,
destination, shop, belt, and sealed chests. Any pending order is replaced. The personality's retreat
and potion thresholds are copied onto the character for that run. Turning auto mode off leaves the
last personality selected but the next run waits for a normal order again. With **best map**
(`characters.auto_place = 'best'`) the destination step ignores the personality's risk and exploration
rolls. It previews each open place with the character's current stats, gear, skills, horcrux, injury,
and potions they already own, using the average combat roll. It takes the hardest place that preview
clears. A preview that ends in a defeat is skipped, because a defeat is what applies *Wounded*. If
some place is a clean clear, a place they would only retreat from is skipped too. If every open place
would defeat them, they rest in town. If they would survive but clear none on the preview, they take
the hardest place they still walk away from. The personality still
spends points, equips, sells, shops, and sets retreat and potion thresholds. Every unspent point is
spent before the trip, and any points the trip itself grants are spent again before the run finishes,
then the best gear is worn. This does not add a seventh
bot, and the arena is still opt-in.

### 8.3 Stat allocation

Spend points one at a time toward a target distribution proportional to the weights, with a small
chance of a random weighted pick.

```
def allocate_stat_points(char, prof, rng):
    weights = normalise({s: prof.w[s] for s in STATS})          # sums to 1
    spent   = {s: char.stat[s] - 5 for s in STATS}               # points already invested
    while char.unspent_points > 0:
        if max_hp(char) < 60 + 12 * char.level:                  # safety rule, all archetypes [T]
            pick = 'vit'
        elif rng.random() < prof.randomness * 0.3:               # personality quirk
            pick = weighted_choice(weights, rng)
        else:
            total   = sum(spent.values()) + 1
            deficit = {s: weights[s] * total - spent[s] for s in STATS}
            pick    = max(STATS, key=lambda s: (deficit[s], weights[s], rng.random()))
        char.stat[pick] += 1; spent[pick] += 1; char.unspent_points -= 1
```

### 8.4 Gear scoring and equipping

Score a whole **loadout** by the derived stats it produces, weighted by the profile. Attack depends on
the weapon's scaling stat, so a mage automatically rates swords poorly with no special-case code.

```
UNIT = {"hp": 10, "attack": 1, "defense": 1, "mres": 1, "crit": 1, "dodge": 1, "speed": 2, "mf": 5}   # [T]

def loadout_score(char, loadout, prof):
    d = derived_stats(char.base_stats, loadout)                  # the same function combat uses
    return sum(prof.gear_weights[k] * d[k] / UNIT[k] for k in UNIT)

def equip_best_gear(char, prof, rng):
    items   = [i for i in owned_items(char) if i.req_level <= char.level]
    current = dict(char.equipment)                               # slot -> item
    for _ in range(2):                                           # greedy coordinate ascent, 2 passes
        for slot in SLOTS:                                       # weapon, offhand, head, chest, hands, feet, ring1, ring2, amulet
            best, best_score = current.get(slot), loadout_score(char, current, prof)
            for item in candidates_for(slot, items, current):    # ring -> ring1/ring2; 2H clears offhand
                trial = with_item(current, slot, item)
                s = loadout_score(char, trial, prof)
                if s > best_score * 1.01:                        # 1% hysteresis avoids flip-flopping
                    best, best_score = item, s
            current = with_item(current, slot, best)
    apply_equipment(char, current)                               # writes character_equipment
```

Special cases: a 2H weapon is compared as *2H alone* vs *1H + best available offhand*. An item can't
be in two slots (`uq_equip_item`).

### 8.5 Selling junk

If `prof.sell_junk` and inventory ≥ 75% of capacity: sell (through the shop, `shop_transactions.actor='bot'`)
unequipped items that would not improve `loadout_score` in any slot, plus items whose
`req_level > level + 3`. Cheapest first, until inventory is at 50% of capacity. Surplus consumables
above 2× the potion target are also sold.

### 8.6 Shop step and belt

Runs after the destination is chosen, because the buff choice depends on where the bot is going. All
purchases respect `budget = gold − gold_reserve_pct% · gold` (recomputed after each purchase) and the
listing's `min_level`.

```
def shop_step(char, prof, place, shop, rng):
    budget = lambda: char.gold - ceil(char.gold * prof.gold_reserve_pct / 100)
    # 1. Injury: bold profiles pay to skip resting
    if char.injury_severity == 1 and not prof.rest_when_injured and budget() >= shop.price('bandage_kit'):
        buy_and_use(char, 'bandage_kit')                         # clears Wounded now
    # 2. One gear upgrade per run: best score gain per gold among affordable listings
    best = None
    for listing in shop.gear_listings(max_level=char.level):
        if listing.price > budget(): continue
        gain = loadout_score(char, best_loadout_with(char, listing.template), prof) / loadout_score(char, char.equipment, prof) - 1
        if gain * 100 >= prof.shop_min_gain_pct:
            value = gain / listing.price
            if best is None or value > best[0]: best = (value, listing)
    if best:
        buy(char, best[1]); equip_best_gear(char, prof, rng)
    # 3. Healing potions up to the target (best potion tier the bot's level allows)
    potion = best_healing_potion_for(char.level)                 # minor < healing < greater
    while count(char, potion) < prof.potion_target and budget() >= shop.price(potion):
        buy(char, potion)
    # 4. Buff for dangerous trips only: destination rec_level > own level, or the place has a boss
    if prof.buff_preference != 'none' and (place.rec_level > char.level or place.boss_monster_id):
        buff = {'might': 'elixir_of_might', 'stoneskin': 'stoneskin_tonic', 'luck': 'lucky_charm'}[prof.buff_preference]
        if level_ok(buff, char) and budget() >= shop.price(buff):
            buy(char, buff)

def fill_belt(char, prof, place):
    # BELT_SLOTS = 4: healing potions first (up to potion_target), then 1 buff, then a smoke bomb if owned
    ...
```

Humans do the same things by hand on the Market and Pack pages (belt editor).

### 8.7 Destination choice (respects level gates)

```
def choose_destination(char, prof, mem, places, rng):
    if char.injury_severity >= 2:                                return TOWN   # forced anyway
    if char.injury_severity == 1 and prof.rest_when_injured:     return TOWN   # (bold bots bought a bandage in 8.6)
    target = (char.level + prof.risk_offset + mem.step_up
              - (1 if mem.last_outcome in ('defeated', 'order_rejected') else 0))
    cands = [p for p in places
             if p.place_type != 'rest'
             and can_enter(char.level, p)[0]                     # HARD gates: min_level / max_level (4.0)
             and p.id not in mem.blocked]
    if not cands:
        return TOWN
    if rng.random() < prof.randomness * 0.15:                    # occasional exploration
        near = [p for p in cands if abs(p.rec_level - target) <= 3]
        if near:
            return rng.choice(near)
    def score(p):
        in_range = p.rec_min_level <= char.level <= p.rec_max_level
        return (- abs(p.rec_level - target)
                + (0.5 if in_range else 0)                       # mild pull toward the recommended range
                - 0.5 * mem.retreats[p.id]
                + 10 * prof.rarity_preference * p.rarity_bonus
                + rng.gauss(0, max(0.05, prof.randomness)))
    return max(cands, key=score)
```

**Best map** (`auto_place = 'best'`, humans in auto mode only) replaces the score above. The same gates,
injury rest, and blocked-place memory still apply. Each remaining place is previewed at the average
damage roll (`(variance_min + variance_max) / 2`, expected crit and dodge) for `encounter_max` fights.
Each fight uses the largest pack of the spawn that hurts the most, and the last fight is the boss when
the place has one. Potions already in the bags are assumed on the belt, up to the personality's potion
target. A place counts only when every fight is won. The winner is the highest `rec_level` among those
(then gold multiplier, then id). A place whose preview reaches 0 HP is a defeat and is never chosen.
If nothing is a clean clear, the hardest place they still survive is chosen. If every preview
is a defeat, the character rests in town. Essence farming and the
exploration roll stay on the personality path.

Because of the gates, a berserker with `risk_offset +2` still can't enter the Sunken Crypt before level 5.
A level-7 bot **can** return to Greenwood. If the next skill rank needs an essence they do not have,
`bot_farm_chance` sends them to the lowest open place that drops one. Otherwise they still chase their
level target. XP in a place below the recommended range stays poor (§5.3).

### 8.8 Mail-based memory

Memory is rebuilt each run from the `payload` JSON of the last `memory_window` trip reports
(soft-deleted mail still counts). Nothing extra is stored; the mailbox *is* the memory. Because runs are
manual and irregular, "avoid for N" counts the bot's **own subsequent trips**, not time.

```
def read_memory(char, prof):
    reports = last_n_trip_reports(char, prof.memory_window)      # newest first; payload fields
    mem = Memory(blocked=set(), step_up=0, retreats=Counter(), last_outcome=None)
    for place_id in {r.place_id for r in reports}:
        defeat_idx = [i for i, r in enumerate(reports) if r.place_id == place_id and r.outcome == 'defeated']
        if len(defeat_idx) >= prof.fail_threshold:
            trips_since_last_defeat = defeat_idx[0]              # index 0 = newest report
            if trips_since_last_defeat < prof.fail_avoid_runs:
                mem.blocked.add(place_id)
    mem.retreats = Counter(r.place_id for r in reports if r.outcome == 'retreated')
    streak = 0                                                   # consecutive easy wins, newest first
    for r in reports:
        if (r.outcome == 'cleared' and r.hp_end_pct >= prof.stepup_hp_pct
                and r.place_rec_level >= char.level + prof.risk_offset - 1):
            streak += 1
        else:
            break
    mem.step_up = min(2, streak // prof.stepup_after_wins)
    mem.last_outcome = reports[0].outcome if reports else None
    return mem
```

*Behaviour this produces:* Brakka grinds safely at level. Grul charges two levels up (as far as the gates
allow) and bounces off. Old Minnow avoids a place for 4 trips after a single defeat. Lucky Pim drifts
toward places with a rarity bonus and carries a Lucky Charm.

### 8.9 Fair play

- Bots use exactly the same DB state, shop prices, level gates, and simulation code as humans. They get
  no hidden stats and no knowledge of monster tables beyond the places' level ranges and their own mail.
- Bots never read other characters' mail and never respec.

---

## 9. Mail Report Format

### 9.1 Structure

- **Subject:** `[Run #<id>] <Place> — <OUTCOME>` plus `(Lv a → b!)` on level-up.
- **Body** (`body_text`): rendered once by the simulation and shown as-is (monospace) in the web UI.
  Order: header → encounters → loot → totals → progression → status/next steps.
- **Payload** (`payload` JSON): the machine-readable summary bots use for memory and the website uses
  for icons and filters.
- Every character gets exactly one mail per run, including `rested`, `blocked_injured`, and
  `order_rejected` trips.

### 9.2 Example rendered report

```
Subject: [Run #42] Sunken Crypt — CLEARED (Lv 8 → 9!)

═══════════════════════════════════════════════════════════
  DELVEBOUND · EXPEDITION REPORT · Rey · Run #42 · 27 Sep 2026 20:41 (Bangkok)
  Destination: Sunken Crypt (dungeon, Lv 6–9)   Outcome: CLEARED ✔
═══════════════════════════════════════════════════════════
Starting HP 262/262 · Attack 38 (phys) · Def 36 (Stoneskin Tonic) · Retreat < 20% · Potion < 40%
Belt: Healing Potion ×2, Stoneskin Tonic ×1 (drunk at the entrance)

 #1  Skeleton Warrior ×2 ........ WON in 7 rounds   HP 262 → 171
     You dealt 214 dmg (2 crits) · took 91 · +333 XP · +41 g
 #2  Crypt Wraith ×1 ............ WON in 4 rounds   HP 242 → 158
     Its magic bolts hurt (your magic resist is only 12). +226 XP · +27 g
     ► Loot: Warded Iron Helm [uncommon, ilvl 8]  (armor 6, mres +4)
 #3  Cave Bat ×3 ................ WON in 5 rounds   HP 229 → 204
     Bats dodged 4 of your swings. +78 XP (low level) · +33 g
 #4  Skeleton Warrior ×1 ........ WON in 3 rounds   HP 262 → 230
     +167 XP · +19 g
     ► Loot: Chainmail [common, ilvl 7]
 #5  ☠ BOSS: Bone Lord .......... WON in 12 rounds  HP 262 → 64
     A brutal fight: he crit you twice for 58. You drank a Healing Potion
     at 31% HP (+131). +1,138 XP · +121 g
     ► Loot: Keen War Axe of the Bear [rare, ilvl 10]  (power 23, crit +3%, STR +3)

 Clear bonus: +388 XP (20%)
 Chest:  ► Copper Ring of Plenty [uncommon, ilvl 8]  (LUK +2, gold find +9%)

───────────────────────────────────────────────────────────
 TOTALS   XP +2,330 · Gold +241 · Items 4 · Kills 8 (1 boss)
 USED     Healing Potion ×1, Stoneskin Tonic ×1   (left: Healing Potion ×1)
 LEVEL UP! 8 → 9   +5 stat points (unspent: 5) · +180 gold
 XP 1,142 / 4,970 to Lv 10
───────────────────────────────────────────────────────────
 Status: healthy. Your order will auto-repeat (Sunken Crypt)
 unless you pick a new destination before the next run.
 Tip: the Keen War Axe is two-handed; equipping it removes your shield.
 Shop: restock potions before your next run (Healing Potion 80 g).
═══════════════════════════════════════════════════════════
```

A **defeat** report instead highlights the fatal encounter, lists the **lost items** and the halved
XP/gold, and states the new injury: *"You are Wounded: −20% HP and −10% attack on your next trip.
Rest in Lanternford or use a Bandage Kit to recover."*

An **order_rejected** report is short:

```
Subject: [Run #57] Greenwood Forest — ORDER REJECTED
You are level 7; Greenwood Forest is capped at Lv 6, so you stayed in Lanternford and rested (+35 g).
Your auto-repeat order was cancelled. Places open to you: Old Copper Mine (Lv 3–6, max 9),
Sunken Crypt (Lv 6–9), Town.
```

### 9.3 Example payload

```json
{
  "v": 1, "run_id": 42, "trip_id": 311, "trip_no": 57,
  "place_id": 3, "place_code": "sunken_crypt", "place_rec_level": 8, "place_rec_range": [6, 9],
  "outcome": "cleared", "encounters": 5, "encounters_won": 5,
  "boss": {"met": true, "killed": true, "code": "bone_lord"},
  "hp_start": 262, "hp_end": 64, "hp_end_pct": 24,
  "xp": 2330, "gold": 241, "level_before": 8, "level_after": 9,
  "items": [{"name": "Heavy Iron Longsword of the Bear", "rarity": "uncommon", "slot": "weapon",
             "item_type": "sword", "two_handed": false, "code": "iron_longsword", "icon": "scrittl-sword-long-4.webp"}],
  "lost_items": [], "injury": {"severity": 0, "runs_left": 0},
  "consumables_used": [{"code": "healing_potion", "qty": 1}, {"code": "stoneskin_tonic", "qty": 1}]
}
```

This clear ended at 24% HP, so for a bot it would *not* count as an "easy win" toward stepping up.
`trip_no` is the character's own trip counter (`characters.trips_total`). Bot memory counts "avoid for N
trips" with it, because manual runs happen at irregular times.

---

## 10. Simulation Command (delve:run)

The simulation is a Laravel console command. It shares the pure game code in `app/Game` with the website,
but it only ever talks to the website **through MySQL** (§1.2): no HTTP request starts it, queues it, or
calls Artisan, and it never calls the website.

### 10.1 Modules

```
app/
├── Game/                      # PURE core: arrays + Rng in, arrays out. No DB, no HTTP, no facades.
│   ├── Balance.php            # loads config/balance.json (path from config/delvebound.php)
│   ├── Numbers.php            # halfUp(), roundTo(), clamp(): the one rounding rule everywhere
│   ├── Rng.php                # seeded RNG: Rng::trip($seed, $charId), Rng::bot($seed, $charId)
│   ├── Rules.php              # canEnter() level gates, canEquip(), slot groups, reject messages
│   ├── Stats.php              # derived(), xpToNext(), pointPool(): character sheet AND combat
│   ├── ShopMath.php           # gearPrice(), sellValue(), respecCost(), shopGearInstance()
│   ├── Combat.php             # runEncounter(), resolveAttack(), choosePotion(), fleeChance()
│   ├── Loot.php               # tryDrop(), rollRarity(), rollAffixes(), itemName(), planCapacity()
│   ├── Progression.php        # xpToNext(), applyXp(), monsterXp(), nextInjury()
│   ├── Trip.php               # resolve(ctx, rng) → result (pure orchestration)
│   ├── MailReport.php         # render(result, runId, when, tripNo) → [subject, body, payload]
│   └── Bots/
│       ├── Memory.php         # read(): mail-based memory (8.8)
│       ├── Allocation.php     # allocate(): stat points (8.3)
│       ├── Gear.php           # loadoutScore(), equipBest(), sellJunk() (8.4, 8.5)
│       ├── Shopping.php       # shopStep(), fillBelt() (8.6)
│       ├── Destination.php    # choose() (8.7)
│       └── BotPlanner.php     # plan(): runs the steps above for one bot (8.2)
├── Simulation/                # the only simulation code that touches MySQL (connection "sim")
│   ├── SimRepo.php            # load world/characters, snapshot orders, apply trip, apply bot decision
│   ├── RunLock.php            # GET_LOCK, simulation_runs lifecycle, heartbeat, stale detection, prune
│   ├── Runner.php             # the run pipeline (section 1.3, steps 0–5)
│   └── RunBlocked.php         # conditions that end with exit code 2
└── Console/Commands/
    ├── RunSimulation.php      # delve:run
    ├── BalanceLab.php         # delve:balance-lab: offline Monte Carlo, reads content, writes nothing
    ├── CheckContent.php       # delve:check-content: runs database/sql/check_content.sql
    ├── CreateAccount.php      # delve:create-account (dev account without the website)
    └── MakeAdmin.php          # delve:make-admin
```

There is no HTTP client, socket, or AI library anywhere in `app/Game` or `app/Simulation`, and a test
enforces it. Bots are purely rule-based (§8).

### 10.2 CLI flags

Normal use is just `php artisan delve:run`, run as often as Rey likes. Each invocation creates a new
run id.

| Flag | Meaning |
|---|---|
| *(none)* | Full run: bot phase, then every active character's trip. |
| `--dry-run` | Do everything, print the reports to stdout, then **roll back** every transaction. It still takes the GET_LOCK but keeps no `simulation_runs` row. |
| `--seed=N` | Fix the run seed (default: a random 63-bit integer). Stored in `simulation_runs.seed`. |
| `--only-character=ID` | Repeatable. Resolve only these characters. Allowed only with `--dry-run` or `--resume`, so a real run can't be applied to a partial set by accident. |
| `--resume=RUN_ID` | Continue a `failed` (or force-unlocked) run with its original seed. Only bots without a `bot_decisions` row and characters without a `trips` row are processed. |
| `--abandon=RUN_ID` | Mark a failed run `abandoned` (characters it already processed keep their results) so new runs are allowed again. |
| `--force-unlock` | Mark a stale `running` run (heartbeat older than `STALE_RUN_MINUTES`) as `failed`, after a crash or a closed terminal. |
| `--skip-bots` | Skip the bot phase (bots with pending orders still travel; the rest auto-repeat or rest). |
| `--bots-only` | Run only the bot phase and print decisions (rolled back unless `--commit-bot-orders`). |
| `--prune-logs` | Trim `trip_encounters.log` older than `LOG_RETENTION_DAYS`, then exit. |
| `-v` / `-q` | Verbose (print every report and bot decision) / warnings only. |
| `--env=NAME` | Laravel's standard flag: load `.env.NAME` instead of `.env` (e.g. a test database). |

**Exit codes:** `0` ok · `1` error · `2` blocked (another run holds the lock, a run is still `running`, or an
unfinished `failed` run needs `--resume`/`--abandon`).

### 10.3 Double-apply safeguards (keyed to the run id)

With manual runs there are no time slots. "Idempotent" here means **a given run can never apply a
character's result twice, and two runs can never overlap**. Running again later is always allowed and
simply means a new run and new trips.

1. **Process lock:** `SELECT GET_LOCK('delvebound_simulation', 0)`. If it returns 0, another
   `delve:run` or `delve:arena` is active: print who (from `simulation_runs` or `arena_tournaments`
   host/pid) and exit 2. MySQL releases the lock automatically if the process dies. The arena uses this
   same lock (§15) so an expedition and a bracket never write characters together.
2. **One running run:** the new run row is inserted with `status='running'`. The generated
   `running_guard` column + `uq_runs_one_running` make a second concurrent `running` row impossible, even
   if the lock were bypassed.
3. **No silent overlap with a broken run:** if the latest run is `failed`, a new run refuses to start
   (exit 2) until Rey chooses `--resume=<id>` (finish it) or `--abandon=<id>` (accept the partial run).
   Otherwise some characters would get two trips "in the same run" while others got one.
4. **Per-character atomicity:** for each character, one transaction:
   `SELECT … FROM characters WHERE id=? FOR UPDATE` → check there is no `trips` row for
   `(run_id, character_id)` → insert trip, encounters, item instances, affixes, trip_loot → update the
   character (xp, level, points, gold, injury, counters, `row_version+1`) → update consumable stacks →
   mark the order `consumed` with `consumed_run_id` → insert mail → bump `simulation_runs.characters_done`
   and `heartbeat_at` → **COMMIT**.
   `uq_trips_run_char`, `uq_mail_trip`, and `uq_botdec_run_char` make a double apply within a run
   impossible even if the application logic has a bug.
5. **Snapshot cut-off:** only orders with `submitted_at <= run.started_at` are consumed. Orders placed
   during a run stay pending for the next one.
6. **Level gate re-check:** at snapshot time every order is validated with `Rules::canEnter()` (§4.0).
   Invalid ones become `order_rejected` rest trips.
7. **Website read-only during runs:** the `run.guard` middleware on every state-changing route checks
   `EXISTS(SELECT 1 FROM simulation_runs WHERE status='running')` and refuses with *"A simulation run is in
   progress, try again in a moment."* The same guard refuses while an `arena_tournaments` row is `running`.
   Optimistic `row_version` checks catch the remaining race.
8. **Determinism:** trip RNG = `Rng::trip($runSeed, $characterId)`, a Xoshiro256\*\* engine seeded with the
   SHA-256 of the string `"<run seed>:<character id>"` (bots: `"<run seed>:bot:<character id>"`). It gives
   the same sequence in every PHP process and version (8.2+). Results don't depend on processing order, so
   `--resume` or `--only-character` reproduces exactly what an uninterrupted run would have done.
   Nothing in `app/Game` or `app/Simulation` may use `rand()`, `mt_rand()`, `random_int()`, `shuffle()`, or
   `array_rand()`. (Runs made before M6 used Python's RNG, so their seeds don't replay identically; their
   stored results are unaffected.)

### 10.4 Runner pipeline (pseudocode)

```php
public function execute(array $opts): int
{
    $db = DB::connection('sim');                                   // delve_sim user
    $lock = RunLock::acquire($db);                                 // exit 2 if busy
    try {
        if ($opts['abandon']) return RunLock::abandon($db, $opts['abandon']);
        RunLock::refuseIfBlocked($db, $opts['resume'], $dry);       // exit 2 if a failed run is pending
        if ($dry) $db->beginTransaction();                          // outer tx, rolled back at the end
        $run = $opts['resume'] ? RunLock::resume($db, $id) : RunLock::begin($db, $seed, ...);
        $world = $repo->loadWorld();
        if (!$opts['resume']) $repo->birthNewcomer($run['seed'], $run['id'], $world); // spawn_chance, until the cap
        if (!$opts['skip_bots']) $this->botPhase($run, $world);    // one transaction per bot
        $plans = $repo->snapshotOrders($run, $world);              // order | auto-repeat | rest | blocked | rejected
        foreach (sorted($plans) as $charId => $plan) {             // stable order = readable logs
            if ($only && !in_array($charId, $only)) continue;
            if ($repo->tripExists($run['id'], $charId)) continue;  // resume path
            $ctx    = $repo->loadTripContext($charId, $plan, $world, $run);
            $result = Trip::resolve($ctx, Rng::trip($run['seed'], $charId));      // pure
            $mail   = MailReport::render($result, $run['id'], $run['started_at'], $tripNo);
            $db->transaction(fn () => $repo->applyTrip($run, $ctx, $result, $mail));
            $this->log($result);
        }
        RunLock::finish($db, $run['id'], 'completed');
        return 0;
    } catch (Throwable $e) {
        RunLock::finish($db, $run['id'], 'failed', $e->getMessage());
        return 1;
    } finally {
        if ($dry) $db->rollBack();
        $lock->release();
    }
}
```

### 10.5 Logging and output

- Console: one line per character plus an end-of-run summary (counts by outcome). With `--dry-run` or
  `-v` every mail report is printed too. This is what Rey sees in the terminal.
- File: `storage/logs/sim-YYYY-MM-DD.log` (Laravel log channel `sim`, daily, keep 30). Per-character line:
  `[2026-09-27 20:41:03] sim.INFO: run=42 char=1(Rey) place=sunken_crypt outcome=cleared xp=2330 gold=241 lvl=8→9`
- Warnings for content problems (place without monsters). Passwords are never logged.

---

## 11. Website (Laravel + Inertia + React)

**Stack:** Laravel 12 on PHP 8.2+ (Apache `mod_php`), Inertia.js 2 with React 19 + TypeScript, Tailwind
CSS 4, built by Vite into `public/build`. Apache serves `public/` at **http://delvebound.host**; every
request goes through Laravel's front controller `public/index.php` (rewrite rules in `public/.htaccess`).
Pages are React components. Links and form posts are Inertia requests (XHR), so the screen never does a
full reload: a controller returns `Inertia::render('Page', $props)` or redirects back with a flash message,
and Inertia swaps the page in place. The HTTP side uses the default `mysql` connection (`delve_web` user).

### 11.1 Pages

| Route (GET) | Page (`resources/js/pages`) | Reads | Writes (POST/DELETE, CSRF-protected, refused during runs) |
|---|---|---|---|
| `/register` | `auth/Register` | `accounts` (uniqueness), starter weapons | `POST /register`, **one transaction:** `accounts` + `characters` (+ starter kit `item_instances`, `character_equipment`, 2 Minor Healing Potions, 50 gold). Only if `ALLOW_SIGNUP=true` |
| `/login` | `auth/Login` | `accounts` | `POST /login`: session; `accounts.last_login_at`, `failed_logins`, `locked_until`. `POST /logout` |
| `/account` | `Account` | own account | `POST /account/password`: change password (requires the current one) |
| `/` | `Dashboard` | own character summary, unread mail count, pending order, belt, last `simulation_runs` row, recent trips | – |
| `/character` | `Character` | left menu: Character (sheet, attributes, combat profile, power, auto mode), Skills, Horcrux, Inventory (`v_inventory` slot grid, equipment, belt, sealed `loot_boxes`, material stacks), Forge (§6.9), Collection (every premium piece, owned or locked, with activate / equip, §16.3). `?tab=skills\|horcrux\|inventory\|forge\|collection` opens that section. `Stats::derived()` includes skill, horcrux, and premium bonuses; a "Stat bonuses" panel shows base → bonus → total per stat and each bonus source; the power panel shows the portrait with equipped premium pieces. The forge quote (`Forge::quote`) is loaded when `forge_a` and `forge_b` are set | `POST /character/points`, `/character/prep` (retreat, potions, auto-repeat, and auto mode + personality + map choice `profile` or `best`), `/character/respec` (cost `50·level` gold **[T]**), `/character/skills/{code}`, `/character/horcrux`, the Collection tab's `/premium/activate`, `/premium/activate-all`, `/premium/wear`, `/premium/take-off`, plus the old pack writes below |
| `/pack` | redirects to `/character` | — | `POST /pack/equip`, `/pack/unequip` (refused when the inventory already holds `inventory_capacity` items), `/pack/belt` (≤ `BELT_SLOTS` total), `/pack/bandage`, `/pack/chests/{id}/open`, `/pack/forge` (two unequipped pieces, same slot; §6.9). Gear is a grid of icons; stats show on hover |
| `/market` | `Market` | `shop_listings` (+ templates), own gold/level/inventory, recent `shop_transactions` | `POST /market/buy`, `/market/sell-item`, `/market/sell-consumable` (§7.7 rules, ledger row per action; consumables buy/sell 1–20 at a time, selling shrinks `belt_qty` to what is left) |
| `/premium` | `Premium` | `premium_items`, own `premium_cash` / `premium`, per-item stat preview from `Stats::derived`, recent premium `shop_transactions` | `POST /premium/buy` (`code`); owned pieces link to the Hero Collection tab; §16 rules |
| `/map` | `Map` | `places` incl. **min/rec/max levels**, own past `trips` per place | `POST /map/order` (orders flow below), `DELETE /map/order` cancels the pending order; "Rest in town" = the Town place |
| `/mail`, `/mail/{id}` | `mail/Index`, `mail/Show` | own `mail` (inbox, paging, filter by outcome/unread) | opening marks `is_read`; `DELETE /mail/{id}` soft-deletes (`deleted_at`); `POST /mail/read-all` marks the inbox read |
| `/trips/{id}` | `Trip` | own `trips`, `trip_encounters.log`, `trip_loot` | – (animated round-by-round battle replay) |
| `/leaderboard` | `Leaderboard` | `v_leaderboard` plus each character's power (`Growth::powerLevel` from level, skill ranks, and horcrux tier; sortable: level/XP, power, gold, boss kills, clears, defeats; bots marked 🤖). A name opens that adventurer's public sheet | – |
| `/leaderboard/{id}` | `Adventurer` | any character's public sheet: attributes, derived stats, equipped gear, skill ranks, horcrux. Inventory, material stacks, belt counts, and expedition orders are not included | – |
| `/bots` | `Bots` | bot characters, their `bot_profiles` flavour text, recent `bot_decisions` | – |
| `/arena` | `Arena` | own signup, waiting humans, every active bot (they always enter), the latest bracket and its logs, past champions | `POST /arena/enter` signs the session's character up; `DELETE /arena/enter` withdraws. There is no "start the arena" button |
| `/runs` | `Runs` | `simulation_runs` history, outcome counts per run | – (admin only; there is no "Run now" button) |
| `/credits` | `Credits` | static attribution for the item drawings (public; no login) | – |
| `/admin` | `admin/Index` | all `item_templates` plus loot/shop usage | – (admin only) |
| `/admin/gear/create`, `/admin/gear/{id}/edit` | `admin/GearForm` | one template, loot tables, shop listing | `POST /admin/gear`, `PUT /admin/gear/{id}`, `DELETE /admin/gear/{id}` (admin + `run.guard`). Writes `item_templates`, `loot_table_entries`, and `shop_listings`. Live DB only — not written back to seed SQL. Already-dropped `item_instances` keep rolled power/armor/mres; implicits follow the template. |

**Orders flow (`POST /map/order` → `CharacterService::setOrder`), with level validation:**

```php
// route middleware: auth, run.guard; CSRF is checked by Laravel before the controller runs
DB::transaction(function () use ($accountId, $placeId) {
    $char  = DB::table('characters')->where('account_id', $accountId)->lockForUpdate()->first(); // never a posted character id
    $place = DB::table('places')->where('id', $placeId)->where('is_active', 1)->first();
    [$ok, $reason] = Rules::canEnter($char->level, (array) $place);                     // the one gate rule
    if (!$ok) throw new GameException(Rules::rejectMessage($char->level, (array) $place, $reason));
    DB::table('trip_orders')->where('character_id', $char->id)->where('status', 'pending')->update(['status' => 'cancelled']);
    DB::table('trip_orders')->insert(['character_id' => $char->id, 'place_id' => $placeId, 'submitted_by' => 'web']);
});
// GameException → redirect back with flash("Requires level 5.") and nothing written
```

The map greys out locked places with the same reason text. Places outside the *recommended* range
stay selectable, with a warning ("Recommended Lv 6–9: expect heavy losses" or "Low XP here").

**Other server-side validation (the same rules the simulation uses):** points spent ≤ unspent; item owned
with `status='owned'`; `req_level <= level`; slot compatibility via `Rules::canEquip()` (ring → ring1/ring2,
2H clears offhand, no shield with a 2H weapon); shop level/gold/inventory checks; belt total ≤
`BELT_SLOTS`; every write in a transaction with a `row_version` check.

### 11.2 Accounts, sign-up, and login

- **Sign-up** (`POST /register`): username (3–32 chars, `[A-Za-z0-9_]`), optional email, password (min 10
  chars, confirmed), character name, starting weapon. The account, character, and starter kit are created
  in **one transaction**. `uq_accounts_username`, `uq_characters_name`, and `uq_characters_account` settle
  races and enforce **one account = one character**. Sign-up can be closed with `ALLOW_SIGNUP=false`.
  The first account ever created becomes `is_admin` (normally Rey).
- **Model:** `App\Models\Account` (table `accounts`) is the only Eloquent model; it implements Laravel's
  `Authenticatable`. Game data is read and written with the query builder.
- **Passwords:** `Hash::make()` (bcrypt, the same format as the old `password_hash()`, so accounts created
  before M6 still log in); `Hash::check()` on login; `Hash::needsRehash()` → re-hash transparently.
- **Login throttling:** 5 failed attempts → `locked_until = now + 15 min` **[T]** (`LOGIN_MAX_FAILURES`,
  `LOGIN_LOCK_MINUTES`). The error message is generic ("Invalid username or password").
- **Sessions:** Laravel's `file` session driver (no extra table); `$request->session()->regenerate()` on
  login; cookie `HttpOnly`, `SameSite=Lax`, `Secure` when served over HTTPS; idle timeout
  `SESSION_LIFETIME` minutes (720 = 12 h). Logout is a POST that invalidates the session and the token.
- **CSRF:** Laravel's built-in CSRF middleware on every non-GET route. Laravel sets an `XSRF-TOKEN` cookie
  and Inertia sends it back as the `X-XSRF-TOKEN` header automatically.
- **Authorization:** every query that touches game state is scoped by
  `characters.account_id = Auth::id()`. Posted ids (items, mail, trips) are always looked up *through* the
  session's character, so someone else's id simply isn't found. Admin-only routes use the `admin`
  middleware (`accounts.is_admin`).
- **Bots** have no account row, so they can never log in (enforced by `chk_char_owner`).
- **Output/SQL hygiene:** React escapes all text (`dangerouslySetInnerHTML` is not used anywhere);
  query builder / prepared statements only; `PDO::ATTR_EMULATE_PREPARES = false`.

### 11.3 Code layout

```
routes/web.php
  → app/Http/Controllers/*Controller.php     thin: validate input, call a service, redirect or render
  → app/Services/*.php                        transactions, row_version checks, ledger rows
       AccountService   sign-up + starter kit, password change
       CharacterService points, prep, respec, equip/unequip, belt, bandage, orders
       ShopService      buy/sell (gear and consumables)
       ForgeService     combine two pieces (§6.9)
       AdminContentService  admin gear templates, loot weights, shop listings
       GameQueries      read models for the pages
  → app/Game/*                                rules and formulas (shared with the simulation)
  → Inertia::render('Page', props) → resources/js/pages/*.tsx (+ components/, layouts/, lib/)

app/Http/Middleware/HandleInertiaRequests.php   shared props: account, character summary, flash, run status
app/Http/Middleware/RefuseDuringRun.php         alias "run.guard"
app/Http/Middleware/EnsureAdmin.php             alias "admin"
app/Exceptions/GameException.php                a rule was broken → flash message, nothing written
```

---

## 12. Project Layout, Config, and WSL Setup

### 12.1 Folder layout

Repo root: **`/var/www/vhosts/delvebound`** (WSL2 Ubuntu 22.04 on Rey's PC). It is a standard Laravel 12
application.

```
/var/www/vhosts/delvebound/
├── README.md  DESIGN.md  AGENTS.md
├── .env.example               # copy to .env (never committed)
├── artisan  composer.json  package.json  vite.config.ts  tsconfig.json
├── app/
│   ├── Game/                  # pure rules/formulas/combat/loot/bots (section 10.1); ItemArt, ItemTemplates authoring
│   ├── Simulation/            # delve:run and delve:arena pipelines + all simulation SQL
│   ├── Console/Commands/      # delve:run, delve:arena, delve:balance-lab, delve:check-content, delve:create-account, delve:make-admin
│   ├── Http/Controllers/  Http/Middleware/
│   ├── Services/              # web-side transactions
│   ├── Models/Account.php     # the only Eloquent model (auth)
│   └── Exceptions/GameException.php
├── bootstrap/                 # app.php (routing, middleware aliases); cache/ must be writable by Apache
├── config/
│   ├── balance.json           # ALL [T] constants (read by App\Game\Balance)
│   ├── delvebound.php         # game settings from .env (signup, lockout, stale minutes, display timezone)
│   └── database.php …         # Laravel config; connections mysql (web), sim, admin
├── database/
│   ├── schema/001_schema.sql  # section 2.3, applied by the base migration
│   ├── migrations/            # 0001_01_01_000001_delvebound_schema.php, then every later change
│   ├── seeders/               # DatabaseSeeder runs sql/010,015,020 once each
│   │   └── sql/               # 010_content.sql, 015_shop.sql, 020_bots.sql
│   └── sql/                   # bootstrap.sql, grants_web.sql (run once as MySQL root), check_content.sql
├── public/                    # Apache DocumentRoot → http://delvebound.host
│   ├── index.php  .htaccess   # Laravel front controller
│   ├── build/                 # Vite output (npm run build)
│   └── item-art/              # painted WebP icons, two SVG fallbacks, CREDITS.md; URL /item-art/ (not /icons/)
├── resources/
│   ├── item-art/catalog.json  # icon families, slot fallbacks, consumable stems
│   ├── js/                    # app.tsx, pages/, components/, layouts/, lib/, types/
│   ├── css/app.css            # Tailwind 4 + the Delvebound theme
│   └── views/app.blade.php    # the single HTML shell Inertia mounts into
├── routes/web.php  routes/console.php
├── storage/                   # logs/ (laravel.log, sim-*.log), framework/sessions; writable by Apache
├── tests/
│   ├── fixtures/              # derived_stats_cases.json, place_access_cases.json, shop_price_cases.json, xp_cases.json
│   ├── Unit/                  # formulas, fixtures, combat, loot, bots, no-network guard
│   └── Feature/               # auth, tampering, run guard, shop/belt/orders, simulation determinism + idempotency
└── apache/
    └── delvebound.host.conf   # vhost template
```

### 12.2 `.env` (example)

```dotenv
APP_NAME=Delvebound
APP_ENV=local
APP_KEY=                        # php artisan key:generate
APP_DEBUG=false
APP_URL=http://delvebound.host
DISPLAY_TIMEZONE=Asia/Bangkok   # app timezone stays UTC; this is only for display

# Database: one host, three users (section 2.1)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=delvebound
DB_USERNAME=delve_web           # web requests (connection "mysql")
DB_PASSWORD=change-me-web
DB_SIM_USERNAME=delve_sim       # delve:run (connection "sim")
DB_SIM_PASSWORD=change-me-sim
DB_ADMIN_USERNAME=delve_admin   # migrations + seeds (connection "admin")
DB_ADMIN_PASSWORD=change-me-admin

# Sessions / cache / queue: files and sync, so no extra tables
SESSION_DRIVER=file
SESSION_LIFETIME=720
SESSION_COOKIE=delvebound_session
CACHE_STORE=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=daily

# Game
BALANCE_FILE=config/balance.json
ALLOW_SIGNUP=true
LOGIN_MAX_FAILURES=5
LOGIN_LOCK_MINUTES=15
STALE_RUN_MINUTES=15
LOG_RETENTION_DAYS=60
```

**Grants:** `delve_admin` has all privileges on `delvebound.*` (migrations and seeds only). `delve_sim` has
SELECT/INSERT/UPDATE/DELETE on `delvebound.*`. `delve_web` has:
- SELECT on `delvebound.*`;
- INSERT on `accounts`, `characters`, `item_instances`, `character_equipment`, `character_consumables`,
  `trip_orders`, `shop_transactions`;
- UPDATE on `accounts`, `characters`, `item_instances`, `character_consumables`, `trip_orders`, `mail`;
- DELETE on `character_equipment`, `character_consumables`.

Admin content tools (`/admin`) use the `admin` connection (`delve_admin`) so they can write
`item_templates`, `loot_table_entries`, and `shop_listings` without widening `delve_web`. Those
routes still require `accounts.is_admin`.

### 12.3 Setup steps (WSL2 Ubuntu 22.04)

```bash
# 1. Packages: PHP 8.2+ with the Laravel extensions, Composer, Node 20+, MySQL 8.0, Apache
sudo apt install -y mysql-server apache2 libapache2-mod-php8.2 php8.2-cli php8.2-mysql php8.2-mbstring \
                    php8.2-xml php8.2-curl php8.2-bcmath php8.2-zip
sudo a2enmod rewrite
sudo systemctl enable --now mysql apache2      # needs systemd=true in /etc/wsl.conf

# 2. Code + dependencies
cd /var/www/vhosts/delvebound
composer install
npm ci && npm run build                        # compiles the React UI into public/build
cp .env.example .env && nano .env              # set the three DB passwords
php artisan key:generate
chgrp -R www-data storage bootstrap/cache && chmod -R g+rwX storage bootstrap/cache

# 3. Database: create it and the users, then schema + seed data
sudo mysql < database/sql/bootstrap.sql         # CREATE DATABASE delvebound + delve_* users/grants
php artisan migrate --database=admin --force    # base schema (no-op if the tables already exist)
php artisan db:seed --database=admin --force    # content, shop, 6 bots (skips data that is already there)
sudo mysql < database/sql/grants_web.sql        # table-level grants for delve_web
php artisan delve:check-content                 # must report no problems

# 4. Apache vhost (DocumentRoot is public/, AllowOverride All for Laravel's .htaccess)
sudo cp apache/delvebound.host.conf /etc/apache2/sites-available/
sudo a2ensite delvebound.host && sudo apache2ctl configtest && sudo systemctl reload apache2
```

`apache/delvebound.host.conf`:

```apache
<VirtualHost *:80>
    ServerName delvebound.host
    DocumentRoot /var/www/vhosts/delvebound/public
    <Directory /var/www/vhosts/delvebound/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>
    ErrorLog  ${APACHE_LOG_DIR}/delvebound_error.log
    CustomLog ${APACHE_LOG_DIR}/delvebound_access.log combined
</VirtualHost>
```

**5. Hosts entry on Windows** (Notepad as Administrator → `C:\Windows\System32\drivers\etc\hosts`):

```
172.19.63.2 delvebound.host
```

Then open http://delvebound.host and sign up (the first account becomes admin). Note that the WSL2 IP can
change after a Windows reboot. Check it with `hostname -I` inside WSL and update the hosts line if needed.

**6. Run the simulation (manual, any time):**

```bash
cd /var/www/vhosts/delvebound
php artisan test                                # tests
php artisan delve:run --dry-run --seed=42       # preview; nothing is written
php artisan delve:run                           # real run
php artisan delve:arena --dry-run --seed=42     # preview a knockout bracket; nothing is written
php artisan delve:arena                         # real arena (humans who signed up, plus every bot)
```

**Dev reset** (drops every table, then schema + seeds): `php artisan migrate:fresh --drop-views --seed
--database=admin`. The table-level grants for `delve_web` survive a drop, so there is no need to re-run
`grants_web.sql`.

**Frontend development:** `npm run dev` starts Vite with hot reload (the site picks it up automatically
through `public/hot`); stop it and run `npm run build` before leaving the site running normally.

---

## 13. Build Milestones

M1–M4 were first built with a Python simulation and a plain-PHP site. Since **M6** the same acceptance
criteria apply to the Laravel code: read `python3 run_simulation.py …` as `php artisan delve:run …`,
`pytest` as `php artisan test`, `migrate.sh`/`reset_db.sh` as `php artisan migrate`/`migrate:fresh`, and
the `.php` pages as the routes in §11.1.

### M1: Database + seed data
**Deliverables:** `db/bootstrap.sql`, `001_schema.sql`, seeds (content, shop, 6 bots), `migrate.sh`,
`reset_db.sh`, `check_content.sql`, `config/balance.json`, `bin/create_account.php` (a dev account for
testing before the website exists), `apache/delvebound.host.conf`.
**Acceptance criteria:**
- [ ] `reset_db.sh` on a clean MySQL 8.0 builds everything with no errors, and running `migrate.sh`
      again is a no-op.
- [ ] Seed counts: 16 places (incl. Town) across 3 regions, 46 monsters, 40 item templates, 15 affixes, 15 loot tables,
      8 consumables, 24 shop listings (16 gear + 8 consumables), 6 bot profiles, and 6 bot characters,
      each with its starter kit equipped.
- [ ] Every place row passes `chk_places_levels`. Greenwood and Copper Mine have a `max_level`; the others
      have `NULL`.
- [ ] `check_content.sql` returns zero rows for: non-rest places without monsters, monsters without a
      loot table, loot tables with zero total weight, shop listings for inactive templates, and places
      whose monsters are far outside the recommended range.
- [ ] Constraint tests: a second character for the same account is rejected (`uq_characters_account`);
      a bot with an account, or a human without one, is rejected (`chk_char_owner`); a second
      `running` run is rejected (`uq_runs_one_running`); a second pending order is rejected
      (`uq_orders_one_pending`).

### M2: Simulation CLI + mail (humans only)
**Deliverables:** `rpg_sim` core (rules, stats, combat incl. potions, loot, progression, trip, mail,
runner, locking), CLI flags, tests, `tools/balance_lab.py`.
**Acceptance criteria:**
- [ ] `python3 run_simulation.py --dry-run --seed 42` prints identical output on two consecutive runs and
      leaves the DB unchanged.
- [ ] A real run creates one `simulation_runs` row plus one `trips` row and one `mail` per active
      character, with XP, level, points, gold, items, consumables, and injury updated as in §4–§7.
- [ ] Running it again right away creates a *new* run id and new trips (manual runs are unlimited). Two
      simultaneous invocations give one success and one exit 2.
- [ ] Killing the process mid-run → the next plain run exits 2 → `--force-unlock` + `--resume <id>`
      finishes with no duplicate trips or mail, matching an uninterrupted run with the same seed.
      `--abandon <id>` unblocks new runs instead.
- [ ] Level gates: an order below `min_level` or above `max_level` (inserted by hand) becomes
      `order_rejected` with an explanatory mail. `place_access_cases.json` passes.
- [ ] Unit tests cover every formula in §3–§7 (caps, 2H/offhand, multi-level-up, defeat losses, injury
      transitions, affix constraints, potion/buff/smoke-bomb effects). The shared fixtures pass.
- [ ] Balance lab: the §7.6 targets are met, or deliberately re-tuned in `balance.json`.

### M3: Bots
**Deliverables:** `rpg_sim/bots/*` incl. shopping, `bot_decisions` logging, bot tests, balance-lab bot mode.
**Acceptance criteria:**
- [ ] In the bot phase, each of the 6 bots spends all unspent points, equips its best legal loadout,
      shops within its gold reserve, fills its belt, and submits exactly one order. Every decision is
      recorded with reasons.
- [ ] Over a 100-run offline simulation: every bot reaches at least level 8; **zero** bot orders are
      `order_rejected` (bots respect the gates); no bot drops below its gold reserve through shopping;
      no bot is defeated more than 3 times in a row at the same place; archetypes are visibly different.
- [ ] Memory tests: after `fail_threshold` defeats at a place, the bot avoids it for `fail_avoid_runs`
      trips; after `stepup_after_wins` easy clears, its target level goes up by 1.
- [ ] The same seed gives identical bot decisions. A test asserts that `rpg_sim` imports no network/HTTP
      modules.

### M4: Website
**Deliverables:** the pages in §11, sign-up/login, CSRF, `Rules.php`, `Stats.php`, `Shop.php` sharing
`balance.json` and the fixtures.
**Acceptance criteria:**
- [ ] Sign-up creates an account + character + starter kit atomically. A second character for the same
      account is impossible. Login, logout, lockout, and password change work. Every page except
      login/register redirects when logged out.
- [ ] A user can only see and modify their own character (tested by tampering with POSTed ids).
- [ ] The orders flow refuses places outside the level gates, with the right message, and writes nothing.
      Locked places are greyed out on the map.
- [ ] Shop buy/sell works, never lets gold go negative, and writes a ledger row per action. The belt
      editor enforces `BELT_SLOTS`.
- [ ] The derived stats on the character sheet match Python for every fixture case, and `Rules.php`
      passes `place_access_cases.json`.
- [ ] While a run is `running`, every POST is refused with a friendly message.
- [ ] Mailbox, battle replay, leaderboard (humans + 🤖 bots), and bots page render correctly.

### M5: Extras (each can ship on its own)

| Extra | Sketch | Acceptance |
|---|---|---|
| **Parties** | `parties` + `party_members`. One order per party; shared encounters; monsters target by threat; round-robin loot; each member still gets their own mail. The **party** must pass every member's level gate. | Deterministic; per-character idempotency holds; no member bypasses a gate. |
| **Crafting** | The forge (§6.9) is the first sink: two unequipped pieces of the same slot. Recipes remain later. | Atomic; crafted items have `source='craft'`. A crack destroys both pieces. |
| **Rare events** | `events` table (trigger chance, place filter, effect JSON): wandering merchant, shrine buff, ambush. | Fire at about the configured rate and appear in the mail. |
| **Seasons** | `seasons` table, soft reset, archived leaderboards. | Rollover is idempotent; past seasons stay viewable. |
| **Shop v2** | Rotating/limited stock, level-scaled uncommon gear. | Stock never goes negative. |
| **Arena (PvP)** | `arena_signups`, `arena_tournaments`, `arena_matches`. Humans opt in; bots always enter. `php artisan delve:arena` draws a random single-elimination bracket and fights each bout to 0 HP. See §15. | Deterministic for a seed; dry-run writes nothing; a knockout does not injure or drop gear; the website cannot start it. |
| *Possible future:* **Run request** | A "Request a run" button writes a `run_requests` row, and an optional watcher runs the sim. **Not planned**; runs stay manual. | – |

### M6: One Laravel codebase (Laravel 12 + Inertia + React)
**Deliverables:** a Laravel 12 app at the repo root; the simulation ported from Python to `app/Game` +
`app/Simulation` + `php artisan delve:run`; the website rebuilt as Inertia/React pages; schema and seeds
wrapped in a Laravel migration and seeders; PHPUnit tests replacing pytest and `tests/php/run.php`; the
Python `sim/` and the old `src/`, `templates/`, `bin/` folders removed.
**Acceptance criteria:**
- [ ] `php artisan test` is green: the four fixture files, formula/combat/loot/bot unit tests, the
      no-network guard, feature tests (logged-out redirects, tampered ids, CSRF, run guard, shop, belt,
      orders), and simulation tests (dry-run determinism, idempotent apply, `--resume`, level gates).
- [ ] `php artisan delve:run --dry-run --seed=42` prints identical output on two consecutive runs and
      leaves the database unchanged.
- [ ] A database created before M6 is adopted without data loss: `php artisan migrate --database=admin`
      records the base schema migration without recreating any table, and existing accounts log in with
      their old passwords.
- [ ] Every page works without full page reloads, and every state-changing request is refused while a run
      is `running`.
- [ ] The derived stats on the character sheet equal the fixture values (one implementation now).
- [ ] No Python remains in the repo.

---

## 14. Decisions Log

| # | Decision | Why | Source |
|---|---|---|---|
| 1 | The game is named **Delvebound**. | Project identity (DB name `delvebound`, site `delvebound.host`). | Rey |
| 2 | **Runs are manual only**: `php artisan delve:run` (was `python3 run_simulation.py` before M6), any time, unlimited. No schedule, cron, Laravel scheduler, or queue worker, and no catch-up. | Rey controls the pace; removes all wall-clock logic and WSL cron quirks. | Rey |
| 3 | Double-apply safeguards are keyed to the **run id** + `GET_LOCK` (one running run; unique trip per run+character; failed runs must be resumed or abandoned). | Keeps crash safety without time slots. | Rey + design |
| 4 | **No "Run now" button** or request queue. Listed only as a possible future idea. | Would break "manual only" and "no direct calls" for little gain. | Rey |
| 5 | **Sign-up/login** with `password_hash`, sessions, CSRF, login throttling. **One account = one character** (`uq_characters_account`). Multiple humans are possible. | Proper auth even on a local site; the unique key makes the 1:1 rule impossible to break. | Rey |
| 6 | Keep **6 bots**; bots are characters **without an account** (`chk_char_owner`). | The world stays lively even with a single human. | Rey |
| 7 | Add a **shop**: common starter gear + consumables for gold; sell loot; every action in a `shop_transactions` ledger; bots shop in their decision step. | A gold sink plus a way to recover from bad luck; the ledger makes the economy debuggable. | Rey |
| 8 | Defeat penalty stays: lose the trip's items + 50% XP/gold + a one-run *Wounded*. **No gear durability.** | Stings without being punishing; less bookkeeping. | Rey (kept default) |
| 9 | **Places are level-specific:** hard `min_level`, recommended range, optional hard `max_level` on starter areas. Enforced by the website orders flow, the bot candidate filter, and the sim snapshot (`order_rejected`). One rule mirrored in `Rules.php` / `rules.py` with shared fixtures. | Clear progression, no farming of starter areas, no way around it via stale orders or hand-edited rows. | Rey |
| 10 | Repo at `/var/www/vhosts/delvebound`; `public/` is the Apache root for `http://delvebound.host`. Stack since M6: Laravel 12 (PHP 8.2+), MySQL 8.0 on WSL2 Ubuntu 22.04. | Matches Rey's machine. | Rey |
| 11 | ~~Formulas are mirrored in Python and PHP, guarded by shared JSON fixtures.~~ **Superseded by #13:** one implementation in `app/Game`, still checked by the same JSON fixtures; all numbers still live in `config/balance.json`. | The site shows exact stats instantly without calling the sim. | Design |
| 12 | Crash recovery is manual (`--force-unlock`, `--resume`, `--abandon`), never automatic. | Failures stay visible; fits manual runs. | Design (unchanged) |
| 13 | **Move to one Laravel codebase (M6).** The website becomes Laravel 12 + Inertia + React; the Python simulation is ported to PHP as `php artisan delve:run`, sharing `app/Game` with the site. The DB stays the only channel between them, and web requests never run a simulation. | The project will grow, mostly with AI help: Laravel gives fixed conventions, security defaults (CSRF, escaping, auth), and a stack AI tools know well. One language removes the mirrored formulas. | Rey |
| 14 | **Laravel 12, not 13.** | Apache runs `mod_php` 8.2 and Laravel 13 needs PHP 8.3. Upgrade when Apache moves to PHP 8.3+. | Design |
| 15 | **Inertia + React instead of a separate JSON API.** | No full-page reloads while keeping one app, session auth, and Laravel's CSRF; no token auth or CORS. A JSON API can be added next to it later if needed. | Rey + design |
| 16 | **SQL-first schema; query builder, not an ORM.** The existing schema SQL is wrapped in the base migration; game data uses the query builder. The only Eloquent model is `Account` (for Laravel auth). Sessions/cache use files and the queue is `sync`, so Laravel adds no tables. | Keeps the CHECK constraints, generated columns, and views exactly as designed, and keeps `FOR UPDATE` transactions explicit. | Design |
| 17 | **New seeded RNG:** Xoshiro256\*\* seeded with SHA-256 of `"<seed>:<char id>"` replaces Python's `random.Random`. | Deterministic in every PHP version. Runs made before M6 don't replay identically from their seeds; their stored results are untouched. | Design |
| 18 | **Admin gear editor** at `/admin` (`accounts.is_admin`). Create/edit `item_templates` in MySQL, with loot-table weights and an optional shop listing (price from `ShopMath::gearPrice`). Writes use the `admin` DB connection. Seeds stay the reset source; admin edits are not written back to seed SQL. | Rey can add and tune gear without a migration. | Rey |
| 19 | **Arena is a separate manual command**, `php artisan delve:arena`, not part of `delve:run`. Humans opt in on `/arena`; the six bots always enter. Bouts are knockout (to 0 HP) in a random single-elimination bracket. No injury, no gear loss. The website cannot start it. It shares the `delvebound_simulation` lock with expeditions so the two never write characters at the same time. | PvP should feel like a duel card, not another expedition, and Rey still rings the bell. | Rey |
| 20 | **Three maps (regions).** The Wilds (Lv 1–16), The Salt Marches (Lv 14–25), and The Ashen Crown (Lv 24–30, the level cap). A place has a `region_id`; the map page shows one region at a time. Later gear drops from those places and is not sold in the Lanternford shop. | One map ran out of room before the level cap. Regions keep each parchment readable. | Rey |
| 21 | **Clear reward is a sealed chest.** Kill and boss drops are still rolled during the trip. A clear writes a `loot_boxes` row; the gear is rolled when a human opens it on the hero page, or when a bot's next decision step opens it. The seed and luck factor are snapshotted on the box. Affix count stays tied to rarity. Roll count grows with the place's recommended level (`chest_rolls_*` in `balance.json`). | Rey wanted loot on the kill, and a chest you open later the way a loot box opens, including for bots. | Rey |
| 22 | **Materials, two upgrades, and open low maps.** Spark Dust and Rune Shards drop everywhere. Each place also drops one essence. Skills (Keen Edge, Iron Hide, Fortune) spend those materials. The horcrux eats unequipped gear. Power level is `10×level` plus skill ranks and horcrux tier. `max_level` is no longer a hard gate, so a strong character can farm a low place for its essence. Bots train one rank, feed a few junk items, and sometimes walk back for an essence they still need. The hero page holds the inventory grid; `/pack` redirects there. | Rey wanted an MU-style bag, drops worth returning for, and one or two upgrade paths the bots can use. | Rey |
| 23 | **Auto mode for humans.** `auto_mode` plus `auto_profile_id` (one of the six personalities). The bot phase runs for that character too. They stay `is_bot = 0` with an account. The website cannot start a run. | A player can hand the between-run chores (gear, shop, destination, points) to the same rules the rivals use. | Rey |
| 24 | **Best map inside auto mode.** `auto_place` is `profile` (the personality's risk) or `best` (hardest place a stat preview can clear; otherwise town). | A player who wants the chores automated can still refuse a road that looks like a defeat. | Rey |
| 25 | **Legendary titles come from `legendary_names`.** Common–epic gear keeps prefix + template + suffix ("of the Owl"). A legendary combines a base for its weapon or armor with an epithet for its strongest stat (life → Leeching), or, less often and only once `min_ilvl` allows, a unique name such as Excalibur or Ragnarok. The string is stored on the item instance. | Rey wanted Diablo-style legendary names without giving up the animal titles on ordinary loot. | Rey |
| 26 | **Regions generate when someone reaches the end.** At the start of a run, if any active character's level is at least the world's highest `rec_max_level`, the simulation inserts the next themed region (places, monsters, loot, essence) and the effective level cap rises to that region's top. The authored cap of 30 stays the floor. Dry-run rolls it back. The website never generates a map. | The three hand-drawn maps end at the cap. The world should keep opening as Rey and the bots arrive. | Rey |
| 27 | **A newcomer on 10% of runs, cap 48.** `spawn_chance` is 0.10, so most runs add nobody. On a hit: a random personality, level 1 or inside the top window but never the current highest level, with rolled gear above level 1, and a "A hero has been born" letter. The six profiles stay. 1000 was rejected because every bot delves and enters the arena. | A birth every run would fill the town too fast. | Rey |
| 28 | **XP to next level compounds by 5% per level** on top of `100 · L^1.6` (`xp.growth` in `balance.json`). | A pure power curve flattens: each new level barely costs more than the last. The percent keeps the gap widening, in the same spirit as Ragnarok's base EXP, without a pure exponential that would outrun monster XP. | Rey |
| 29 | **A generated dungeon is a climb.** The last place of a new region spans `dungeon_band_width` (11) levels. Trash steps up the band; the boss is always `rec_max`. Depth pressure makes a mid-band monster a real threat to someone at the floor. Trash cannot roll the boss-tier templates; the boss and the clear chest can. | Rey wanted a 40–50 dungeon to stay hard at 40, with the boss at 50 and the best loot on that boss. Failing the clear while under-levelled is the intended result unless the gear is ahead. | Rey |
| 30 | **Premium store with play money (§16).** Every character, bots and newcomers included, starts with **$100.00** (`premium.starting_cash_cents`). Titles, wings, auras, pets, and frames add bonus stats and power. Every **active** owned piece counts; one piece per category is equipped for the look (§16.3, collection tab on the Hero page). Gold bundles turn dollars into gold. There is no real payment and no way to earn more dollars in v1. Bots buy at most one piece per run with a fixed rule; auto-mode humans never spend dollars. | Rey wanted the mobile-RPG "HP bonus / attack bonus" feel and cosmetics everyone, bots included, can show off. | Rey |
| 31 | **Mobile-style bonus layer.** Percent bonuses (`hp_pct`, `attack_pct`, `defense_pct`) after the flat sums, plus four new stats: lifesteal, armor pierce, damage reduction, and XP bonus. Only bonus sources set them, not gear. The arena now applies the same bonus map as trips, so skills and the horcrux count in duels too. | One bonus path for trips, arena, the best-map preview, and the sheet. The arena leaving out skills and the horcrux was an oversight. | Rey + design |
| 32 | **The forge combines two pieces.** Same template slot, both unequipped. Gold is spent either way. The attempt cracks (both destroyed), yields a new piece at the better rarity, or sings (one tier higher, or a stronger legendary). Odds improve with the lower piece's rarity and with LUK. The website shows `Forge::quote`. Bots do not forge. | A gold sink that can fail or turn two spare pieces into something finer, without a recipe list. | Rey |
| 33 | **Item drawings come from game-icons.net.** `ItemArt` maps slot, weapon class, handedness, and name keywords to a local SVG. The site tints it with the rarity color. Files live in the repo (CC BY 3.0). The footer and `/credits` attribute Lorc, Delapouite, Skoll, and Carl Olsen. | Gear had no pictures. The mapping stays stable when affixes and legendary titles change the display name. | Rey |
| 34 | **Painted item icons replace the game-icons.net set.** Weapons, armor, shields, jewelry, and potions are 128×128 WebP from Flare, OSARE, Ravenmore, Scrittl, and Kit (CC0 or CC BY 3.0). No commercial-game rips. Each class keeps several icons where the packs have them; `ItemArt` hashes the template code into a plain-to-fancy window that shifts with rarity. A two-handed weapon still uses a two-handed drawing. Smoke and bandage stay SVG fallbacks. The rarity color glows a painting instead of masking it into a silhouette. | The silhouette set read as line art. Painted icons should differ from bow to bow and still match the weapon in hand. | Rey |

---

## 15. Arena (PvP)

A knockout bracket, separate from expeditions. Rey starts it by hand. The website only records who wants in.

### 15.1 Who enters

| Who | How |
|---|---|
| Humans | `POST /arena/enter` sets `characters.arena_entered`. The website user can already update `characters`, so no new grant is required. `DELETE /arena/enter` clears the flag. One character, one flag. |
| Bots | Every active bot is in every bracket, founders and newcomers alike. They do not opt in. |

Fewer than two entrants prints a message and writes nothing (exit 0). A human who never signed up is not in the bracket and gets no arena mail.

Signing up does not change the map order. An expedition and an arena are different intentions.

### 15.2 The command

`php artisan delve:arena` (class `RunArena`, pipeline `App\Simulation\ArenaRunner`). Same connection as expeditions (`sim` / `delve_sim`).

| Flag | Effect |
|---|---|
| *(none)* | Draw the bracket, fight it, pay prizes, send mail. |
| `--dry-run` | Resolve and print the bracket. Writes nothing, including no tournament row. |
| `--seed=N` | Fix the seed. Without it, the process draws one with `random_int` in the console command (not in `app/Game`). |
| `--force-unlock` | If an arena row is still `running` and its heartbeat is at least `STALE_RUN_MINUTES` old, mark it `failed` and exit. Signups stay `waiting`. |

**Exit codes:** `0` ok (including "not enough entrants") · `1` error · `2` blocked (the shared lock is held, an expedition is still `running`, or an arena is still `running`).

There is no "Fight now" button and no `Artisan::call` from HTTP code.

### 15.3 Locking and crash safety

1. `GET_LOCK('delvebound_simulation', 0)` — the same lock as `delve:run` (§10.3).
2. Refuse if a `simulation_runs` row is `running`, or if an `arena_tournaments` row is `running`.
3. If at least two characters are in (every active bot, plus humans with `arena_entered`), one transaction inserts the tournament as `running` and commits that row. The website's `run.guard` then refuses writes.
4. A second transaction loads fighters, resolves the bracket, and writes matches, prizes, consumable use, mail, a `consumed` signup row per entrant, and `status='completed'` together. It clears `arena_entered` on those characters.
5. If that second transaction throws, it rolls back (no prizes, humans stay signed up) and the tournament row is marked `failed`.
6. A dead process leaves the tournament `running` and holds no lock (MySQL releases `GET_LOCK` on disconnect). The next `delve:arena` exits 2 until `--force-unlock`. Because the prize transaction rolled back, nothing was paid twice.
7. `uq_arena_one_running` is the same generated-column trick as `uq_runs_one_running`. `uq_mail_arena (arena_tournament_id, character_id)` stops a second letter for the same bout.

Dry-run only reads. It resolves the bracket in memory and prints it, and it does not insert a tournament row.

### 15.4 The bracket

Seeded shuffle (Fisher–Yates on `Rng::arena($seed)`, SHA-256 of `"<seed>:arena"`). No `shuffle()`.

Pair the list in order: 1st vs 2nd, 3rd vs 4th, …. If the count is odd, the last fighter receives a **bye** and moves on without a prize. Winners, in slot order, form the next round. Repeat until one champion remains. Later rounds are not shuffled again.

Each duel uses `Rng::duel($seed, $round, $slot, $idA, $idB)` so the same seed and the same entrants replay the same blows. The two ids are sorted inside the seed string.

### 15.5 A duel

Both fighters are snapshotted like a trip: derived stats from `Stats::derived`, including the skill, horcrux, and premium bonuses (§3.2.1; before decision 31 the arena left these out), injury modifiers, and one drink of each trip-start belt buff (Might, Stoneskin, Lucky Charm). Those buffs are consumed once for the whole tournament, not once per round.

| Rule | What happens |
|---|---|
| Start | Each bout sets both fighters to full HP. HP does not carry into the next round. |
| Belt | Healing potions stay on the belt across rounds and are gone once drunk. Smoke bombs and retreat are not used. |
| Turn | Initiative is `speed + randint(0, initiative_die)`. The lower character id is rolled first so the sequence is stable. Ties go to the lower id. |
| Action | Below `potion_threshold_pct`, drink the same potion `Combat::choosePotion` would drink on a trip. Otherwise `Combat::resolveAttack` (dodge, defense or magic resistance, variance, crit). |
| End | The bout ends when one fighter's HP reaches 0 (**knockout**). |
| Round cap | If `combat.max_rounds` passes with both still up, the higher remaining HP fraction wins (`time`). Ties break to higher HP, then the lower character id. |

A knockout does **not** apply the injury rule, does not drop gear, and does not change trip counters. Used potions and trip-start tonics are still consumed.

### 15.6 Prizes **[T]**

From `balance.json` → `arena`, multiplied by the fighter's own level. A bye is not a win.

| Prize | Formula |
|---|---|
| Each knockout win | `win_gold_per_level × level` gold and `win_xp_per_level × level` XP |
| Champion, on top of their wins | `champion_gold_per_level × level` gold and `champion_xp_per_level × level` XP |

XP goes through `Progression::applyXp`, so a level-up still grants its usual points and level gold. Starting values: 8 gold and 6 XP per win per level; champion bonus 40 gold and 20 XP per level.

### 15.7 Mail and the page

Each entrant gets one `mail` row, `mail_type='arena_report'`, `arena_tournament_id` set, `trip_id` null. The payload `outcome` is `champion` or `eliminated`. Bot memory only reads `trip_report`, so an arena letter does not change where a bot delves next.

`/arena` shows the signup button, the humans who are waiting, the bots (marked as always entering), the latest completed bracket with a blow-by-blow log, and recent champions. Battle events use the same `ev` vocabulary as trip logs (`hit`, `dodge`, `potion`, plus `time`).

### 15.8 Tables

Added by migration `2026_09_27_164500_create_arena_tables` (not by editing `001_schema.sql`).

```sql
arena_tournaments (
  id, status ENUM('running','completed','failed','abandoned'),
  seed, started_at, heartbeat_at, finished_at, host, pid,
  champion_id NULL, entrant_count, error_text,
  running_guard GENERATED, UNIQUE uq_arena_one_running
)
arena_signups (
  id, character_id, status ENUM('waiting','consumed','withdrawn'),
  created_at, consumed_tournament_id NULL,
  waiting_character_id GENERATED, UNIQUE uq_arena_one_waiting
)
arena_matches (
  id, tournament_id, round_no, slot_no,
  character_a_id, character_b_id NULL, winner_id,
  rounds, a_hp_end, b_hp_end NULL,
  reason ENUM('knockout','time','bye'), log JSON NULL,
  UNIQUE (tournament_id, round_no, slot_no)
)
```

`mail.mail_type` gains `arena_report`, and `mail.arena_tournament_id` is added with `uq_mail_arena`.

`characters.arena_entered` (migration `2026_09_27_170000_character_arena_entered`) is the human's intention. The sim writes `arena_signups` when the bracket is saved, one `consumed` row per entrant, so the draw stays auditable. `SELECT` on `delvebound.*` already lets the website read the bracket. The sim user already has DML on `delvebound.*`, which covers tables created later.

---

## 16. Premium Store

A second shop at `/premium` sells **cosmetics with stats**: titles, wings, auras, pets, and portrait
frames, plus gold bundles. It is paid in **play dollars**, not gold. Nothing is real money: there is no
payment provider and no network call. The rules live once in `App\Game\Premium` (pure) and are used by
the website (`PremiumService`), the bots, the trip, the arena, and the best-map preview.

### 16.1 The wallet **[T]**

- Every character starts with **$100.00** (`balance.json` → `premium.starting_cash_cents` = 10000).
- `characters.premium_cash` is `NULL` until the first purchase. `NULL` reads as the starting
  allowance, so existing characters, the six seeded bots, newcomers (§8.0), and new sign-ups all have
  $100 without a backfill, and the number lives only in `balance.json`.
- Dollars are only spent. v1 has no way to earn more. The cash can never go negative (`UNSIGNED`).

### 16.2 Catalog (seed `050_premium.sql`)

| Category | Slot rule | Examples (price → bonus) |
|---|---|---|
| `title` | many active; one equipped, shown next to the name everywhere | the Wanderer $2.99 (+2% HP) · Kingslayer $14.99 (+6% attack, +10% crit dmg) · Ascendant $29.99 (+5% HP/attack/defense, +10% XP) |
| `wings` | many active; one equipped, drawn either side of the portrait | Feathered $9.99 (+2 speed, +1.5% dodge) · Nightwing $14.99 (+3% lifesteal) · Voidrender $44.99 (+8% attack, +10% pierce, +2% crit) |
| `aura` | many active; one equipped, glow behind the portrait | Ember $7.99 (+3% attack) · Frost $7.99 (+4% defense) · Starlight $19.99 (+3% crit, +15% crit dmg) |
| `pet` | many active; one equipped, sits at the portrait's foot | Owl $9.99 (+10% XP) · Lucky Cat $11.99 (+10% MF, +10% gold) · Baby Dragon $24.99 (+4% attack/HP, +5% pierce) |
| `frame` | many active; one equipped, portrait border | Bronze $1.99 · Gilded $4.99 · Celestial $12.99 |
| `bundle` | not worn; repeatable | Pouch $0.99 → 100 gold · Chest $4.99 → 600 · Dragon's Hoard $9.99 → 1,400 |

Buying the whole catalog costs far more than $100, so the allowance forces a choice. Each piece also
has a `power` value added to the power level while it is active (§6.8). `delve:check-content` flags a non-bundle
piece without bonuses and any bonus key outside `Stats::BONUS_KEYS`.

### 16.3 Owned, active, equipped

A piece moves through three states, the mobile-RPG collection pattern:

- **Owned:** bought once, kept forever.
- **Active:** any number of owned pieces. **Every active piece adds its bonuses and power**, so ten
  active titles stack ten title bonuses (still capped by §3.2.1).
- **Equipped:** at most one active piece per category. It is only the look: the portrait, the name
  badge, and the leaderboard glyphs. Equipping a piece activates it; deactivating an equipped piece
  takes it off. So equipped ⊆ active ⊆ owned, and `Premium::state` enforces it on every read.

A `characters.premium` value saved before activation existed has no `active` key; it reads as every
owned piece active, so nobody loses stats.

### 16.4 Rules (website)

- **Buy** (`POST /premium/buy`, store page): lock the character, check `row_version`, the item exists and
  is sold, it is not already owned (bundles repeat), and the cash covers `price_cents`. A piece becomes
  owned, active, and **equipped at once** in its category (the previously equipped one stays active). A
  bundle adds `grant_gold` to `characters.gold`. One `shop_transactions` row: `action='premium'`,
  `premium_item_id`, `cash_delta`, `cash_after`, `gold_delta` (bundle gold or 0).
- **Hero → Collection** (`/character?tab=collection`) lists every wearable piece by category, owned ones
  first and the rest locked with a store link. Its writes cost nothing:
  `POST /premium/activate` (`code`, `active` true/false; owned pieces only, and a no-op is refused),
  `/premium/activate-all`, `/premium/wear` (`code`, owned), `/premium/take-off` (`category`, it stays active).
- Each write is one transaction behind `auth` + `run.guard`, like every other game write.
- The store previews, for every piece not active, the exact change to the derived stats
  (`Stats::derived` with and without it). React only displays it.

### 16.5 Bots

In the bot phase (§8.2), after the horcrux step, a **bot** (`is_bot = 1`) first activates every owned
piece and equips its priciest owned piece per category, then may buy up to `bot_buys_per_run` (1) pieces. A candidate's gain is
`Premium::score(after) − score(before)`, where the score is the profile's `gear_weights` over the derived
stats (the same units as §8.4) plus `premium.bot_extra_weights` for lifesteal, pierce, damage reduction,
and XP. It buys the largest gain above `bot_min_gain`; ties go to the cheaper piece, then the code. It
never buys a bundle. The step uses no RNG, so it does not shift later bot rolls. Auto-mode humans skip
it: a human's dollars are only spent by that human. The decision is written in the bot transaction
(`premium_cash`, `premium`, ledger rows with `actor='bot'`), and `bot_decisions.decision.premium` lists
what was bought.
