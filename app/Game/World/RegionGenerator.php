<?php

namespace App\Game\World;

use App\Game\Numbers;
use App\Game\Rng;
use InvalidArgumentException;

/**
 * One new region: a theme, five places, their monsters, loot, and a place essence.
 * Pure. The simulation is what writes the rows (DESIGN.md §7.1).
 */
final class RegionGenerator
{
    private const CODE_REGION = 32;

    private const CODE_PLACE = 32;

    private const CODE_MONSTER = 32;

    private const CODE_LOOT = 32;

    private const CODE_ITEM = 40;

    private const CODE_MATERIAL = 40;

    private const NAME = 64;

    /**
     * @param  list<string>  $usedThemeCodes
     * @param  array<string, true>  $takenCodes
     * @param  array<string, true>  $takenNames
     * @return array{
     *     code: string, name: string, blurb: string, sort_order: int, theme: string, from: int, to: int,
     *     loot_tables: list<array{code: string, name: string, description: string}>,
     *     items: list<array<string, mixed>>,
     *     loot_entries: list<array{table: string, type: string, item: ?string, weight: int}>,
     *     monsters: list<array<string, mixed>>,
     *     places: list<array<string, mixed>>,
     *     spawns: list<array{place: string, monster: string, weight: int, min_group: int, max_group: int}>,
     *     essences: list<array{code: string, name: string, glyph: string, description: string, place: string, sort_order: int}>
     * }
     */
    public static function build(
        int $frontierRecMax,
        int $ordinal,
        string $frontierCode,
        array $usedThemeCodes,
        array $takenCodes,
        array $takenNames,
        array $balance,
    ): array {
        $settings = $balance['world'];
        $count = (int) $settings['places_per_region'];
        $pins = $settings['pins'];
        if (count($pins) !== $count) {
            throw new InvalidArgumentException('world.pins must have one point per place.');
        }
        $rng = Rng::world("{$frontierCode}:{$ordinal}");
        $theme = self::theme($rng, $usedThemeCodes);
        foreach (['places', 'skirmishers', 'brutes', 'casters', 'bosses'] as $list) {
            if (count($theme[$list]) !== $count) {
                throw new InvalidArgumentException("Theme {$theme['code']} must list {$count} {$list}.");
            }
        }
        $bag = [
            'adj' => $rng->choice($theme['adjectives']),
            'noun' => $rng->choice($theme['nouns']),
        ];
        $regionCode = self::uniqueCode("gen{$ordinal}_{$theme['code']}", $takenCodes, self::CODE_REGION);
        $regionName = self::uniqueName("The {$bag['adj']} {$bag['noun']}", $takenNames);

        $bands = self::bands($frontierRecMax, $count, $settings);
        $places = [];
        $lootTables = [];
        $monsters = [];
        $spawns = [];
        $essences = [];
        $ceilings = [];
        $lowLevel = $bands[0]['rec_level'];
        $dungeon = $bands[$count - 1];
        $dungeonSpan = $dungeon['rec_max'] - $dungeon['rec_min'];
        $midLevel = $dungeon['rec_min'] + intdiv($dungeonSpan, 2);
        $highLevel = $dungeon['rec_max'];

        foreach ($bands as $index => $band) {
            $type = $theme['places'][$index];
            $placeName = self::uniqueName(self::fill($rng->choice(ThemeCatalog::PLACE_NAMES[$type]), $bag), $takenNames);
            $placeCode = self::uniqueCode("{$regionCode}_".($index + 1), $takenCodes, self::CODE_PLACE);
            $lootCode = self::uniqueCode("lt_{$placeCode}", $takenCodes, self::CODE_LOOT);
            $bossLootCode = self::uniqueCode("lb_{$placeCode}", $takenCodes, self::CODE_LOOT);
            $lootTables[] = [
                'code' => $lootCode,
                'name' => self::clip("{$placeName} Spoils", self::NAME),
                'description' => self::clip("Gear the lesser denizens of {$placeName} carry.", 255),
            ];
            $lootTables[] = [
                'code' => $bossLootCode,
                'name' => self::clip("{$placeName} Hoard", self::NAME),
                'description' => self::clip("The best of {$placeName}, kept for the boss and a clear.", 255),
            ];
            $bossCode = null;
            $trashCeiling = $band['rec_min'];
            $bossLevel = $band['rec_max'];
            $letters = ['skirmisher' => 's', 'brute' => 'r', 'caster' => 'c', 'boss' => 'x'];
            $pools = ['skirmisher' => 'skirmishers', 'brute' => 'brutes', 'caster' => 'casters', 'boss' => 'bosses'];
            foreach (array_keys($letters) as $role) {
                $beast = $theme[$pools[$role]][$index];
                $monsterName = $role === 'boss'
                    ? self::uniqueName("{$beast} of the {$bag['noun']}", $takenNames)
                    : self::uniqueName("{$bag['adj']} {$beast}", $takenNames);
                $monsterCode = self::uniqueCode("{$placeCode}_{$letters[$role]}", $takenCodes, self::CODE_MONSTER);
                $level = self::roleLevel($role, $band);
                $magic = $role === 'boss' ? (bool) $theme['boss_magic'] : $role === 'caster';
                $loot = $role === 'boss' ? $bossLootCode : $lootCode;
                $monsters[] = self::monster($monsterCode, $monsterName, $level, $band['rec_min'], $role === 'boss', $magic, $role, $loot, $placeName, $settings);
                if ($role === 'boss') {
                    $bossCode = $monsterCode;
                    $bossLevel = $level;
                } else {
                    $trashCeiling = max($trashCeiling, $level);
                    $group = $settings['roles'][$role]['group'];
                    $spawns[] = [
                        'place' => $placeCode,
                        'monster' => $monsterCode,
                        'weight' => (int) $settings['roles'][$role]['weight'],
                        'min_group' => (int) $group[0],
                        'max_group' => (int) $group[1],
                    ];
                }
            }
            $ceilings[$index] = ['trash' => $trashCeiling, 'boss' => $bossLevel];
            $chance = min((float) $settings['boss_chance_cap'], (float) $settings['boss_chance_base'] + $index * (float) $settings['boss_chance_step']);
            $encounterMin = min((int) $settings['encounter_cap'], (int) $settings['encounter_min_base'] + intdiv($band['rec_level'], (int) $settings['encounter_min_per']));
            $encounterMax = min((int) $settings['encounter_cap'], $encounterMin + (int) $settings['encounter_span']);
            $places[] = [
                'code' => $placeCode,
                'name' => $placeName,
                'place_type' => $type,
                'min_level' => $band['min_level'],
                'rec_min_level' => $band['rec_min'],
                'rec_max_level' => $band['rec_max'],
                'rec_level' => $band['rec_level'],
                'encounter_min' => $encounterMin,
                'encounter_max' => max($encounterMin, $encounterMax),
                'boss' => $bossCode,
                'trash_loot' => $lootCode,
                'boss_loot' => $bossLootCode,
                'boss_chance' => round($chance, 2),
                'rarity_bonus' => round((float) $settings['rarity_base'] + $index * (float) $settings['rarity_step'], 2),
                'gold_mult' => round((float) $settings['gold_base'] + $index * (float) $settings['gold_step'], 2),
                'chest' => $bossLootCode,
                'map_x' => (int) $pins[$index][0],
                'map_y' => (int) $pins[$index][1],
                'description' => self::clip("{$placeName}. {$theme['mood']}", 255),
            ];
            $essenceName = self::uniqueName("Heart of the {$beast}", $takenNames);
            $essences[] = [
                'code' => self::uniqueCode("{$placeCode}_ess", $takenCodes, self::CODE_MATERIAL),
                'name' => $essenceName,
                'glyph' => $theme['glyph'],
                'description' => self::clip("{$essenceName}, from {$placeName}. Any place essence finishes a skill.", 255),
                'place' => $placeCode,
                'sort_order' => 300 + $ordinal * 10 + $index,
            ];
        }

        $items = self::items($regionCode, $bag['noun'], $lowLevel, $midLevel, $highLevel, $takenCodes, $takenNames, $settings['gear']);
        $entries = [];
        foreach ($places as $index => $place) {
            $trashTable = $place['trash_loot'];
            $bossTable = $place['boss_loot'];
            $entries[] = ['table' => $trashTable, 'type' => 'nothing', 'item' => null, 'weight' => (int) $settings['loot_nothing']];
            $entries[] = ['table' => $bossTable, 'type' => 'nothing', 'item' => null, 'weight' => (int) $settings['loot_nothing']];
            $trashCeiling = $ceilings[$index]['trash'];
            $bossLevel = $ceilings[$index]['boss'];
            $better = array_values(array_filter(
                $items,
                fn (array $item) => (int) $item['req_level'] > $lowLevel && (int) $item['req_level'] <= $bossLevel,
            ));
            foreach ($items as $item) {
                $req = (int) $item['req_level'];
                if ($req <= $trashCeiling) {
                    $entries[] = ['table' => $trashTable, 'type' => 'item', 'item' => $item['code'], 'weight' => (int) $settings['loot_item']];
                }
                $onBoss = $better !== [] ? ($req > $lowLevel && $req <= $bossLevel) : ($req <= $bossLevel);
                if ($onBoss) {
                    $weight = $req >= $bossLevel ? (int) $settings['loot_item'] * 2 : (int) $settings['loot_item'];
                    $entries[] = ['table' => $bossTable, 'type' => 'item', 'item' => $item['code'], 'weight' => $weight];
                }
            }
        }

        return [
            'code' => $regionCode,
            'name' => $regionName,
            'blurb' => self::clip($theme['mood'], 255),
            'sort_order' => $ordinal,
            'theme' => $theme['code'],
            'from' => $bands[0]['rec_min'],
            'to' => $bands[$count - 1]['rec_max'],
            'loot_tables' => $lootTables,
            'items' => $items,
            'loot_entries' => $entries,
            'monsters' => $monsters,
            'places' => $places,
            'spawns' => $spawns,
            'essences' => $essences,
        ];
    }

    /**
     * @param  list<string>  $usedThemeCodes
     * @return array<string, mixed>
     */
    private static function theme(Rng $rng, array $usedThemeCodes): array
    {
        $used = array_fill_keys($usedThemeCodes, true);
        $pool = array_values(array_filter(ThemeCatalog::all(), fn (array $theme) => ! isset($used[$theme['code']])));
        if ($pool === []) {
            $pool = ThemeCatalog::all();
        }

        return $rng->choice($pool);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<array{min_level: int, rec_min: int, rec_max: int, rec_level: int}>
     */
    private static function bands(int $frontierRecMax, int $count, array $settings): array
    {
        $width = (int) $settings['band_width'];
        $step = (int) $settings['band_step'];
        $dungeonWidth = (int) $settings['dungeon_band_width'];
        $recMin = $frontierRecMax - (int) $settings['overlap'] + 1;
        $bands = [];
        for ($i = 0; $i < $count; $i++) {
            $isDungeon = $i === $count - 1;
            $bandWidth = $isDungeon ? $dungeonWidth : $width;
            $recMax = $recMin + $bandWidth - 1;
            $bands[] = [
                'min_level' => max(1, $recMin - 1),
                'rec_min' => $recMin,
                'rec_max' => $recMax,
                'rec_level' => $isDungeon ? $recMax : $recMin + intdiv($bandWidth, 2),
            ];
            $recMin += $isDungeon ? $bandWidth : $step;
        }

        return $bands;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    /** @param  array{rec_min: int, rec_max: int}  $band */
    private static function roleLevel(string $role, array $band): int
    {
        $span = $band['rec_max'] - $band['rec_min'];

        return match ($role) {
            'skirmisher' => $band['rec_min'],
            'caster' => $band['rec_min'] + intdiv($span, 3),
            'brute' => $band['rec_min'] + intdiv($span, 2),
            default => $band['rec_max'],
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function monster(
        string $code,
        string $name,
        int $level,
        int $floor,
        bool $boss,
        bool $magic,
        string $role,
        string $loot,
        string $placeName,
        array $settings,
    ): array {
        $formula = $settings['monster'];
        $mult = $settings['roles'][$role];
        $depth = max(0, $level - $floor);
        $pressure = $boss
            ? 1 + (float) $formula['boss_depth_pressure'] * $depth
            : 1 + (float) $formula['depth_pressure'] * $depth;
        $xp = Numbers::halfUp((float) $formula['xp_factor'] * ($level ** (float) $formula['xp_exp'])) * (int) $mult['xp'];
        $goldMin = (int) $formula['gold_min_per_level'] * $level * (int) $mult['gold'];
        $goldMax = (int) $formula['gold_max_per_level'] * $level * (int) $mult['gold'];

        return [
            'code' => $code,
            'name' => $name,
            'level' => $level,
            'is_boss' => $boss,
            'hp' => max(1, Numbers::halfUp(((float) $formula['hp_base'] + (float) $formula['hp_per_level'] * $level) * (float) $mult['hp'] * $pressure)),
            'attack' => max(1, Numbers::halfUp(((float) $formula['atk_base'] + (float) $formula['atk_per_level'] * $level) * (float) $mult['atk'] * $pressure)),
            'defense' => max(0, Numbers::halfUp(((float) $formula['def_base'] + (float) $formula['def_per_level'] * $level) * (float) $mult['def'] * $pressure)),
            'mres' => max(0, Numbers::halfUp(((float) $formula['mres_base'] + (float) $formula['mres_per_level'] * $level) * (float) $mult['mres'] * $pressure)),
            'speed' => max(1, (int) $formula['speed_base'] + $level + (int) $mult['speed']),
            'crit_pct' => (float) $mult['crit'],
            'dodge_pct' => (float) $mult['dodge'],
            'damage_type' => $magic ? 'magic' : 'physical',
            'xp' => max(1, $xp),
            'gold_min' => $goldMin,
            'gold_max' => max($goldMin, $goldMax),
            'loot' => $loot,
            'drop_chance' => $boss ? 1.0 : (float) $formula['trash_drop'],
            'description' => self::clip($boss ? "The last word in {$placeName}." : "It keeps {$placeName}.", 255),
        ];
    }

    /**
     * @param  array<string, true>  $takenCodes
     * @param  array<string, true>  $takenNames
     * @param  array<string, mixed>  $gear
     * @return list<array<string, mixed>>
     */
    private static function items(string $regionCode, string $noun, int $low, int $mid, int $high, array &$takenCodes, array &$takenNames, array $gear): array
    {
        $patterns = [
            ['sw', 'Blade', 'weapon', 'sword', 'str', false, 'power'],
            ['bw', 'Bow', 'weapon', 'bow', 'dex', true, 'power'],
            ['st', 'Staff', 'weapon', 'staff', 'int', true, 'power'],
            ['ch', 'Mail', 'chest', 'mail', null, false, 'armor'],
            ['hm', 'Helm', 'head', 'mail', null, false, 'helm'],
            ['rg', 'Ring', 'ring', 'jewelry', null, false, 'ring'],
        ];
        $tiers = [
            ['l', $low, ''],
            ['m', $mid, 'Tempered '],
            ['b', $high, 'Sovereign '],
        ];
        $specs = [];
        foreach ($tiers as [$mark, $level, $title]) {
            foreach ($patterns as [$suffix, $name, $slot, $type, $scaling, $twoHand, $kind]) {
                $specs[] = ["{$suffix}_{$mark}", "{$title}{$noun} {$name}", $slot, $type, $scaling, $twoHand, $level, $kind];
            }
        }
        $items = [];
        foreach ($specs as [$suffix, $name, $slot, $type, $scaling, $twoHand, $level, $kind]) {
            $power = Numbers::halfUp(((float) $gear['power_base'] + (float) $gear['power_per_level'] * $level) * ($twoHand ? (float) $gear['two_hand_mult'] : 1));
            $armor = match ($kind) {
                'armor' => Numbers::halfUp((float) $gear['armor_base'] + (float) $gear['armor_per_level'] * $level),
                'helm' => Numbers::halfUp((float) $gear['helm_base'] + (float) $gear['helm_per_level'] * $level),
                default => 0,
            };
            $mres = match ($kind) {
                'power' => $scaling === 'int' ? max(1, intdiv($level, (int) $gear['staff_mres_div'])) : 0,
                'helm' => intdiv($level, (int) $gear['helm_mres_div']),
                default => 0,
            };
            $implicit = match ($kind) {
                'power' => $scaling === 'dex' ? ['speed' => (int) $gear['bow_speed']] : ($scaling === 'int' ? ['int' => max(1, intdiv($level, (int) $gear['int_per']))] : null),
                'ring' => ['luk' => max(2, intdiv($level, (int) $gear['luk_per']))],
                default => null,
            };
            $items[] = [
                'code' => self::uniqueCode("{$regionCode}_{$suffix}", $takenCodes, self::CODE_ITEM),
                'name' => self::uniqueName($name, $takenNames),
                'slot' => $slot,
                'item_type' => $type,
                'scaling_stat' => $scaling,
                'two_handed' => $twoHand,
                'base_power' => $kind === 'power' ? max(1, $power) : 0,
                'base_armor' => $armor,
                'base_mres' => $mres,
                'implicit_mods' => $implicit,
                'req_level' => $level,
                'base_value' => max(1, Numbers::halfUp($level * $level * (float) $gear['value_factor'])),
                'description' => self::clip("Made where {$noun} is the only name that matters.", 255),
            ];
        }

        return $items;
    }

    /** @param  array<string, string>  $bag */
    private static function fill(string $pattern, array $bag): string
    {
        return strtr($pattern, ['{adj}' => $bag['adj'], '{noun}' => $bag['noun']]);
    }

    /** @param  array<string, true>  $taken */
    private static function uniqueCode(string $code, array &$taken, int $max): string
    {
        $base = self::clip($code, $max);
        $candidate = $base;
        $n = 2;
        while (isset($taken[$candidate])) {
            $suffix = (string) $n;
            $candidate = self::clip($base, $max - strlen($suffix)).$suffix;
            $n++;
        }
        $taken[$candidate] = true;

        return $candidate;
    }

    /** @param  array<string, true>  $taken */
    private static function uniqueName(string $name, array &$taken): string
    {
        $base = self::clip($name, self::NAME);
        $candidate = $base;
        $n = 2;
        while (isset($taken[strtolower($candidate)])) {
            $suffix = " {$n}";
            $candidate = self::clip($base, self::NAME - strlen($suffix)).$suffix;
            $n++;
        }
        $taken[strtolower($candidate)] = true;

        return $candidate;
    }

    private static function clip(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max);
    }
}
