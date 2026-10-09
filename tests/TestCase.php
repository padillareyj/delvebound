<?php

namespace Tests;

use App\Game\Balance;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const TEST_DATABASE = 'delvebound_test';

    private const SEEDS = ['010_content.sql', '012_later_maps.sql', '015_shop.sql', '020_bots.sql', '030_growth.sql', '040_legendary_names.sql', '050_premium.sql'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['mysql', 'sim', 'admin'] as $name) {
            $database = DB::connection($name)->getDatabaseName();
            if ($database !== self::TEST_DATABASE) {
                throw new RuntimeException("Refusing to run tests: connection '{$name}' points at '{$database}', not ".self::TEST_DATABASE.'.');
            }
        }
        Balance::forget();
    }

    /** Empties every table and reloads the starter content, so each test starts from a fresh world. */
    protected function resetGame(): void
    {
        $admin = DB::connection('admin');
        $tables = array_column($admin->select(
            "SELECT table_name AS name FROM information_schema.tables
              WHERE table_schema = ? AND table_type = 'BASE TABLE' AND table_name NOT IN ('migrations', 'schema_migrations')",
            [self::TEST_DATABASE],
        ), 'name');
        $admin->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $admin->statement("TRUNCATE TABLE `{$table}`");
        }
        $admin->statement('SET FOREIGN_KEY_CHECKS = 1');
        foreach (self::SEEDS as $file) {
            $admin->unprepared(file_get_contents(database_path('seeders/sql/'.$file)));
        }
    }

    /** Registers a player through the real sign-up path and returns [account, character row]. */
    protected function makePlayer(string $username = 'rey', string $weapon = 'rusty_sword'): array
    {
        $account = app(AccountService::class)->register($username, null, 'correct horse battery', ucfirst($username), $weapon);
        $character = (array) DB::table('characters')->where('account_id', $account->id)->first();

        return [$account, $character];
    }
}
