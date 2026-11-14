<?php

namespace Tests\Unit;

use App\Game\Daily;
use App\Game\Premium;
use App\Game\Stats;
use App\Game\Systems;

/** Daily quests and MU2 upgrade tracks (DESIGN.md §17). */
class DailySystemsTest extends GameTestCase
{
    public function test_day_rolls_and_trip_progress_unlock_claims(): void
    {
        $balance = self::$balance;
        $day = Daily::dayKey(new \DateTimeImmutable('2026-11-09 10:00:00', new \DateTimeZone('UTC')), $balance['daily']['timezone']);
        $state = Daily::state(null, $day, $balance);
        Daily::markLogin($state);
        Daily::noteTrip($state, [
            'outcome' => 'cleared',
            'gold_gained' => 120,
            'encounters' => [
                ['result' => 'won', 'monsters' => [['name' => 'a'], ['name' => 'b']]],
                ['result' => 'won', 'monsters' => [['name' => 'c']]],
            ],
        ]);

        $board = Daily::board($state, $balance);
        $byCode = array_column($board, null, 'code');
        $this->assertTrue($byCode['login']['ready']);
        $this->assertTrue($byCode['trips_1']['ready']);
        $this->assertTrue($byCode['clear_1']['ready']);
        $this->assertTrue($byCode['gold_100']['ready']);
        $this->assertSame(3, $byCode['kills_10']['progress']);

        $bag = [];
        [$ok, , $gained] = Daily::claimQuest($state, $bag, 'login', $balance);
        $this->assertTrue($ok);
        $this->assertSame(['jewel_dust' => 1], $gained);
        $this->assertFalse(Daily::claimQuest($state, $bag, 'login', $balance)[0]);
    }

    public function test_jewels_and_excellency_raise_advanced_stats(): void
    {
        $balance = self::$balance;
        $systems = Systems::state(null, $balance);
        $bag = ['jewel_dust' => 5, 'excellent_stone' => 3, 'wing_feather' => 2];

        $this->assertTrue(Systems::canRaiseJewel($systems, $bag, 'fire', $balance)[0]);
        Systems::raiseJewel($systems, $bag, 'fire', $balance);
        Systems::raiseExcellency($systems, $bag, $balance);
        Systems::raiseWing($systems, $bag, $balance);

        $bonus = Systems::bonuses($systems, $balance);
        $this->assertGreaterThan(0, $bonus['fire_atk']);
        $this->assertGreaterThan(0, $bonus['excellency_bonus_pct']);
        $this->assertGreaterThan(0, $bonus['true_damage']);

        $char = [
            'skills' => [],
            'horcrux' => ['tier' => 0],
            'premium' => ['boosts' => []],
            'systems' => $systems,
        ];
        $combined = Premium::combinedBonus($char, $balance);
        $derived = Stats::derived(
            ['str' => 5, 'dex' => 5, 'int' => 5, 'vit' => 5, 'luk' => 5],
            1,
            [],
            $balance,
            0,
            [],
            $combined,
        );
        $this->assertSame(25, $derived['advanced']['elements']['fire']['atk']);
        $this->assertGreaterThan(0, $derived['advanced']['global']['excellency_bonus_pct']);
        $this->assertSame(Systems::power($systems, $balance), 3 + 4 + 5);
    }

    public function test_sigils_and_nexus_raise_elemental_detail(): void
    {
        $balance = self::$balance;
        $systems = Systems::state(null, $balance);
        $bag = ['sigil_ink' => 20];

        foreach (['fire', 'water', 'thunder', 'earth', 'wind'] as $element) {
            $this->assertTrue(Systems::canRaiseSigil($systems, $bag, $element, $balance)[0]);
            Systems::raiseSigil($systems, $bag, $element, $balance);
        }
        $this->assertSame(5, Systems::sigilSum($systems));
        $this->assertTrue(Systems::canRaiseNexus($systems, $bag, $balance)[0]);
        Systems::raiseNexus($systems, $bag, $balance);

        $bonus = Systems::bonuses($systems, $balance);
        $this->assertGreaterThan(0, $bonus['fire_crit_bonus_pct']);
        $this->assertGreaterThan(0, $bonus['final_dmg_bonus_pct']);
        $this->assertGreaterThan(0, $bonus['true_damage']);

        $char = [
            'skills' => [],
            'horcrux' => ['tier' => 0],
            'premium' => ['boosts' => []],
            'systems' => $systems,
        ];
        $combined = Premium::combinedBonus($char, $balance);
        $derived = Stats::derived(
            ['str' => 5, 'dex' => 5, 'int' => 5, 'vit' => 5, 'luk' => 5],
            1,
            [],
            $balance,
            0,
            [],
            $combined,
        );
        $this->assertSame(3.0, $derived['advanced']['elements']['fire']['crit_bonus_pct']);
        $this->assertSame(40, $derived['advanced']['elements']['fire']['dmg_addition']);
        $this->assertGreaterThan(0, $derived['advanced']['global']['final_dmg_bonus_pct']);
        $this->assertSame(Systems::power($systems, $balance), 4 * 5 + 6);
    }

    public function test_bot_upgrade_spends_cheapest_first(): void
    {
        $balance = self::$balance;
        $systems = Systems::state(null, $balance);
        $bag = ['jewel_dust' => 10, 'excellent_stone' => 0, 'wing_feather' => 0, 'sigil_ink' => 0];
        $log = Systems::botUpgrade($systems, $bag, $balance);
        $this->assertNotEmpty($log);
        $this->assertSame('jewel', $log[0]['track']);
        $this->assertLessThan(10, $bag['jewel_dust'] ?? 0);
    }
}
