<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Premium store: catalog, play-money cash and worn pieces on characters, ledger columns (DESIGN.md §16). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('premium_items')) {
            DB::unprepared(<<<'SQL'
CREATE TABLE premium_items (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40)  NOT NULL,
  name         VARCHAR(64)  NOT NULL,
  category     ENUM('title','wings','aura','pet','frame','bundle') NOT NULL,
  tier         ENUM('rare','epic','legendary','mythic') NOT NULL DEFAULT 'rare',
  glyph        VARCHAR(8)   NOT NULL DEFAULT '✦',
  style        VARCHAR(20)  NOT NULL DEFAULT 'lantern',
  description  VARCHAR(255) NULL,
  price_cents  INT UNSIGNED NOT NULL,
  bonuses      JSON NULL,
  power        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  grant_gold   INT UNSIGNED NOT NULL DEFAULT 0,
  is_active    BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_premium_items_code (code),
  CONSTRAINT chk_premium_price CHECK (price_cents > 0),
  CONSTRAINT chk_premium_shape CHECK (
        (category = 'bundle' AND grant_gold > 0 AND bonuses IS NULL)
     OR (category <> 'bundle' AND grant_gold = 0))
) ENGINE=InnoDB;
SQL);
        }

        if (! Schema::hasColumn('characters', 'premium_cash')) {
            DB::statement('ALTER TABLE characters
                ADD COLUMN premium_cash INT UNSIGNED NULL AFTER horcrux_progress,
                ADD COLUMN premium JSON NULL AFTER premium_cash');
        }

        if (! Schema::hasColumn('shop_transactions', 'premium_item_id')) {
            DB::statement("ALTER TABLE shop_transactions
                MODIFY COLUMN action ENUM('buy','sell','use','premium') NOT NULL,
                ADD COLUMN premium_item_id SMALLINT UNSIGNED NULL AFTER consumable_id,
                ADD COLUMN cash_delta INT NOT NULL DEFAULT 0 AFTER gold_after,
                ADD COLUMN cash_after INT UNSIGNED NULL AFTER cash_delta,
                ADD CONSTRAINT fk_st_premium FOREIGN KEY (premium_item_id) REFERENCES premium_items(id)");
        }

        if (! DB::table('premium_items')->exists()) {
            DB::unprepared(file_get_contents(database_path('seeders/sql/050_premium.sql')));
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shop_transactions
            DROP FOREIGN KEY fk_st_premium,
            DROP COLUMN cash_after,
            DROP COLUMN cash_delta,
            DROP COLUMN premium_item_id');
        DB::statement("DELETE FROM shop_transactions WHERE action = 'premium'");
        DB::statement("ALTER TABLE shop_transactions MODIFY COLUMN action ENUM('buy','sell','use') NOT NULL");
        DB::statement('ALTER TABLE characters DROP COLUMN premium, DROP COLUMN premium_cash');
        DB::statement('DROP TABLE IF EXISTS premium_items');
    }
};
