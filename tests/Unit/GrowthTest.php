<?php

namespace Tests\Unit;

use App\Game\Growth;
use App\Game\Rng;

class GrowthTest extends GameTestCase
{
    public function test_power_is_level_plus_ranks_plus_horcrux(): void
    {
        $power = Growth::powerLevel(4, ['keen_edge' => 2, 'iron_hide' => 1], 1, self::$balance);
        $g = self::$balance['growth'];
        $this->assertSame(
            4 * $g['power_per_level'] + 3 * $g['power_per_skill_rank'] + $g['power_per_horcrux_tier'],
            $power,
        );
    }

    public function test_rank_one_spends_spark_dust(): void
    {
        $state = [
            'gold' => 100,
            'skills' => [],
            'materials' => [
                ['code' => 'spark_dust', 'kind' => 'dust', 'qty' => 2],
                ['code' => 'verdant_sap', 'kind' => 'essence', 'qty' => 0],
            ],
        ];
        $trained = Growth::botTrain($state, self::$balance);
        $this->assertSame(['keen_edge'], $trained);
        $this->assertSame(1, $state['skills']['keen_edge']);
        $this->assertSame(60, $state['gold']);
        $this->assertSame(0, $state['materials'][0]['qty']);
    }

    public function test_material_rolls_are_deterministic(): void
    {
        $catalog = [
            ['code' => 'verdant_sap', 'name' => 'Verdant Sap', 'kind' => 'essence'],
            ['code' => 'spark_dust', 'name' => 'Spark Dust', 'kind' => 'dust'],
        ];
        $once = Growth::rollKill($catalog, false, Rng::trip(42, 7), self::$balance);
        $twice = Growth::rollKill($catalog, false, Rng::trip(42, 7), self::$balance);
        $this->assertSame($once, $twice);
    }

    public function test_horcrux_tier_follows_thresholds(): void
    {
        $this->assertSame(0, Growth::tierFor(0, self::$balance));
        $this->assertSame(1, Growth::tierFor(24, self::$balance));
        $this->assertSame(5, Growth::tierFor(700, self::$balance));
        $this->assertGreaterThan(0, Growth::feedValue(['rarity' => 'rare', 'item_level' => 4], self::$balance));
    }

    public function test_essence_is_missing_only_at_the_last_rank(): void
    {
        $state = ['skills' => [], 'materials' => []];
        $this->assertFalse(Growth::missingEssence($state, self::$balance));
        $state['skills'] = ['keen_edge' => 4, 'iron_hide' => 4, 'fortune' => 4];
        $this->assertTrue(Growth::missingEssence($state, self::$balance));
    }
}
