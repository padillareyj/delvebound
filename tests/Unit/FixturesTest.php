<?php

namespace Tests\Unit;

use App\Game\Progression;
use App\Game\Rules;
use App\Game\ShopMath;
use App\Game\Stats;

/** The shared JSON fixtures in tests/fixtures (AGENTS.md §4.2). */
class FixturesTest extends GameTestCase
{
    public function test_derived_stats_fixture(): void
    {
        foreach (self::fixture('derived_stats_cases.json') as $case) {
            $got = Stats::derived($case['stats'], $case['level'], $case['equipment'], self::$balance, $case['injury'], $case['buffs'], $case['bonus'] ?? []);
            foreach ($case['expected'] as $key => $expected) {
                if (is_float($expected)) {
                    $this->assertEqualsWithDelta($expected, $got[$key], 1e-6, "{$case['name']}: {$key}");
                } else {
                    $this->assertEquals($expected, $got[$key], "{$case['name']}: {$key}");
                }
            }
        }
    }

    public function test_place_access_fixture(): void
    {
        foreach (self::fixture('place_access_cases.json') as $case) {
            [$ok, $reason] = Rules::canEnter($case['level'], $case['place']);
            $this->assertSame($case['ok'], $ok, $case['name']);
            $this->assertSame($case['reason'], $reason, $case['name']);
        }
    }

    public function test_shop_price_fixture(): void
    {
        foreach (self::fixture('shop_price_cases.json') as $case) {
            $this->assertSame($case['price'], ShopMath::gearPrice($case['base_value'], $case['req_level'], self::$balance), $case['name']);
        }
    }

    public function test_xp_table_and_point_pool(): void
    {
        foreach (self::fixture('xp_cases.json') as $case) {
            $this->assertSame($case['xp_to_next'], Progression::xpToNext($case['level'], self::$balance));
        }
        $this->assertSame(54, Progression::pointPool(10, self::$balance));
        $this->assertSame(5, Progression::pointPool(1, self::$balance));
    }
}
