<?php

namespace App\Game;

/**
 * Mobile-style combat detail sheet (DESIGN.md §3.2.2). Every value starts at 0 and only rises from
 * bonus sources (skills, horcrux, premium, and later systems). Combat does not use these yet; the
 * character sheet shows them so grants have somewhere to land.
 */
final class Advanced
{
    public const ELEMENTS = ['fire', 'water', 'thunder', 'earth', 'wind'];

    /** Flat / percent keys that apply to every fight, not one element. */
    public const GLOBAL_KEYS = [
        'dmg_resist_pct',
        'dmg_resist',
        'ignore_defense_pct',
        'lucky_resist_pct',
        'excellency_resist_pct',
        'double_resist_pct',
        'hit_bonus_pct',
        'excellency_bonus_pct',
        'dmg_penetration_pct',
        'final_dmg_bonus_pct',
        'damage_support_pct',
        'true_damage',
    ];

    /**
     * Per-element suffixes. Full bonus keys are `{element}_{suffix}` (e.g. fire_crit_pct).
     * `atk` and `def` also produce display ranges using the combat hit variance.
     */
    public const ELEMENT_KEYS = [
        'atk',
        'def',
        'resistance',
        'reduce_res',
        'penetration',
        'crit_pct',
        'crit_res_pct',
        'crit_bonus_pct',
        'crit_decrease_pct',
        'lucky_strike_pct',
        'lucky_resist_pct',
        'dmg_addition',
        'dmg_bonus_pct',
        'def_ignoring_pct',
        'dmg_res_pct',
    ];

    public const ELEMENT_LABELS = [
        'fire' => 'Fire',
        'water' => 'Water',
        'thunder' => 'Thunder',
        'earth' => 'Earth',
        'wind' => 'Wind',
    ];

    public const ELEMENT_GLYPHS = [
        'fire' => '🔥',
        'water' => '💧',
        'thunder' => '⚡',
        'earth' => '⛰',
        'wind' => '🌬',
    ];

    /** Every bonus key this sheet accepts (for Stats::BONUS_KEYS and content checks). */
    public static function bonusKeys(): array
    {
        $keys = self::GLOBAL_KEYS;
        foreach (self::ELEMENTS as $element) {
            foreach (self::ELEMENT_KEYS as $suffix) {
                $keys[] = "{$element}_{$suffix}";
            }
        }

        return $keys;
    }

    /**
     * Build the detail sheet from a bonus map. Unknown keys are ignored; every known key is present.
     *
     * @return array{global: array<string, float|int>, elements: array<string, array<string, float|int>>}
     */
    public static function fromBonus(array $bonus, array $balance): array
    {
        $global = [];
        foreach (self::GLOBAL_KEYS as $key) {
            $global[$key] = self::number($bonus[$key] ?? 0, str_ends_with($key, '_pct'));
        }

        $vMin = (float) $balance['combat']['variance_min'];
        $vMax = (float) $balance['combat']['variance_max'];
        $elements = [];
        foreach (self::ELEMENTS as $element) {
            $row = [];
            foreach (self::ELEMENT_KEYS as $suffix) {
                $key = "{$element}_{$suffix}";
                $value = self::number($bonus[$key] ?? 0, str_ends_with($suffix, '_pct'));
                $row[$suffix] = $value;
                if ($suffix === 'atk' || $suffix === 'def') {
                    $row["{$suffix}_min"] = (int) floor($value * $vMin);
                    $row["{$suffix}_max"] = (int) ceil($value * $vMax);
                }
            }
            $elements[$element] = $row;
        }

        return ['global' => $global, 'elements' => $elements];
    }

    /** Flat sheet for JSON snapshots and simple tests: global keys + `{element}_{suffix}` (+ min/max). */
    public static function flat(array $bonus, array $balance): array
    {
        $sheet = self::fromBonus($bonus, $balance);
        $flat = $sheet['global'];
        foreach ($sheet['elements'] as $element => $row) {
            foreach ($row as $suffix => $value) {
                $flat["{$element}_{$suffix}"] = $value;
            }
        }

        return $flat;
    }

    private static function number(mixed $value, bool $percent): float|int
    {
        $n = max(0.0, (float) $value);

        return $percent ? round($n, 3) : (int) round($n);
    }
}
