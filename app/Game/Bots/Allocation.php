<?php

namespace App\Game\Bots;

use App\Game\Loot;
use App\Game\Numbers;
use App\Game\Rng;
use App\Game\Stats;

/** Spend unspent stat points one at a time (DESIGN.md §8.3). */
final class Allocation
{
    /** @return array<string, int> points gained per stat */
    public static function allocate(array &$state, Rng $rng, array $balance): array
    {
        $bots = $balance['bots'];
        $order = $bots['stats'];
        $base = (int) $balance['character']['base_stat'];
        $profile = $state['profile'];
        $weights = self::normalise(array_combine($order, array_map(fn ($s) => $profile['weights'][$s], $order)));
        $spent = [];
        $gained = [];
        foreach ($order as $stat) {
            $spent[$stat] = (int) $state['stats'][$stat] - $base;
            $gained[$stat] = 0;
        }
        while ((int) $state['unspent'] > 0) {
            $preview = Stats::derived($state['stats'], (int) $state['level'], array_values(array_filter($state['equipment'])), $balance);
            $safety = $bots['hp_safety_base'] + $bots['hp_safety_per_level'] * (int) $state['level'];
            if ($preview['hp'] < $safety) {
                $pick = 'vit';
            } elseif ($rng->random() < (float) $profile['randomness'] * $bots['quirk_factor']) {
                $pick = Loot::weightedPick($order, $rng, fn ($stat) => $weights[$stat]);
            } else {
                $total = array_sum($spent) + 1;
                $ranked = [];
                foreach ($order as $stat) {
                    $ranked[] = [$weights[$stat] * $total - $spent[$stat], $weights[$stat], $rng->random(), $stat];
                }
                $pick = $ranked[Numbers::argMax($ranked)][3];
            }
            $state['stats'][$pick] = (int) $state['stats'][$pick] + 1;
            $spent[$pick]++;
            $gained[$pick]++;
            $state['unspent'] = (int) $state['unspent'] - 1;
        }

        return array_filter($gained);
    }

    private static function normalise(array $weights): array
    {
        $total = 0.0;
        foreach ($weights as $value) {
            $total += max(0.0, (float) $value);
        }
        if ($total <= 0) {
            return array_map(fn () => 1 / count($weights), $weights);
        }

        return array_map(fn ($value) => max(0.0, (float) $value) / $total, $weights);
    }
}
