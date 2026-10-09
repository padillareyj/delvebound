<?php

namespace Tests\Unit;

use App\Game\Newcomer;
use App\Game\Progression;
use App\Game\Rng;

class NewcomerTest extends GameTestCase
{
    public function test_a_newcomer_is_level_one_or_below_the_leader_and_replayable(): void
    {
        $balance = self::$balance;
        $balance['newcomers']['spawn_chance'] = 1;
        $balance['newcomers']['fresh_chance'] = 0;
        $templates = $this->templates();
        $first = Newcomer::plan(6, 12, $this->profiles(), ['Brakka the Wall'], $templates, [], Rng::arrival(42, 6), $balance);
        $again = Newcomer::plan(6, 12, $this->profiles(), ['Brakka the Wall'], $templates, [], Rng::arrival(42, 6), $balance);

        $this->assertSame($first, $again);
        $this->assertGreaterThanOrEqual(7, $first['level']);
        $this->assertLessThanOrEqual(11, $first['level']);
        $this->assertSame(Progression::xpSpentToReach($first['level'], $balance), $first['total_xp']);
        $this->assertSame('A hero has been born', $first['subject']);
        $this->assertNotSame('Brakka the Wall', $first['name']);
        $slots = array_column($first['items'], 'slot');
        $this->assertContains('weapon', $slots);
        $this->assertNotContains('offhand', $slots);
        foreach ($first['items'] as $item) {
            $this->assertLessThanOrEqual($first['level'], $item['req_level']);
            $this->assertSame('starter', $item['source']);
        }
        $weapon = null;
        foreach ($first['items'] as $item) {
            if ($item['slot'] === 'weapon') {
                $weapon = $item;
            }
        }
        $this->assertSame($first['profile_id'] === 2 ? 'dex' : 'str', $weapon['scaling_stat']);
    }

    public function test_level_one_is_the_only_choice_while_everyone_is_new(): void
    {
        $balance = self::$balance;
        $balance['newcomers']['spawn_chance'] = 1;
        $plan = Newcomer::plan(6, 1, $this->profiles(), [], $this->templates(), [], Rng::arrival(3, 6), $balance);

        $this->assertSame(1, $plan['level']);
        $this->assertContains($plan['items'][0]['code'], ['rusty_sword', 'worn_dagger']);
        $this->assertSame(['cloth_tunic', 'soft_boots'], array_slice(array_column($plan['items'], 'code'), 1));
        $this->assertSame([], $plan['items'][0]['affixes']);
    }

    public function test_a_fresh_roll_stays_at_level_one_and_the_cap_stops_arrivals(): void
    {
        $balance = self::$balance;
        $balance['newcomers']['spawn_chance'] = 1;
        $balance['newcomers']['fresh_chance'] = 1;
        $plan = Newcomer::plan(6, 20, $this->profiles(), [], $this->templates(), [], Rng::arrival(9, 6), $balance);

        $this->assertSame(1, $plan['level']);
        $this->assertNull(Newcomer::plan(48, 20, $this->profiles(), [], $this->templates(), [], Rng::arrival(9, 48), $balance));
    }

    public function test_surnames_stay_unique_until_the_list_is_used_up(): void
    {
        $rng = Rng::arrival(5, 6);
        $taken = ['Someone Ivy', 'Other Ivy'];
        $surnames = [];
        for ($i = 0; $i < 40; $i++) {
            $name = Newcomer::name($rng, $taken, 0);
            $this->assertLessThanOrEqual(32, strlen($name));
            $taken[] = $name;
            $surnames[] = substr($name, (int) strrpos($name, ' ') + 1);
        }

        $this->assertCount(40, array_unique($surnames));
        $this->assertNotContains('Ivy', $surnames);
    }

    public function test_some_newcomers_are_known_by_one_name(): void
    {
        $rng = Rng::arrival(11, 6);
        $taken = ['Vex'];
        $single = 0;
        for ($i = 0; $i < 40; $i++) {
            $name = Newcomer::name($rng, $taken, 0.35);
            $this->assertNotSame('Vex', $name);
            $taken[] = $name;
            if (! str_contains($name, ' ')) {
                $single++;
            }
        }

        $this->assertGreaterThan(0, $single);
        $this->assertLessThan(40, $single);
        $this->assertStringNotContainsString(' ', Newcomer::name(Rng::arrival(2, 6), ['Vex'], 1));
    }

    public function test_about_one_run_in_ten_births_a_hero(): void
    {
        $born = 0;
        for ($seed = 1; $seed <= 200; $seed++) {
            $plan = Newcomer::plan(6, 8, $this->profiles(), [], $this->templates(), [], Rng::arrival($seed, 6), self::$balance);
            $again = Newcomer::plan(6, 8, $this->profiles(), [], $this->templates(), [], Rng::arrival($seed, 6), self::$balance);
            $this->assertSame($plan === null, $again === null);
            if ($plan !== null) {
                $born++;
            }
        }

        $this->assertGreaterThanOrEqual(10, $born);
        $this->assertLessThanOrEqual(30, $born);
        $quiet = self::$balance;
        $quiet['newcomers']['spawn_chance'] = 0;
        $this->assertNull(Newcomer::plan(6, 8, $this->profiles(), [], $this->templates(), [], Rng::arrival(1, 6), $quiet));
    }

    /** @return list<array{id: int, archetype: string, preferred_scaling: string, retreat_threshold_pct: int}> */
    private function profiles(): array
    {
        return [
            ['id' => 1, 'archetype' => 'tank', 'preferred_scaling' => 'str', 'retreat_threshold_pct' => 20],
            ['id' => 2, 'archetype' => 'rogue', 'preferred_scaling' => 'dex', 'retreat_threshold_pct' => 35],
        ];
    }

    /** @return list<array> */
    private function templates(): array
    {
        $rows = [
            ['id' => 1, 'code' => 'rusty_sword', 'name' => 'Rusty Sword', 'slot' => 'weapon', 'scaling_stat' => 'str', 'two_handed' => false, 'req_level' => 1],
            ['id' => 2, 'code' => 'worn_dagger', 'name' => 'Worn Dagger', 'slot' => 'weapon', 'scaling_stat' => 'dex', 'two_handed' => false, 'req_level' => 1],
            ['id' => 3, 'code' => 'cloth_tunic', 'name' => 'Cloth Tunic', 'slot' => 'chest', 'scaling_stat' => null, 'two_handed' => false, 'req_level' => 1],
            ['id' => 4, 'code' => 'soft_boots', 'name' => 'Soft Boots', 'slot' => 'feet', 'scaling_stat' => null, 'two_handed' => false, 'req_level' => 1],
            ['id' => 5, 'code' => 'greatsword', 'name' => 'Greatsword', 'slot' => 'weapon', 'scaling_stat' => 'str', 'two_handed' => true, 'req_level' => 8],
            ['id' => 6, 'code' => 'longbow', 'name' => 'Longbow', 'slot' => 'weapon', 'scaling_stat' => 'dex', 'two_handed' => true, 'req_level' => 8],
            ['id' => 7, 'code' => 'kite', 'name' => 'Kite Shield', 'slot' => 'offhand', 'scaling_stat' => null, 'two_handed' => false, 'req_level' => 8],
            ['id' => 8, 'code' => 'mail', 'name' => 'Mail Shirt', 'slot' => 'chest', 'scaling_stat' => null, 'two_handed' => false, 'req_level' => 8],
        ];

        return array_map(fn ($row) => $row + [
            'base_power' => 4, 'base_armor' => 1, 'base_mres' => 0, 'base_value' => 10, 'implicit_mods' => null,
        ], $rows);
    }
}
