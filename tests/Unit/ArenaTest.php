<?php

namespace Tests\Unit;

use App\Game\Arena;
use App\Game\Rng;

class ArenaTest extends GameTestCase
{
    private function fighter(int $id, string $name, int $hp, int $attack, int $speed = 10, array $belt = []): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'level' => 1,
            'is_bot' => false,
            'hp' => $hp,
            'max_hp' => $hp,
            'attack' => $attack,
            'defense' => 0,
            'mres' => 0,
            'speed' => $speed,
            'crit' => 0,
            'crit_mult' => 1.5,
            'dodge' => 0,
            'damage_type' => 'physical',
            'potion_pct' => 50,
            'belt' => $belt,
            'uses' => [],
        ];
    }

    public function test_a_duel_ends_when_one_fighter_reaches_zero(): void
    {
        $a = $this->fighter(1, 'Ash', 30, 12);
        $b = $this->fighter(2, 'Briar', 80, 4);
        $fight = Arena::duel($a, $b, Rng::duel(7, 1, 1, 1, 2), self::$balance);

        $this->assertSame('knockout', $fight['reason']);
        $this->assertSame(0, min($fight['a_hp_end'], $fight['b_hp_end']));
        $this->assertGreaterThan(0, max($fight['a_hp_end'], $fight['b_hp_end']));
        $this->assertContains($fight['winner_id'], [1, 2]);
        $this->assertNotSame($fight['winner_id'], $fight['loser_id']);
    }

    public function test_the_same_seed_repeats_the_bracket(): void
    {
        $make = function () {
            $fighters = [];
            foreach ([1 => 'Ash', 2 => 'Briar', 3 => 'Cade', 4 => 'Dara', 5 => 'Elm'] as $id => $name) {
                $fighters[$id] = $this->fighter($id, $name, 40 + $id, 8 + $id);
            }

            return Arena::bracket($fighters, 42, self::$balance);
        };
        $first = $make();
        $second = $make();

        $this->assertSame($first['champion_id'], $second['champion_id']);
        $this->assertSame(
            array_map(fn ($m) => [$m['round'], $m['character_a_id'], $m['character_b_id'], $m['winner_id'], $m['reason']], $first['matches']),
            array_map(fn ($m) => [$m['round'], $m['character_a_id'], $m['character_b_id'], $m['winner_id'], $m['reason']], $second['matches']),
        );
    }

    public function test_an_odd_field_gives_one_bye_and_one_champion(): void
    {
        $bracket = Arena::bracket($this->field(5), 3, self::$balance);
        $byes = array_values(array_filter($bracket['matches'], fn ($m) => $m['reason'] === 'bye'));
        $knockouts = array_values(array_filter($bracket['matches'], fn ($m) => $m['reason'] !== 'bye'));

        $this->assertCount(1, array_filter($bracket['matches'], fn ($m) => $m['round'] === 1 && $m['reason'] === 'bye'));
        $this->assertNotEmpty($byes);
        foreach ($knockouts as $match) {
            $this->assertSame(0, min($match['a_hp_end'], $match['b_hp_end']));
        }
        $this->assertSame($bracket['champion_id'], $bracket['matches'][array_key_last($bracket['matches'])]['winner_id']);
        $this->assertGreaterThan(0, Arena::purse(4, 2, true, self::$balance)['gold']);
        $this->assertSame(0, Arena::purse(4, 0, false, self::$balance)['gold']);
    }

    public function test_a_low_fighter_drinks_before_swinging(): void
    {
        $a = $this->fighter(1, 'Ash', 80, 3, 1, [[
            'code' => 'minor_healing_potion', 'effect_type' => 'heal_pct', 'effect_value' => 50,
            'belt_qty' => 1, 'use_trigger' => 'combat', 'id' => 9,
        ]]);
        $b = $this->fighter(2, 'Briar', 80, 6, 20);
        $a['potion_pct'] = 100;
        $fight = Arena::duel($a, $b, Rng::duel(1, 1, 1, 1, 2), self::$balance);

        $this->assertContains('potion', array_column($fight['log'], 'ev'));
        $this->assertSame(9, $a['uses'][0]['id']);
        $this->assertSame(0, $a['belt'][0]['belt_qty']);
    }

    public function test_the_round_cap_still_names_a_winner(): void
    {
        $balance = self::$balance;
        $balance['combat']['max_rounds'] = 1;
        $a = $this->fighter(1, 'Ash', 5000, 1);
        $b = $this->fighter(2, 'Briar', 5000, 1);
        $fight = Arena::duel($a, $b, Rng::duel(4, 1, 1, 1, 2), $balance);

        $this->assertSame('time', $fight['reason']);
        $this->assertGreaterThan(0, $fight['a_hp_end']);
        $this->assertGreaterThan(0, $fight['b_hp_end']);
        $this->assertContains($fight['winner_id'], [1, 2]);
    }

    /** @return array<int, array> */
    private function field(int $n): array
    {
        $fighters = [];
        for ($id = 1; $id <= $n; $id++) {
            $fighters[$id] = $this->fighter($id, "F{$id}", 30 + $id * 3, 6 + $id);
        }

        return $fighters;
    }
}
