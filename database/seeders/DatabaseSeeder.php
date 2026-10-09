<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starter content (DESIGN.md §7, §8.1). Each SQL file runs once: it is skipped when its marker table
 * already has rows, so seeding an existing database changes nothing.
 */
class DatabaseSeeder extends Seeder
{
    private const FILES = [
        '010_content.sql' => 'places',
        '015_shop.sql' => 'shop_listings',
        '020_bots.sql' => 'bot_profiles',
        '030_growth.sql' => 'material_templates',
        '040_legendary_names.sql' => 'legendary_names',
        '050_premium.sql' => 'premium_items',
    ];

    public function run(): void
    {
        foreach (self::FILES as $file => $marker) {
            if (DB::table($marker)->exists()) {
                $this->command?->line("skip  {$file} ({$marker} already has rows)");

                continue;
            }
            DB::unprepared(file_get_contents(database_path('seeders/sql/'.$file)));
            DB::table('schema_migrations')->insertOrIgnore(['version' => 'seed:'.$file]);
            $this->command?->info("seed  {$file}");
        }
        if (! DB::table('places')->where('code', 'brinefen')->exists()) {
            DB::unprepared(file_get_contents(database_path('seeders/sql/012_later_maps.sql')));
            DB::table('schema_migrations')->insertOrIgnore(['version' => 'seed:012_later_maps.sql']);
            $this->command?->info('seed  012_later_maps.sql');
        }
    }
}
