<?php

namespace App\Game;

/** The one rounding rule used everywhere (.5 rounds away from zero). */
final class Numbers
{
    public static function halfUp(float|int $value): int
    {
        if ($value >= 0) {
            return (int) floor($value + 0.5);
        }

        return (int) ceil($value - 0.5);
    }

    public static function roundTo(float|int $value, int $step): int
    {
        return self::halfUp($value / $step) * $step;
    }

    public static function clamp(float|int $value, float|int $low, float|int $high): float|int
    {
        return max($low, min($high, $value));
    }

    /** Index of the largest tuple (arrays compared element by element), first one wins ties. */
    public static function argMax(array $tuples): int|string|null
    {
        $bestKey = null;
        $best = null;
        foreach ($tuples as $key => $tuple) {
            if ($best === null || ($tuple <=> $best) > 0) {
                $best = $tuple;
                $bestKey = $key;
            }
        }

        return $bestKey;
    }
}
