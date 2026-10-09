<?php

namespace Tests\Unit;

use App\Game\Combat;
use App\Game\Growth;
use App\Game\Premium;
use App\Game\Rng;
use App\Game\Stats;
use App\Game\Trip;

/** Premium store rules and the bonus stats they feed (DESIGN.md §3.2.1, §4.4, §16). */
class PremiumTest extends GameTestCase
{
    private static function catalog(): array
    {
        $rows = [
            ['code' => 'title_a', 'name' => 'Title A', 'category' => 'title', 'price_cents' => 500, 'bonuses' => ['attack_pct' => 10], 'power' => 5],
            ['code' => 'title_b', 'name' => 'Title B', 'category' => 'title', 'price_cents' => 900, 'bonuses' => ['attack_pct' => 30, 'hp_pct' => 10], 'power' => 9],
            ['code' => 'wings_a', 'name' => 'Wings A', 'category' => 'wings', 'price_cents' => 4000, 'bonuses' => ['hp_pct' => 20, 'lifesteal_pct' => 5], 'power' => 20],
            ['code' => 'pouch', 'name' => 'Pouch', 'category' => 'bundle', 'price_cents' => 100, 'bonuses' => [], 'power' => 0, 'grant_gold' => 100],
        ];
        $catalog = [];
        foreach ($rows as $i => $row) {
            $catalog[$row['code']] = Premium::row($row + [
                'id' => $i + 1, 'tier' => 'rare', 'glyph' => '*', 'style' => 'lantern', 'description' => null,
                'grant_gold' => 0, 'is_active' => 1, 'sort_order' => $i,
            ]);
        }

        return $catalog;
    }

    private static function botState(?int $cash = null): array
    {
        return [
            'is_bot' => true,
            'level' => 5,
            'stats' => ['str' => 20, 'dex' => 8, 'int' => 5, 'vit' => 15, 'luk' => 5],
            'equipment' => [['slot' => 'weapon', 'power' => 10, 'armor' => 0, 'mres' => 0, 'scaling_stat' => 'str', 'implicit' => [], 'affixes' => []]],
            'profile' => ['gear_weights' => ['hp' => 1, 'attack' => 1, 'defense' => 1, 'mres' => 0.5, 'crit' => 0.5, 'dodge' => 0.5, 'speed' => 0.2, 'mf' => 0.1]],
            'skills' => [],
            'horcrux' => ['tier' => 0, 'progress' => 0],
            'premium' => Premium::state($cash, null, self::catalog(), self::$balance),
        ];
    }

    public function test_untouched_cash_is_the_starting_allowance(): void
    {
        $state = Premium::state(null, null, self::catalog(), self::$balance);
        $this->assertSame((int) self::$balance['premium']['starting_cash_cents'], $state['cash']);
        $this->assertSame('$100.00', Premium::money($state['cash']));
        $this->assertSame(0, Premium::state(0, null, self::catalog(), self::$balance)['cash']);
    }

    public function test_state_drops_unknown_codes_and_unowned_worn_pieces(): void
    {
        $json = json_encode(['owned' => ['title_a', 'ghost', 'pouch'], 'equipped' => ['title' => 'title_a', 'wings' => 'wings_a', 'aura' => 'title_a']]);
        $state = Premium::state(300, $json, self::catalog(), self::$balance);

        $this->assertSame(['title_a'], $state['owned']);
        $this->assertSame(['title' => 'title_a'], $state['equipped']);
        $this->assertSame(['attack_pct' => 10.0], Premium::bonuses($state['gear']));
    }

    public function test_buying_spends_cash_and_wears_the_piece(): void
    {
        $catalog = self::catalog();
        $state = Premium::state(1000, null, $catalog, self::$balance);

        $this->assertTrue(Premium::canBuy($catalog['title_a'], $state)[0]);
        $this->assertSame(0, Premium::buy($state, $catalog['title_a'], $catalog));
        $this->assertSame(500, $state['cash']);
        $this->assertSame(['title' => 'title_a'], $state['equipped']);

        [$ok, $why] = Premium::canBuy($catalog['title_a'], $state);
        $this->assertFalse($ok);
        $this->assertStringContainsString('already own', $why);
        $this->assertFalse(Premium::canBuy($catalog['title_b'], $state)[0]);
    }

    public function test_bundles_repeat_and_pay_gold(): void
    {
        $catalog = self::catalog();
        $state = Premium::state(250, null, $catalog, self::$balance);
        $this->assertSame(100, Premium::buy($state, $catalog['pouch'], $catalog));
        $this->assertTrue(Premium::canBuy($catalog['pouch'], $state)[0]);
        $this->assertSame(100, Premium::buy($state, $catalog['pouch'], $catalog));
        $this->assertSame(50, $state['cash']);
        $this->assertSame([], $state['owned']);
        $this->assertFalse(Premium::canWear($catalog['pouch'], $state)[0]);
    }

    public function test_combined_bonus_stacks_growth_and_premium(): void
    {
        $catalog = self::catalog();
        $char = ['skills' => ['keen_edge' => 1], 'horcrux' => ['tier' => 1]];
        $char['premium'] = Premium::state(null, json_encode(['owned' => ['title_a'], 'equipped' => ['title' => 'title_a']]), $catalog, self::$balance);
        $bonus = Premium::combinedBonus($char, self::$balance);

        $growth = Growth::bonuses(['keen_edge' => 1], 1, self::$balance);
        $this->assertEqualsWithDelta($growth['attack'], $bonus['attack'], 1e-9);
        $this->assertEqualsWithDelta(10.0, $bonus['attack_pct'], 1e-9);
    }

    public function test_power_level_counts_worn_premium(): void
    {
        $catalog = self::catalog();
        $gear = Premium::gear(['title' => 'title_b', 'wings' => 'wings_a'], $catalog);
        $this->assertSame(29, Premium::power($gear));
        $this->assertSame(Growth::powerLevel(3, [], 0, self::$balance) + 29, Growth::powerLevel(3, [], 0, self::$balance, 29));
    }

    public function test_penetration_and_reduction_change_damage_without_extra_rolls(): void
    {
        $attacker = ['attack' => 40, 'damage_type' => 'physical', 'crit' => 0, 'crit_mult' => 1.5];
        $target = ['hp' => 1000, 'defense' => 40, 'mres' => 0, 'dodge' => 0];

        $plain = $target;
        $a = $attacker;
        $base = Combat::resolveAttack($a, $plain, Rng::trip(1, 1), self::$balance)['dmg'];

        $pierced = $target;
        $a = $attacker + ['penetration' => 50];
        $rng = Rng::trip(1, 1);
        $more = Combat::resolveAttack($a, $pierced, $rng, self::$balance)['dmg'];

        $tough = $target + ['dmg_reduction' => 30];
        $a = $attacker;
        $less = Combat::resolveAttack($a, $tough, Rng::trip(1, 1), self::$balance)['dmg'];

        $this->assertGreaterThan($base, $more);
        $this->assertLessThan($base, $less);

        $plainRng = Rng::trip(1, 1);
        $a = $attacker;
        $t = $target;
        Combat::resolveAttack($a, $t, $plainRng, self::$balance);
        $effectRng = Rng::trip(1, 1);
        $a = $attacker + ['penetration' => 50, 'lifesteal' => 10, 'hp' => 10, 'max_hp' => 100];
        $t = $target + ['dmg_reduction' => 30];
        Combat::resolveAttack($a, $t, $effectRng, self::$balance);
        $this->assertSame($plainRng->random(), $effectRng->random(), 'the new stats must not consume extra rolls');
    }

    public function test_lifesteal_heals_the_attacker_up_to_max_hp(): void
    {
        $attacker = ['attack' => 40, 'damage_type' => 'physical', 'crit' => 0, 'crit_mult' => 1.5, 'hp' => 50, 'max_hp' => 100, 'lifesteal' => 50];
        $target = ['hp' => 1000, 'defense' => 0, 'mres' => 0, 'dodge' => 0];
        $event = Combat::resolveAttack($attacker, $target, Rng::trip(3, 1), self::$balance);

        $this->assertSame('hit', $event['ev']);
        $this->assertSame((int) floor($event['dmg'] * 0.5 + 0.5), $event['heal']);
        $this->assertSame(50 + $event['heal'], $attacker['hp']);

        $full = ['hp' => 100, 'max_hp' => 100, 'lifesteal' => 50];
        $this->assertSame(0, Combat::lifesteal($full, 40));
        $this->assertSame(100, $full['hp']);
    }

    public function test_xp_bonus_raises_trip_xp(): void
    {
        $ctx = $this->fightContext();
        $plain = Trip::resolve($ctx, Rng::trip(42, 1));

        $catalog = self::catalog();
        $catalog['owl'] = Premium::row([
            'id' => 99, 'code' => 'owl', 'name' => 'Owl', 'category' => 'pet', 'tier' => 'rare', 'glyph' => '*', 'style' => 'lantern',
            'description' => null, 'price_cents' => 100, 'bonuses' => ['xp_pct' => 50], 'power' => 1, 'grant_gold' => 0, 'is_active' => 1,
        ]);
        $ctx['character']['premium'] = Premium::state(null, json_encode(['owned' => ['owl'], 'equipped' => ['pet' => 'owl']]), $catalog, self::$balance);
        $boosted = Trip::resolve($ctx, Rng::trip(42, 1));

        $this->assertSame($plain['outcome'], $boosted['outcome']);
        $this->assertGreaterThan($plain['xp_gained'], $boosted['xp_gained']);
        $this->assertEqualsWithDelta(1.5, $boosted['snapshot']['derived']['xp_mult'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $plain['snapshot']['derived']['xp_mult'], 1e-9);
    }

    public function test_bot_buys_the_biggest_gain_it_can_afford_and_never_a_bundle(): void
    {
        $catalog = self::catalog();
        $state = self::botState(1000);
        $bought = Premium::botShop($state, $catalog, self::$balance);

        $this->assertCount(1, $bought);
        $this->assertSame('title_b', $bought[0]['code']);
        $this->assertSame(100, $state['premium']['cash']);
        $this->assertSame(['title' => 'title_b'], $state['premium']['equipped']);

        $again = $state;
        $this->assertSame([], Premium::botShop($again, $catalog, self::$balance));

        $twin = self::botState(1000);
        $this->assertSame($bought, Premium::botShop($twin, $catalog, self::$balance));
    }

    public function test_bot_wears_its_best_owned_piece(): void
    {
        $catalog = self::catalog();
        $state = self::botState(0);
        $state['premium'] = Premium::state(0, json_encode(['owned' => ['title_a', 'title_b'], 'equipped' => ['title' => 'title_a']]), $catalog, self::$balance);
        Premium::botShop($state, $catalog, self::$balance);

        $this->assertSame(['title' => 'title_b'], $state['premium']['equipped']);
    }

    public function test_bonus_keys_cover_every_seeded_premium_key(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2).'/database/seeders/sql/050_premium.sql');
        preg_match_all('/"([a-z_]+)":/', $sql, $m);
        foreach (array_unique($m[1]) as $key) {
            $this->assertContains($key, Stats::BONUS_KEYS, $key);
        }
    }
}
