<?php

namespace Tests\Unit;

use App\Game\World\RegionGenerator;

class RegionGeneratorTest extends GameTestCase
{
    public function test_a_frontier_opens_one_themed_region(): void
    {
        $region = $this->region();
        $again = $this->region();

        $this->assertSame($region, $again);
        $this->assertSame('The ', substr($region['name'], 0, 4));
        $this->assertCount(5, $region['places']);
        $this->assertSame('dungeon', $region['places'][4]['place_type']);
        $this->assertSame(29, $region['from']);
        $this->assertSame(51, $region['to']);
        $dungeon = $region['places'][4];
        $this->assertSame(41, $dungeon['rec_min_level']);
        $this->assertSame(51, $dungeon['rec_max_level']);
        $this->assertSame(51, $dungeon['rec_level']);
        $this->assertNotSame($region['name'], $this->region(5)['name']);

        $codes = [];
        foreach ($region['places'] as $index => $place) {
            $this->assertGreaterThanOrEqual($place['min_level'], $place['rec_min_level']);
            $this->assertGreaterThanOrEqual($place['rec_min_level'], $place['rec_level']);
            $this->assertGreaterThanOrEqual($place['rec_level'], $place['rec_max_level']);
            $this->assertLessThanOrEqual(32, strlen($place['code']));
            $this->assertLessThanOrEqual(64, strlen($place['name']));
            $codes[$place['code']] = true;
            if ($index > 0) {
                $this->assertGreaterThan($region['places'][$index - 1]['rec_min_level'], $place['rec_min_level']);
            }
        }
        foreach ($region['monsters'] as $monster) {
            $this->assertLessThanOrEqual(32, strlen($monster['code']));
            $this->assertArrayHasKey($monster['loot'], array_column($region['loot_tables'], 'code', 'code'));
            $place = null;
            foreach ($region['places'] as $candidate) {
                if ($candidate['chest'] === $monster['loot'] || $candidate['trash_loot'] === $monster['loot']) {
                    $place = $candidate;
                }
            }
            $this->assertNotNull($place);
            $this->assertGreaterThanOrEqual($place['rec_min_level'], $monster['level']);
            $this->assertLessThanOrEqual($place['rec_max_level'], $monster['level']);
            if ($monster['is_boss']) {
                $this->assertSame($place['rec_max_level'], $monster['level']);
                $this->assertSame($place['boss_loot'], $monster['loot']);
            } else {
                $this->assertLessThan($place['rec_max_level'], $monster['level']);
                $this->assertSame($place['trash_loot'], $monster['loot']);
            }
            $codes[$monster['code']] = true;
        }
        $this->assertCount(count($codes), $codes);
        $this->assertCount(5, $region['essences']);
        $bossReq = $dungeon['rec_max_level'];
        $bossItems = array_column(array_filter($region['items'], fn ($item) => (int) $item['req_level'] === $bossReq), 'code');
        $this->assertNotEmpty($bossItems);
        foreach ($region['loot_tables'] as $table) {
            $weights = array_filter($region['loot_entries'], fn ($entry) => $entry['table'] === $table['code']);
            $this->assertNotEmpty($weights);
            $this->assertGreaterThan(0, array_sum(array_column($weights, 'weight')));
        }
        $trashDrops = array_filter(
            $region['loot_entries'],
            fn ($entry) => $entry['table'] === $dungeon['trash_loot'] && $entry['item'] !== null,
        );
        foreach ($trashDrops as $entry) {
            $this->assertNotContains($entry['item'], $bossItems);
        }
        $hoard = array_column(array_filter(
            $region['loot_entries'],
            fn ($entry) => $entry['table'] === $dungeon['boss_loot'] && $entry['item'] !== null,
        ), 'item');
        foreach ($bossItems as $code) {
            $this->assertContains($code, $hoard);
        }
        $inDungeon = array_values(array_filter(
            $region['monsters'],
            fn ($monster) => $monster['loot'] === $dungeon['trash_loot'] || $monster['loot'] === $dungeon['boss_loot'],
        ));
        $bossRow = array_values(array_filter($inDungeon, fn ($monster) => $monster['is_boss']))[0];
        foreach ($inDungeon as $monster) {
            if ($monster['is_boss']) {
                continue;
            }
            $this->assertGreaterThan($monster['attack'], $bossRow['attack']);
            $this->assertGreaterThan($monster['hp'], $bossRow['hp']);
        }
    }

    private function region(int $ordinal = 4): array
    {
        return RegionGenerator::build(30, $ordinal, 'crown_of_embers', [], [], [], self::$balance);
    }
}
