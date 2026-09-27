<?php

namespace App\Game;

/** XP curve, level-up rewards, and injury transitions (DESIGN.md §4.5, §5). */
final class Progression
{
    public static function xpToNext(int $level, array $balance): int
    {
        $raw = $balance['xp']['base'] * ($level ** $balance['xp']['exponent']);

        return Numbers::roundTo($raw, (int) $balance['xp']['round_to']);
    }

    public static function pointsForReaching(int $level, array $balance): int
    {
        $points = (int) $balance['xp']['points_per_level'];
        if ($level % (int) $balance['xp']['bonus_every'] === 0) {
            $points += (int) $balance['xp']['bonus_points'];
        }

        return $points;
    }

    public static function pointPool(int $level, array $balance): int
    {
        $total = (int) $balance['character']['starting_unspent'];
        for ($reached = 2; $reached <= $level; $reached++) {
            $total += self::pointsForReaching($reached, $balance);
        }

        return $total;
    }

    public static function monsterXp(int $baseXp, int $monsterLevel, int $charLevel, array $balance): int
    {
        $factor = 1 + $balance['xp']['diff_per_level'] * ($monsterLevel - $charLevel);
        $factor = Numbers::clamp($factor, $balance['xp']['diff_min'], $balance['xp']['diff_max']);

        return Numbers::halfUp($baseXp * $factor);
    }

    /** @return array{level: int, xp: int, points: int, gold: int, levels: list<array{level: int, points: int, gold: int}>} */
    public static function applyXp(int $level, int $xp, int $gained, array $balance): array
    {
        $cap = (int) $balance['character']['level_cap'];
        $xp += $gained;
        $points = 0;
        $gold = 0;
        $levels = [];
        while ($level < $cap && $xp >= self::xpToNext($level, $balance)) {
            $xp -= self::xpToNext($level, $balance);
            $level++;
            $gainedPoints = self::pointsForReaching($level, $balance);
            $gainedGold = (int) $balance['xp']['gold_per_new_level'] * $level;
            $points += $gainedPoints;
            $gold += $gainedGold;
            $levels[] = ['level' => $level, 'points' => $gainedPoints, 'gold' => $gainedGold];
        }

        return ['level' => $level, 'xp' => $xp, 'points' => $points, 'gold' => $gold, 'levels' => $levels];
    }

    /** @return array{0: int, 1: int} [severity, runs_left] after a trip with this outcome */
    public static function nextInjury(string $outcome, int $severity, int $runsLeft): array
    {
        if (in_array($outcome, ['rested', 'blocked_injured', 'order_rejected'], true)) {
            return [0, 0];
        }
        if ($outcome === 'defeated') {
            return $severity >= 1 ? [2, 1] : [1, 1];
        }
        if ($severity >= 1) {
            $runsLeft--;

            return $runsLeft <= 0 ? [0, 0] : [$severity, $runsLeft];
        }

        return [0, 0];
    }
}
