<?php

namespace App\Game\Bots;

use App\Game\Numbers;
use App\Game\Rng;
use App\Game\Rules;

/** Choose a destination that respects level gates (DESIGN.md §8.7). */
final class Destination
{
    public static function choose(array $state, array $places, array $memory, Rng $rng, array $balance): array
    {
        $town = null;
        foreach ($places as $place) {
            if (($place['place_type'] ?? null) === 'rest') {
                $town = $place;
                break;
            }
        }
        $profile = $state['profile'];
        $injury = (int) $state['injury_severity'];
        if ($injury >= 2 || ($injury === 1 && ($profile['rest_when_injured'] ?? true))) {
            return $town;
        }
        $bots = $balance['bots'];
        $level = (int) $state['level'];
        $target = $level + (int) $profile['risk_offset'] + (int) $memory['step_up'];
        if (in_array($memory['last_outcome'] ?? null, ['defeated', 'order_rejected'], true)) {
            $target--;
        }
        $candidates = [];
        foreach ($places as $place) {
            if (($place['place_type'] ?? null) === 'rest') {
                continue;
            }
            if (! Rules::canEnter($level, $place)[0] || in_array($place['id'], $memory['blocked'], true)) {
                continue;
            }
            $candidates[] = $place;
        }
        if ($candidates === []) {
            return $town;
        }
        usort($candidates, fn ($a, $b) => $a['id'] <=> $b['id']);
        $randomness = (float) $profile['randomness'];
        if ($rng->random() < $randomness * $bots['explore_factor']) {
            $near = array_values(array_filter($candidates, fn ($p) => abs((int) $p['rec_level'] - $target) <= $bots['explore_window']));
            if ($near !== []) {
                return $rng->choice($near);
            }
        }
        $ranked = [];
        foreach ($candidates as $place) {
            $inRange = (int) $place['rec_min_level'] <= $level && $level <= (int) $place['rec_max_level'];
            $score = -abs((int) $place['rec_level'] - $target)
                + ($inRange ? $bots['in_range_bonus'] : 0)
                - $bots['retreat_penalty'] * ($memory['retreats'][$place['id']] ?? 0)
                + $bots['rarity_weight'] * (float) $profile['rarity_preference'] * (float) ($place['rarity_bonus'] ?? 0)
                + $rng->gauss(0, max((float) $bots['score_noise_floor'], $randomness));
            $ranked[] = [(float) $score, $place['id']];
        }

        return $candidates[Numbers::argMax($ranked)];
    }
}
