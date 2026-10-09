<?php

namespace Tests\Unit;

use App\Game\Forge;
use App\Game\Rng;
use InvalidArgumentException;

class ForgeTest extends GameTestCase
{
    public function test_two_commons_at_no_luck_are_an_even_crack(): void
    {
        $left = $this->piece(1, 'weapon', 'common', 1);
        $right = $this->piece(2, 'weapon', 'common', 1);
        $quote = Forge::quote($left, $right, 100, 0, self::$balance);

        $this->assertTrue($quote['ok']);
        $this->assertSame(23, $quote['gold']);
        $this->assertSame(['fail' => 50.0, 'craft' => 42.0, 'masterpiece' => 8.0], $quote['chances']);
    }

    public function test_finer_inputs_and_luck_shift_the_odds_toward_a_masterpiece(): void
    {
        $left = $this->piece(1, 'chest', 'epic', 12);
        $right = $this->piece(2, 'chest', 'epic', 10);
        $weights = Forge::weights($left, $right, Forge::luckFactor(20, self::$balance), self::$balance);

        $this->assertEqualsWithDelta(230.0, $weights['fail'], 0.001);
        $this->assertEqualsWithDelta(432.6, $weights['craft'], 0.001);
        $this->assertEqualsWithDelta(301.6, $weights['masterpiece'], 0.001);
        $this->assertSame(
            ['fail' => 23.9, 'craft' => 44.9, 'masterpiece' => 31.2],
            Forge::chancePercents($weights),
        );
        $this->assertSame(333, Forge::cost($left, $right, self::$balance));
    }

    public function test_a_mismatch_or_a_short_purse_refuses_before_any_roll(): void
    {
        $sword = $this->piece(1, 'weapon', 'common', 1);
        $helm = $this->piece(2, 'head', 'common', 1);
        $mismatch = Forge::quote($sword, $helm, 100, 0, self::$balance);
        $this->assertFalse($mismatch['ok']);
        $this->assertNull($mismatch['chances']);

        $poor = Forge::quote($sword, $this->piece(3, 'weapon', 'common', 1), 10, 0, self::$balance);
        $this->assertFalse($poor['ok']);
        $this->assertSame(23, $poor['gold']);
        $this->assertSame('The forge costs 23 gold.', $poor['reason']);
    }

    public function test_each_outcome_follows_the_rarity_rule_and_the_same_seed_replays(): void
    {
        $templates = [$this->template(7, 'weapon', 'Iron Sword', 1)];
        $left = $this->piece(4, 'weapon', 'common', 4);
        $right = $this->piece(9, 'weapon', 'rare', 6);

        $cracked = $this->forced('fail', $left, $right, $templates);
        $this->assertSame('fail', $cracked['outcome']);
        $this->assertNull($cracked['item']);
        $this->assertSame('The forge cracks. Both pieces are lost.', Forge::message($cracked));

        $made = $this->forced('craft', $left, $right, $templates);
        $this->assertSame('craft', $made['outcome']);
        $this->assertSame('rare', $made['item']['rarity']);
        $this->assertSame(5, $made['item']['item_level']);
        $this->assertSame('craft', $made['item']['source']);
        $this->assertSame(2, count($made['item']['affixes']));
        $this->assertSame('The forge yields '.$made['item']['display_name'].'.', Forge::message($made));

        $sung = $this->forced('masterpiece', $left, $right, $templates);
        $this->assertSame('masterpiece', $sung['outcome']);
        $this->assertSame('epic', $sung['item']['rarity']);
        $this->assertSame(6, $sung['item']['item_level']);
        $this->assertSame(3, count($sung['item']['affixes']));

        $again = $this->forced('craft', $left, $right, $templates);
        $this->assertSame($made['item']['display_name'], $again['item']['display_name']);
        $this->assertSame($made['item']['affixes'], $again['item']['affixes']);
        $this->assertSame($made['item']['power'], $again['item']['power']);
    }

    public function test_two_legendaries_stay_legendary_and_step_up_in_item_level(): void
    {
        $templates = [$this->template(3, 'weapon', 'Old Blade', 1)];
        $result = $this->forced(
            'masterpiece',
            $this->piece(1, 'weapon', 'legendary', 8),
            $this->piece(2, 'weapon', 'legendary', 10),
            $templates,
        );

        $this->assertSame('legendary', $result['item']['rarity']);
        $this->assertSame(10, $result['item']['item_level']);
        $this->assertSame(4, count($result['item']['affixes']));
    }

    public function test_different_slots_cannot_be_combined(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Forge::combine(
            $this->piece(1, 'weapon', 'common', 1),
            $this->piece(2, 'feet', 'common', 1),
            [],
            [],
            [],
            0,
            self::$balance,
            new Rng('forge-test'),
        );
    }

    /** @param list<array> $templates */
    private function forced(string $outcome, array $left, array $right, array $templates): array
    {
        $balance = self::$balance;
        $balance['forge']['fail_weight'] = $outcome === 'fail' ? 1 : 0;
        $balance['forge']['craft_weight'] = $outcome === 'craft' ? 1 : 0;
        $balance['forge']['masterpiece_weight'] = $outcome === 'masterpiece' ? 1 : 0;

        return Forge::combine($left, $right, $templates, $this->affixes(), [], 0, $balance, new Rng('forge-pin'));
    }

    private function piece(int $id, string $slot, string $rarity, int $ilvl): array
    {
        return ['id' => $id, 'slot' => $slot, 'rarity' => $rarity, 'item_level' => $ilvl, 'equipped' => false];
    }

    private function template(int $id, string $slot, string $name, int $req): array
    {
        return [
            'id' => $id,
            'code' => 'iron_sword',
            'name' => $name,
            'slot' => $slot,
            'item_type' => 'sword',
            'scaling_stat' => 'str',
            'two_handed' => false,
            'base_power' => 8,
            'base_armor' => 0,
            'base_mres' => 0,
            'implicit' => [],
            'req_level' => $req,
            'base_value' => 12,
        ];
    }

    /** @return list<array> */
    private function affixes(): array
    {
        $rows = [];
        foreach ([
            ['keen', 'Keen', 'prefix', 'crit_pct'],
            ['heavy', 'Heavy', 'prefix', 'attack'],
            ['fox', 'of the Fox', 'suffix', 'dex'],
            ['bear', 'of the Bear', 'suffix', 'vit'],
        ] as $i => [$code, $name, $kind, $stat]) {
            $rows[] = [
                'id' => $i + 1,
                'code' => $code,
                'name' => $name,
                'kind' => $kind,
                'stat_key' => $stat,
                'slot_groups' => ['weapon'],
                'min_ilvl' => 1,
                'weight' => 1,
                'value_min_base' => 1,
                'value_max_base' => 1,
                'value_per_ilvl' => 0,
            ];
        }

        return $rows;
    }
}
