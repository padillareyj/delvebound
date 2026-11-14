<?php

namespace Tests\Unit;

use App\Game\Advanced;
use App\Game\Stats;

/** The mobile-style combat detail sheet (DESIGN.md §3.2.2). */
class AdvancedTest extends GameTestCase
{
    public function test_sheet_starts_at_zero_and_accepts_bonuses(): void
    {
        $empty = Advanced::fromBonus([], self::$balance);
        $this->assertSame(0.0, $empty['global']['dmg_resist_pct']);
        $this->assertSame(0, $empty['global']['true_damage']);
        $this->assertSame(0, $empty['elements']['fire']['atk']);
        $this->assertSame(0, $empty['elements']['fire']['atk_min']);
        $this->assertSame(0, $empty['elements']['fire']['atk_max']);

        $sheet = Advanced::fromBonus([
            'hit_bonus_pct' => 12.3456,
            'true_damage' => 405000,
            'fire_atk' => 1000,
            'fire_crit_pct' => 415.042,
            'water_dmg_bonus_pct' => 63.208,
            'ghost_stat' => 99,
        ], self::$balance);

        $this->assertSame(12.346, $sheet['global']['hit_bonus_pct']);
        $this->assertSame(405000, $sheet['global']['true_damage']);
        $this->assertSame(1000, $sheet['elements']['fire']['atk']);
        $this->assertSame(900, $sheet['elements']['fire']['atk_min']);
        $this->assertSame(1100, $sheet['elements']['fire']['atk_max']);
        $this->assertSame(415.042, $sheet['elements']['fire']['crit_pct']);
        $this->assertSame(63.208, $sheet['elements']['water']['dmg_bonus_pct']);
        $this->assertArrayNotHasKey('ghost_stat', $sheet['global']);
    }

    public function test_derived_embeds_the_sheet_and_bonus_keys_cover_it(): void
    {
        $derived = Stats::derived(
            ['str' => 5, 'dex' => 5, 'int' => 5, 'vit' => 5, 'luk' => 5],
            1,
            [],
            self::$balance,
            0,
            [],
            ['earth_penetration' => 101, 'final_dmg_bonus_pct' => 39],
        );
        $this->assertSame(101, $derived['advanced']['elements']['earth']['penetration']);
        $this->assertSame(39.0, $derived['advanced']['global']['final_dmg_bonus_pct']);
        $this->assertContains('fire_atk', Stats::bonusKeys());
        $this->assertContains('wind_def_ignoring_pct', Stats::bonusKeys());
        $this->assertSame(count(Advanced::GLOBAL_KEYS) + count(Advanced::ELEMENTS) * count(Advanced::ELEMENT_KEYS), count(Advanced::bonusKeys()));
    }
}
