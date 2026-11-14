<?php

namespace Tests\Unit;

use App\Game\Cards;
use App\Game\Premium;
use App\Game\Rng;
use App\Game\Stats;

/** RO-style random cards and sockets (DESIGN.md §6.10). */
class CardsTest extends GameTestCase
{
    public function test_slot_roll_is_seeded_and_rarity_raises_odds(): void
    {
        $balance = self::$balance;
        $a = Cards::rollSlots('common', 0.0, $balance, Rng::trip(42, 1));
        $b = Cards::rollSlots('common', 0.0, $balance, Rng::trip(42, 1));
        $this->assertSame($a, $b);
        $this->assertGreaterThanOrEqual(0, $a);
        $this->assertLessThanOrEqual((int) $balance['cards']['max_slots'], $a);

        $legendarySlots = 0;
        for ($seed = 1; $seed <= 80; $seed++) {
            $legendarySlots += Cards::rollSlots('legendary', 0.5, $balance, Rng::trip($seed, 7));
        }
        $commonSlots = 0;
        for ($seed = 1; $seed <= 80; $seed++) {
            $commonSlots += Cards::rollSlots('common', 0.0, $balance, Rng::trip($seed, 7));
        }
        $this->assertGreaterThan($commonSlots, $legendarySlots);
    }

    public function test_card_drop_is_deterministic_and_feeds_derived(): void
    {
        $balance = self::$balance;
        $monster = ['code' => 'wolf', 'name' => 'Wolf', 'base_name' => 'Wolf', 'level' => 5, 'is_boss' => false];
        $first = Cards::tryDrop($monster, 1.0, 50, $balance, Rng::trip(99, 3));
        $second = Cards::tryDrop($monster, 1.0, 50, $balance, Rng::trip(99, 3));
        $this->assertEquals($first, $second);

        $card = Cards::rollCard($monster, 0.5, $balance, Rng::trip(7, 2));
        $this->assertSame('Wolf Card', $card['display_name']);
        $this->assertNotEmpty($card['bonuses']);

        $item = [
            'id' => 1,
            'card_slots' => 1,
            'cards' => [null],
            'affixes' => [],
            'implicit' => [],
            'power' => 0,
            'armor' => 0,
            'mres' => 0,
            'slot' => 'chest',
            'template_slot' => 'chest',
        ];
        $this->assertTrue(Cards::canInsert($item, $card, 0)[0]);
        Cards::insert($item, $card, 0);
        $this->assertSame('socketed', $card['status']);

        $bonus = Cards::bonusesFromEquipment([$item]);
        $this->assertNotEmpty($bonus);
        $derived = Stats::derived(
            ['str' => 5, 'dex' => 5, 'int' => 5, 'vit' => 5, 'luk' => 5],
            1,
            [$item],
            $balance,
            0,
            [],
            Premium::combinedBonus(['skills' => [], 'horcrux' => ['tier' => 0], 'premium' => ['boosts' => []], 'systems' => null, 'equipment' => [$item]], $balance),
        );
        foreach ($bonus as $key => $value) {
            if (str_ends_with($key, '_pct') || in_array($key, ['lifesteal_pct', 'penetration_pct', 'dmg_reduction_pct', 'xp_pct', 'hp_pct', 'attack_pct', 'defense_pct'], true)) {
                continue;
            }
            if (isset($derived[$key === 'hp' ? 'hp' : $key])) {
                $this->assertGreaterThan(0, $value);
            }
        }
        $pulled = Cards::remove($item, 0);
        $this->assertSame('owned', $pulled['status']);
        $this->assertNull($item['cards'][0]);
    }

    public function test_bot_socket_fills_empty_slots_cheapest_best_first(): void
    {
        $balance = self::$balance;
        $cardA = Cards::rollCard(['name' => 'A', 'base_name' => 'A', 'code' => 'a', 'level' => 3], 0, $balance, Rng::trip(1, 1));
        $cardA['id'] = 10;
        $cardB = Cards::rollCard(['name' => 'B', 'base_name' => 'B', 'code' => 'b', 'level' => 8], 1, $balance, Rng::trip(2, 1));
        $cardB['id'] = 11;
        $state = [
            'is_bot' => true,
            'cards' => [$cardA, $cardB],
            'equipment' => [
                'chest' => [
                    'id' => 5,
                    'card_slots' => 1,
                    'cards' => [null],
                    'affixes' => [],
                    'implicit' => [],
                    'power' => 1,
                    'armor' => 1,
                    'mres' => 0,
                    'slot' => 'chest',
                    'template_slot' => 'chest',
                ],
            ],
            'items' => [],
        ];
        $log = Cards::botSocket($state, $balance);
        $this->assertCount(1, $log);
        $this->assertSame(5, $log[0]['item_id']);
        $this->assertNotNull($state['equipment']['chest']['cards'][0]);
    }
}
