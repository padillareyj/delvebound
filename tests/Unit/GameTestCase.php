<?php

namespace Tests\Unit;

use App\Game\Balance;
use PHPUnit\Framework\TestCase;

/** Pure-core tests: no Laravel app, no database. */
abstract class GameTestCase extends TestCase
{
    protected static array $balance;

    public static function setUpBeforeClass(): void
    {
        self::$balance = Balance::load(dirname(__DIR__, 2).'/config/balance.json');
    }

    protected static function fixture(string $name): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/fixtures/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function fightContext(int $monsterHp = 20, int $monsterAttack = 1, array $encounters = [2, 2], float $dropChance = 0.0): array
    {
        $monster = [
            'id' => 1, 'code' => 'rat', 'name' => 'Rat', 'level' => 1, 'is_boss' => false, 'hp' => $monsterHp,
            'attack' => $monsterAttack, 'defense' => 0, 'mres' => 0, 'speed' => 5, 'crit_pct' => 0, 'dodge_pct' => 0,
            'damage_type' => 'physical', 'xp' => 40, 'gold_min' => 4, 'gold_max' => 4, 'drop_chance' => $dropChance,
            'loot_table' => ['entries' => [['entry_type' => 'nothing', 'weight' => 1, 'template' => null]]],
        ];
        $place = [
            'id' => 2, 'code' => 'greenwood', 'name' => 'Greenwood Forest', 'place_type' => 'forest',
            'min_level' => 1, 'max_level' => 6, 'rec_min_level' => 1, 'rec_max_level' => 3, 'rec_level' => 2,
            'encounter_min' => $encounters[0], 'encounter_max' => $encounters[1], 'boss_chance' => 0,
            'rarity_bonus' => 0, 'gold_mult' => 1, 'boss' => null, 'chest' => null,
            'spawns' => [['monster' => $monster, 'weight' => 1, 'min_group' => 1, 'max_group' => 1]],
        ];
        $town = ['id' => 1, 'code' => 'town', 'name' => 'Lanternford', 'place_type' => 'rest', 'rec_level' => 1,
            'rec_min_level' => 1, 'rec_max_level' => 1, 'max_level' => null, 'min_level' => 1];
        $character = [
            'id' => 1, 'name' => 'Rey', 'level' => 1, 'xp' => 0, 'gold' => 50,
            'stats' => ['str' => 30, 'dex' => 10, 'int' => 5, 'vit' => 30, 'luk' => 5],
            'unspent' => 0, 'retreat_threshold_pct' => 0, 'potion_threshold_pct' => 0,
            'injury_severity' => 0, 'injury_runs_left' => 0, 'best_place_rec' => null, 'trips_total' => 0,
            'equipment' => [[
                'slot' => 'weapon', 'power' => 20, 'armor' => 0, 'mres' => 0, 'scaling_stat' => 'str',
                'implicit' => [], 'affixes' => [],
            ]],
            'belt' => [],
        ];

        return [
            'character' => $character, 'place' => $place, 'town' => $town, 'plan' => 'combat', 'order_id' => null,
            'reject_reason' => null, 'open_places' => [], 'affixes' => [], 'inventory_count' => 0, 'commons' => [],
            'balance' => self::$balance, 'run_seed' => 42, 'run_id' => 1,
        ];
    }
}
