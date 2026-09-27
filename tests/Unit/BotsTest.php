<?php

namespace Tests\Unit;

use App\Game\Bots\Allocation;
use App\Game\Bots\Destination;
use App\Game\Bots\Memory;
use App\Game\Bots\Shopping;
use App\Game\Rng;

class BotsTest extends GameTestCase
{
    private function profile(array $overrides = []): array
    {
        return $overrides + [
            'weights' => ['str' => 0.3, 'dex' => 0.1, 'int' => 0.0, 'vit' => 0.5, 'luk' => 0.1],
            'gear_weights' => ['hp' => 1, 'attack' => 1, 'defense' => 1, 'mres' => 1, 'crit' => 0.2, 'dodge' => 0.2, 'speed' => 0.2, 'mf' => 0.1],
            'risk_offset' => 0, 'retreat_threshold_pct' => 20, 'randomness' => 0, 'rarity_preference' => 0,
            'fail_threshold' => 2, 'fail_avoid_runs' => 3, 'stepup_after_wins' => 2, 'stepup_hp_pct' => 60,
            'rest_when_injured' => true, 'sell_junk' => true, 'gold_reserve_pct' => 40, 'potion_target' => 2,
            'buff_preference' => 'none', 'shop_min_gain_pct' => 5,
        ];
    }

    private function state(?array $profile = null): array
    {
        return [
            'level' => 1, 'unspent' => 5, 'gold' => 100, 'injury_severity' => 0, 'injury_runs_left' => 0,
            'stats' => ['str' => 5, 'dex' => 5, 'int' => 5, 'vit' => 5, 'luk' => 5],
            'equipment' => [], 'items' => [], 'consumables' => [], 'profile' => $profile ?? $this->profile(),
            'ledger' => [], 'new_items' => [],
        ];
    }

    public function test_memory_blocks_after_failures_then_releases(): void
    {
        $reports = [
            ['place_id' => 2, 'outcome' => 'cleared', 'hp_end_pct' => 80, 'place_rec_level' => 2],
            ['place_id' => 2, 'outcome' => 'defeated', 'hp_end_pct' => 0, 'place_rec_level' => 2],
            ['place_id' => 2, 'outcome' => 'defeated', 'hp_end_pct' => 0, 'place_rec_level' => 2],
        ];
        $this->assertContains(2, Memory::read($reports, $this->profile(), 1, self::$balance)['blocked']);
        $later = array_merge(array_fill(0, 3, ['place_id' => 3, 'outcome' => 'cleared', 'hp_end_pct' => 90, 'place_rec_level' => 4]), $reports);
        $this->assertNotContains(2, Memory::read($later, $this->profile(), 1, self::$balance)['blocked']);
    }

    public function test_step_up_after_easy_clears(): void
    {
        $reports = [
            ['place_id' => 2, 'outcome' => 'cleared', 'hp_end_pct' => 80, 'place_rec_level' => 1],
            ['place_id' => 2, 'outcome' => 'cleared', 'hp_end_pct' => 70, 'place_rec_level' => 1],
        ];
        $this->assertSame(1, Memory::read($reports, $this->profile(), 1, self::$balance)['step_up']);
    }

    public function test_destination_respects_gates_and_is_deterministic(): void
    {
        $place = fn (int $id, string $code, string $type, int $min, ?int $max, int $recMin, int $recMax, int $rec) => [
            'id' => $id, 'code' => $code, 'name' => ucfirst($code), 'place_type' => $type, 'is_active' => true,
            'min_level' => $min, 'max_level' => $max, 'rec_min_level' => $recMin, 'rec_max_level' => $recMax,
            'rec_level' => $rec, 'rarity_bonus' => 0, 'boss_monster_id' => null,
        ];
        $places = [
            $place(1, 'town', 'rest', 1, null, 1, 1, 1),
            $place(2, 'greenwood', 'forest', 1, 6, 1, 3, 2),
            $place(3, 'crypt', 'dungeon', 5, null, 6, 9, 8),
        ];
        $state = $this->state($this->profile(['randomness' => 0, 'risk_offset' => 2]));
        $memory = ['blocked' => [], 'step_up' => 0, 'retreats' => [], 'last_outcome' => null];
        $first = Destination::choose($state, $places, $memory, Rng::bot(4, 8), self::$balance);
        $second = Destination::choose($state, $places, $memory, Rng::bot(4, 8), self::$balance);
        $this->assertSame('greenwood', $first['code']);
        $this->assertSame('greenwood', $second['code']);
        $state['level'] = 7;
        $this->assertNotSame('greenwood', Destination::choose($state, $places, $memory, Rng::bot(4, 8), self::$balance)['code']);
    }

    public function test_allocation_spends_every_point_and_matches_seed(): void
    {
        $state = $this->state();
        $first = Allocation::allocate($state, Rng::bot(9, 2), self::$balance);
        $this->assertSame(0, $state['unspent']);
        $this->assertSame(30, array_sum($state['stats']));
        $again = $this->state();
        $this->assertSame($first, Allocation::allocate($again, Rng::bot(9, 2), self::$balance));
        $this->assertSame($state['stats'], $again['stats']);
    }

    public function test_shop_never_spends_the_reserve(): void
    {
        $state = $this->state($this->profile(['gold_reserve_pct' => 40, 'potion_target' => 2, 'buff_preference' => 'none']));
        $state['gold'] = 80;
        $shop = [[
            'id' => 1, 'kind' => 'consumable', 'code' => 'minor_healing_potion', 'price' => 25, 'min_level' => 1, 'is_active' => true,
            'consumable' => ['id' => 1, 'code' => 'minor_healing_potion', 'name' => 'Minor', 'effect_type' => 'heal_pct',
                'effect_value' => 30, 'use_trigger' => 'auto_low_hp', 'req_level' => 1, 'sell_price' => 6],
        ]];
        Shopping::shopStep($state, ['place_type' => 'forest', 'rec_level' => 1, 'boss_monster_id' => null], $shop, self::$balance);
        $this->assertLessThanOrEqual(80, $state['gold']);
        $bought = 0;
        foreach ($state['ledger'] as $entry) {
            if ($entry['action'] !== 'buy') {
                continue;
            }
            $bought++;
            $before = $entry['gold_after'] - $entry['gold_delta'];
            $this->assertGreaterThanOrEqual((int) ceil($before * 0.40), $entry['gold_after']);
        }
        $this->assertGreaterThan(0, $bought);
    }
}
