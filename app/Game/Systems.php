<?php

namespace App\Game;

/**
 * Upgrade tracks fed by daily materials (DESIGN.md §17): elemental Jewels, Excellency, Wing Stars,
 * and the Sigil Circle (five elemental sigils + a Nexus). Levels live on characters.systems.
 *
 * State: jewels, excellency, wing_stars, sigils (per element), sigil_nexus
 */
final class Systems
{
    public static function state(mixed $json, array $balance): array
    {
        $raw = is_string($json) ? (json_decode($json, true) ?: []) : (array) ($json ?? []);
        $jewels = [];
        $sigils = [];
        foreach (Advanced::ELEMENTS as $element) {
            $jewels[$element] = max(0, min((int) $balance['systems']['jewels']['max_level'], (int) (($raw['jewels'][$element] ?? 0))));
            $sigils[$element] = max(0, min((int) $balance['systems']['sigils']['max_level'], (int) (($raw['sigils'][$element] ?? 0))));
        }
        ksort($jewels);
        ksort($sigils);

        return [
            'jewels' => $jewels,
            'excellency' => max(0, min((int) $balance['systems']['excellency']['max_level'], (int) ($raw['excellency'] ?? 0))),
            'wing_stars' => max(0, min((int) $balance['systems']['wing_stars']['max_level'], (int) ($raw['wing_stars'] ?? 0))),
            'sigils' => $sigils,
            'sigil_nexus' => max(0, min((int) $balance['systems']['sigils']['nexus_max_level'], (int) ($raw['sigil_nexus'] ?? 0))),
        ];
    }

    public static function encode(array $state): string
    {
        return json_encode([
            'jewels' => (object) $state['jewels'],
            'excellency' => (int) $state['excellency'],
            'wing_stars' => (int) $state['wing_stars'],
            'sigils' => (object) $state['sigils'],
            'sigil_nexus' => (int) $state['sigil_nexus'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function bonuses(array $state, array $balance): array
    {
        $s = $balance['systems'];
        $total = [];
        foreach ($state['jewels'] as $element => $level) {
            if ($level <= 0) {
                continue;
            }
            foreach ($s['jewels']['per_level'][$element] ?? [] as $key => $per) {
                $total[$key] = ($total[$key] ?? 0.0) + (float) $per * $level;
            }
        }
        if ($state['excellency'] > 0) {
            foreach ($s['excellency']['per_level'] as $key => $per) {
                $total[$key] = ($total[$key] ?? 0.0) + (float) $per * $state['excellency'];
            }
        }
        if ($state['wing_stars'] > 0) {
            foreach ($s['wing_stars']['per_level'] as $key => $per) {
                $total[$key] = ($total[$key] ?? 0.0) + (float) $per * $state['wing_stars'];
            }
        }
        foreach ($state['sigils'] as $element => $level) {
            if ($level <= 0) {
                continue;
            }
            foreach ($s['sigils']['per_level'][$element] ?? [] as $key => $per) {
                $total[$key] = ($total[$key] ?? 0.0) + (float) $per * $level;
            }
        }
        if ($state['sigil_nexus'] > 0) {
            foreach ($s['sigils']['nexus_per_level'] as $key => $per) {
                $total[$key] = ($total[$key] ?? 0.0) + (float) $per * $state['sigil_nexus'];
            }
        }

        return $total;
    }

    public static function power(array $state, array $balance): int
    {
        $s = $balance['systems'];

        return (int) $s['power_per_jewel_level'] * array_sum($state['jewels'])
            + (int) $s['power_per_excellency'] * $state['excellency']
            + (int) $s['power_per_wing_star'] * $state['wing_stars']
            + (int) $s['power_per_sigil_level'] * array_sum($state['sigils'])
            + (int) $s['power_per_sigil_nexus'] * $state['sigil_nexus'];
    }

    public static function jewelCost(int $nextLevel, array $balance): int
    {
        $j = $balance['systems']['jewels'];

        return (int) $j['cost_base'] + (int) $j['cost_per_level'] * max(0, $nextLevel - 1);
    }

    public static function excellencyCost(int $nextLevel, array $balance): int
    {
        $e = $balance['systems']['excellency'];

        return (int) $e['cost_base'] + (int) $e['cost_per_level'] * max(0, $nextLevel - 1);
    }

    public static function wingCost(int $nextLevel, array $balance): int
    {
        $w = $balance['systems']['wing_stars'];

        return (int) $w['cost_base'] + (int) $w['cost_per_level'] * max(0, $nextLevel - 1);
    }

    public static function sigilCost(int $nextLevel, array $balance): int
    {
        $s = $balance['systems']['sigils'];

        return (int) $s['cost_base'] + (int) $s['cost_per_level'] * max(0, $nextLevel - 1);
    }

    public static function nexusCost(int $nextLevel, array $balance): int
    {
        $s = $balance['systems']['sigils'];

        return (int) $s['nexus_cost_base'] + (int) $s['nexus_cost_per_level'] * max(0, $nextLevel - 1);
    }

    public static function sigilSum(array $state): int
    {
        return (int) array_sum($state['sigils']);
    }

    /** @param  array<string, int>  $bag */
    private static function pay(array &$bag, string $mat, int $need): void
    {
        $bag[$mat] = (int) $bag[$mat] - $need;
        if ($bag[$mat] <= 0) {
            unset($bag[$mat]);
        }
    }

    /** @param  array<string, int>  $bag @return array{0: bool, 1: string|null} */
    public static function canRaiseJewel(array $state, array $bag, string $element, array $balance): array
    {
        if (! in_array($element, Advanced::ELEMENTS, true)) {
            return [false, 'Unknown jewel.'];
        }
        $level = (int) $state['jewels'][$element];
        if ($level >= (int) $balance['systems']['jewels']['max_level']) {
            return [false, 'That jewel is maxed.'];
        }
        $need = self::jewelCost($level + 1, $balance);
        $have = (int) ($bag[$balance['systems']['jewels']['material']] ?? 0);

        return $have < $need ? [false, "Need {$need} Jewel Dust (have {$have})."] : [true, null];
    }

    /** @param  array<string, int>  $bag */
    public static function raiseJewel(array &$state, array &$bag, string $element, array $balance): void
    {
        self::pay($bag, $balance['systems']['jewels']['material'], self::jewelCost((int) $state['jewels'][$element] + 1, $balance));
        $state['jewels'][$element]++;
    }

    /** @param  array<string, int>  $bag @return array{0: bool, 1: string|null} */
    public static function canRaiseExcellency(array $state, array $bag, array $balance): array
    {
        $level = (int) $state['excellency'];
        if ($level >= (int) $balance['systems']['excellency']['max_level']) {
            return [false, 'Excellency is maxed.'];
        }
        $need = self::excellencyCost($level + 1, $balance);
        $have = (int) ($bag[$balance['systems']['excellency']['material']] ?? 0);

        return $have < $need ? [false, "Need {$need} Excellent Stone (have {$have})."] : [true, null];
    }

    /** @param  array<string, int>  $bag */
    public static function raiseExcellency(array &$state, array &$bag, array $balance): void
    {
        self::pay($bag, $balance['systems']['excellency']['material'], self::excellencyCost((int) $state['excellency'] + 1, $balance));
        $state['excellency']++;
    }

    /** @param  array<string, int>  $bag @return array{0: bool, 1: string|null} */
    public static function canRaiseWing(array $state, array $bag, array $balance): array
    {
        $level = (int) $state['wing_stars'];
        if ($level >= (int) $balance['systems']['wing_stars']['max_level']) {
            return [false, 'Wing Stars are maxed.'];
        }
        $need = self::wingCost($level + 1, $balance);
        $have = (int) ($bag[$balance['systems']['wing_stars']['material']] ?? 0);

        return $have < $need ? [false, "Need {$need} Wing Feather (have {$have})."] : [true, null];
    }

    /** @param  array<string, int>  $bag */
    public static function raiseWing(array &$state, array &$bag, array $balance): void
    {
        self::pay($bag, $balance['systems']['wing_stars']['material'], self::wingCost((int) $state['wing_stars'] + 1, $balance));
        $state['wing_stars']++;
    }

    /** @param  array<string, int>  $bag @return array{0: bool, 1: string|null} */
    public static function canRaiseSigil(array $state, array $bag, string $element, array $balance): array
    {
        if (! in_array($element, Advanced::ELEMENTS, true)) {
            return [false, 'Unknown sigil.'];
        }
        $level = (int) $state['sigils'][$element];
        if ($level >= (int) $balance['systems']['sigils']['max_level']) {
            return [false, 'That sigil is maxed.'];
        }
        $need = self::sigilCost($level + 1, $balance);
        $have = (int) ($bag[$balance['systems']['sigils']['material']] ?? 0);

        return $have < $need ? [false, "Need {$need} Sigil Ink (have {$have})."] : [true, null];
    }

    /** @param  array<string, int>  $bag */
    public static function raiseSigil(array &$state, array &$bag, string $element, array $balance): void
    {
        self::pay($bag, $balance['systems']['sigils']['material'], self::sigilCost((int) $state['sigils'][$element] + 1, $balance));
        $state['sigils'][$element]++;
    }

    /** @param  array<string, int>  $bag @return array{0: bool, 1: string|null} */
    public static function canRaiseNexus(array $state, array $bag, array $balance): array
    {
        $s = $balance['systems']['sigils'];
        $level = (int) $state['sigil_nexus'];
        if ($level >= (int) $s['nexus_max_level']) {
            return [false, 'The Nexus is maxed.'];
        }
        $sum = self::sigilSum($state);
        $needSum = (int) $s['nexus_min_sigil_sum'] * ($level + 1);
        if ($sum < $needSum) {
            return [false, "Need {$needSum} total sigil levels (have {$sum})."];
        }
        $need = self::nexusCost($level + 1, $balance);
        $have = (int) ($bag[$s['material']] ?? 0);

        return $have < $need ? [false, "Need {$need} Sigil Ink (have {$have})."] : [true, null];
    }

    /** @param  array<string, int>  $bag */
    public static function raiseNexus(array &$state, array &$bag, array $balance): void
    {
        self::pay($bag, $balance['systems']['sigils']['material'], self::nexusCost((int) $state['sigil_nexus'] + 1, $balance));
        $state['sigil_nexus']++;
    }

    /**
     * Bot step: cheapest next upgrade among jewels, excellency, wings, sigils, nexus.
     *
     * @param  array<string, int>  $bag
     * @return list<array{track: string, element?: string, level: int}>
     */
    public static function botUpgrade(array &$state, array &$bag, array $balance): array
    {
        $log = [];
        $limit = (int) $balance['systems']['bot_upgrades_per_run'];
        for ($n = 0; $n < $limit; $n++) {
            $pick = null;
            $bestCost = null;
            foreach (Advanced::ELEMENTS as $element) {
                if (self::canRaiseJewel($state, $bag, $element, $balance)[0]) {
                    $cost = self::jewelCost((int) $state['jewels'][$element] + 1, $balance);
                    if ($bestCost === null || $cost < $bestCost || ($cost === $bestCost && ($pick['track'] ?? '') === 'jewel' && $element < ($pick['element'] ?? 'zzz'))) {
                        $bestCost = $cost;
                        $pick = ['track' => 'jewel', 'element' => $element];
                    }
                }
                if (self::canRaiseSigil($state, $bag, $element, $balance)[0]) {
                    $cost = self::sigilCost((int) $state['sigils'][$element] + 1, $balance);
                    if ($bestCost === null || $cost < $bestCost || ($cost === $bestCost && ($pick['track'] ?? '') === 'sigil' && $element < ($pick['element'] ?? 'zzz'))) {
                        $bestCost = $cost;
                        $pick = ['track' => 'sigil', 'element' => $element];
                    }
                }
            }
            if (self::canRaiseExcellency($state, $bag, $balance)[0]) {
                $cost = self::excellencyCost((int) $state['excellency'] + 1, $balance);
                if ($bestCost === null || $cost < $bestCost) {
                    $bestCost = $cost;
                    $pick = ['track' => 'excellency'];
                }
            }
            if (self::canRaiseWing($state, $bag, $balance)[0]) {
                $cost = self::wingCost((int) $state['wing_stars'] + 1, $balance);
                if ($bestCost === null || $cost < $bestCost) {
                    $bestCost = $cost;
                    $pick = ['track' => 'wing'];
                }
            }
            if (self::canRaiseNexus($state, $bag, $balance)[0]) {
                $cost = self::nexusCost((int) $state['sigil_nexus'] + 1, $balance);
                if ($bestCost === null || $cost < $bestCost) {
                    $pick = ['track' => 'nexus'];
                }
            }
            if ($pick === null) {
                break;
            }
            match ($pick['track']) {
                'jewel' => self::raiseJewel($state, $bag, $pick['element'], $balance),
                'sigil' => self::raiseSigil($state, $bag, $pick['element'], $balance),
                'excellency' => self::raiseExcellency($state, $bag, $balance),
                'wing' => self::raiseWing($state, $bag, $balance),
                default => self::raiseNexus($state, $bag, $balance),
            };
            $log[] = match ($pick['track']) {
                'jewel' => ['track' => 'jewel', 'element' => $pick['element'], 'level' => $state['jewels'][$pick['element']]],
                'sigil' => ['track' => 'sigil', 'element' => $pick['element'], 'level' => $state['sigils'][$pick['element']]],
                'excellency' => ['track' => 'excellency', 'level' => $state['excellency']],
                'wing' => ['track' => 'wing', 'level' => $state['wing_stars']],
                default => ['track' => 'nexus', 'level' => $state['sigil_nexus']],
            };
        }

        return $log;
    }
}
