<?php

namespace App\Game\Bots;

/** Mail-based bot memory (DESIGN.md §8.8). Reports are payloads, newest first. */
final class Memory
{
    /** @return array{blocked: list<int>, step_up: int, retreats: array<int, int>, last_outcome: ?string} */
    public static function read(array $reports, array $profile, int $level, array $balance): array
    {
        $defeats = [];
        $retreats = [];
        foreach ($reports as $index => $report) {
            $placeId = $report['place_id'] ?? null;
            if ($placeId === null) {
                continue;
            }
            if (($report['outcome'] ?? null) === 'defeated') {
                $defeats[$placeId][] = $index;
            }
            if (($report['outcome'] ?? null) === 'retreated') {
                $retreats[$placeId] = ($retreats[$placeId] ?? 0) + 1;
            }
        }
        $blocked = [];
        foreach ($defeats as $placeId => $indexes) {
            if (count($indexes) >= (int) $profile['fail_threshold'] && $indexes[0] < (int) $profile['fail_avoid_runs']) {
                $blocked[] = (int) $placeId;
            }
        }
        sort($blocked);

        $streak = 0;
        $need = $level + (int) $profile['risk_offset'] - 1;
        foreach ($reports as $report) {
            $easy = ($report['outcome'] ?? null) === 'cleared'
                && (int) ($report['hp_end_pct'] ?? 0) >= (int) $profile['stepup_hp_pct']
                && (int) ($report['place_rec_level'] ?? 0) >= $need;
            if (! $easy) {
                break;
            }
            $streak++;
        }
        $step = (int) $profile['stepup_after_wins'] ?: 1;

        return [
            'blocked' => $blocked,
            'step_up' => min((int) $balance['bots']['step_up_cap'], intdiv($streak, $step)),
            'retreats' => $retreats,
            'last_outcome' => $reports[0]['outcome'] ?? null,
        ];
    }
}
