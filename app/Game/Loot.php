<?php

namespace App\Game;

use InvalidArgumentException;

/** Rarity, affixes, item naming, drops, gold, and pack capacity (DESIGN.md §6). */
final class Loot
{
    /** Weighted pick; returns the key of the chosen entry, or null when nothing has weight. */
    public static function weightedKey(array $entries, Rng $rng, callable $weightOf): int|string|null
    {
        if ($entries === []) {
            return null;
        }
        $weights = [];
        $total = 0.0;
        foreach ($entries as $key => $entry) {
            $weights[$key] = (float) $weightOf($entry);
            $total += $weights[$key];
        }
        if ($total <= 0) {
            return null;
        }
        $roll = $rng->random() * $total;
        $acc = 0.0;
        foreach ($weights as $key => $weight) {
            $acc += $weight;
            if ($roll < $acc) {
                return $key;
            }
        }

        return array_key_last($entries);
    }

    public static function weightedPick(array $entries, Rng $rng, callable $weightOf): mixed
    {
        $key = self::weightedKey($entries, $rng, $weightOf);

        return $key === null ? null : $entries[$key];
    }

    public static function tierByName(array $balance, string $name): array
    {
        foreach ($balance['loot']['rarity'] as $tier) {
            if ($tier['name'] === $name) {
                return $tier;
            }
        }
        throw new InvalidArgumentException("Unknown rarity {$name}");
    }

    public static function rollRarity(array $balance, float $luckFactor, Rng $rng, ?string $minRarity = null): array
    {
        $tiers = $balance['loot']['rarity'];
        $weights = [];
        foreach ($tiers as $index => $tier) {
            $weight = (float) $tier['weight'];
            if ($index >= 1) {
                $weight *= 1 + $luckFactor * $index;
            }
            $weights[$index] = $weight;
        }
        $picked = (int) self::weightedKey($weights, $rng, fn ($w) => $w);
        if ($minRarity !== null) {
            $floor = array_search($minRarity, array_column($tiers, 'name'), true);
            if ($picked < $floor) {
                return $tiers[$floor];
            }
        }

        return $tiers[$picked];
    }

    public static function affixGroups(array $affix): array
    {
        $groups = $affix['slot_groups'] ?? [];
        if (is_string($groups)) {
            return array_values(array_filter(explode(',', $groups)));
        }

        return $groups;
    }

    public static function rollAffixValue(array $affix, int $ilvl, Rng $rng, bool $legendary): int
    {
        $lo = (float) $affix['value_min_base'] + (float) $affix['value_per_ilvl'] * ($ilvl - 1);
        $hi = (float) $affix['value_max_base'] + (float) $affix['value_per_ilvl'] * ($ilvl - 1);
        $hi = max($hi, $lo);
        $once = fn (): int => max(1, Numbers::halfUp($rng->uniform($lo, $hi)));
        if ($legendary) {
            $first = $once();

            return max($first, $once());
        }

        return $once();
    }

    public static function rollAffixes(array $pool, array $template, int $ilvl, int $count, Rng $rng, array $balance, bool $legendary = false): array
    {
        $group = Rules::SLOT_GROUP[$template['slot']];
        $usedStats = array_keys(Stats::implicit($template));
        $usedIds = [];
        $prefixes = 0;
        $suffixes = 0;
        $picked = [];
        $maxPre = (int) $balance['loot']['max_prefixes'];
        $maxSuf = (int) $balance['loot']['max_suffixes'];
        for ($i = 0; $i < $count; $i++) {
            $eligible = [];
            foreach ($pool as $affix) {
                $affixId = $affix['id'] ?? $affix['code'];
                if (in_array($affixId, $usedIds, true)
                    || ! in_array($group, self::affixGroups($affix), true)
                    || (int) $affix['min_ilvl'] > $ilvl
                    || in_array($affix['stat_key'], $usedStats, true)
                    || ($affix['kind'] === 'prefix' && $prefixes >= $maxPre)
                    || ($affix['kind'] === 'suffix' && $suffixes >= $maxSuf)) {
                    continue;
                }
                $eligible[] = $affix;
            }
            if ($eligible === []) {
                break;
            }
            $choice = self::weightedPick($eligible, $rng, fn ($a) => $a['weight']);
            $picked[] = [
                'affix_id' => $choice['id'] ?? null,
                'code' => $choice['code'],
                'name' => $choice['name'],
                'kind' => $choice['kind'],
                'stat_key' => $choice['stat_key'],
                'value' => self::rollAffixValue($choice, $ilvl, $rng, $legendary),
            ];
            $usedIds[] = $choice['id'] ?? $choice['code'];
            $usedStats[] = $choice['stat_key'];
            $choice['kind'] === 'prefix' ? $prefixes++ : $suffixes++;
        }

        return $picked;
    }

    public static function itemName(string $templateName, array $affixes): string
    {
        $best = function (string $kind) use ($affixes): string {
            $top = null;
            foreach ($affixes as $affix) {
                if ($affix['kind'] !== $kind) {
                    continue;
                }
                if ($top === null || ([$affix['value'], $affix['name']] <=> [$top['value'], $top['name']]) > 0) {
                    $top = $affix;
                }
            }

            return $top['name'] ?? '';
        };

        return implode(' ', array_filter([$best('prefix'), $templateName, $best('suffix')], fn ($p) => $p !== ''));
    }

    public static function sellValue(int $baseValue, float $valueMult, int $ilvl, array $balance): int
    {
        $raw = $baseValue * $valueMult * (1 + $balance['loot']['sell_per_ilvl'] * $ilvl);

        return max(1, Numbers::halfUp($raw));
    }

    /** @return array{0: int, 1: int, 2: int} [power, armor, mres] */
    public static function scaleBases(array $template, array $rarity, int $ilvl, array $balance): array
    {
        $extra = min(
            (float) $balance['loot']['ilvl_scale_cap'],
            $balance['loot']['ilvl_scale_per_level'] * ($ilvl - (int) $template['req_level']),
        );
        $scale = (float) $rarity['stat_mult'] * (1 + $extra);

        return [
            Numbers::halfUp((float) ($template['base_power'] ?? $template['power'] ?? 0) * $scale),
            Numbers::halfUp((float) ($template['base_armor'] ?? $template['armor'] ?? 0) * $scale),
            Numbers::halfUp((float) ($template['base_mres'] ?? $template['mres'] ?? 0) * $scale),
        ];
    }

    public static function buildInstance(array $template, int $ilvl, array $rarity, array $affixes, string $source, array $balance): array
    {
        [$power, $armor, $mres] = self::scaleBases($template, $rarity, $ilvl, $balance);
        $baseValue = (int) ($template['base_value'] ?? 1);

        return [
            'id' => null,
            'template_id' => $template['id'] ?? null,
            'code' => $template['code'] ?? null,
            'name' => $template['name'],
            'slot' => $template['slot'],
            'template_slot' => $template['slot'],
            'item_type' => $template['item_type'] ?? null,
            'scaling_stat' => $template['scaling_stat'] ?? null,
            'two_handed' => (bool) ($template['two_handed'] ?? false),
            'power' => $power,
            'armor' => $armor,
            'mres' => $mres,
            'implicit' => Stats::implicit($template),
            'affixes' => $affixes,
            'req_level' => (int) $template['req_level'],
            'base_value' => $baseValue,
            'rarity' => $rarity['name'],
            'item_level' => $ilvl,
            'display_name' => self::itemName($template['name'], $affixes),
            'sell_value' => self::sellValue($baseValue, (float) $rarity['value_mult'], $ilvl, $balance),
            'source' => $source,
            'status' => 'owned',
        ];
    }

    public static function rollItem(array $template, int $sourceLevel, float $luckFactor, array $affixPool, Rng $rng, array $balance, string $source, ?string $minRarity = null): array
    {
        $jitter = (int) $balance['loot']['ilvl_jitter'];
        $ilvl = max((int) $template['req_level'], $sourceLevel + $rng->randint(-$jitter, $jitter));
        $rarity = self::rollRarity($balance, $luckFactor, $rng, $minRarity);
        $affixes = self::rollAffixes($affixPool, $template, $ilvl, (int) $rarity['affixes'], $rng, $balance, $rarity['name'] === 'legendary');

        return self::buildInstance($template, $ilvl, $rarity, $affixes, $source, $balance);
    }

    public static function tryDrop(?array $monster, ?array $table, int $charLuk, float $luckFactor, array $place, array $affixPool, Rng $rng, array $balance, bool $boss = false, bool $chest = false): ?array
    {
        if (! $table || empty($table['entries'])) {
            return null;
        }
        $loot = $balance['loot'];
        $trip = $balance['trip'];
        $minRarity = null;
        if ($chest) {
            $sourceLevel = (int) $place['rec_level'];
            $extra = (float) $trip['chest_luck_bonus'];
            $source = 'chest';
        } elseif ($boss) {
            $sourceLevel = (int) $monster['level'];
            $extra = (float) $trip['boss_luck_bonus'];
            $source = 'boss';
            $minRarity = $trip['boss_min_rarity'];
        } else {
            $chance = min((float) $loot['drop_chance_cap'], (float) $monster['drop_chance'] * (1 + $loot['luk_drop_bonus'] * $charLuk));
            if ($rng->random() >= $chance) {
                return null;
            }
            $sourceLevel = (int) $monster['level'];
            $extra = 0.0;
            $source = 'drop';
        }
        $entry = self::weightedPick($table['entries'], $rng, fn ($row) => $row['weight']);
        if ($entry === null || ($entry['entry_type'] ?? null) === 'nothing' || empty($entry['template'])) {
            return null;
        }
        $luck = $luckFactor + (float) ($place['rarity_bonus'] ?? 0) + $extra;

        return self::rollItem($entry['template'], $sourceLevel, $luck, $affixPool, $rng, $balance, $source, $minRarity);
    }

    /**
     * How many independent rolls a clear chest makes. Deeper places (higher recommended level) roll more.
     * Affix count still comes from the rarity tier, not from this number.
     */
    public static function chestRollCount(int $recLevel, array $balance): int
    {
        $loot = $balance['loot'];
        $base = (int) $loot['chest_rolls_base'];
        $every = max(1, (int) $loot['chest_rolls_every']);
        $cap = (int) $loot['chest_rolls_cap'];

        return max(1, min($cap, $base + intdiv(max(0, $recLevel - 1), $every)));
    }

    /**
     * Generate a sealed chest's contents. Luck, level, and roll count are the values stored when the chest was found.
     *
     * @return list<array>
     */
    public static function rollChest(?array $table, int $rolls, float $luckFactor, int $sourceLevel, array $affixPool, Rng $rng, array $balance): array
    {
        if (! $table || empty($table['entries']) || $rolls < 1) {
            return [];
        }
        $items = [];
        for ($i = 0; $i < $rolls; $i++) {
            $entry = self::weightedPick($table['entries'], $rng, fn ($row) => $row['weight']);
            if ($entry === null || ($entry['entry_type'] ?? null) === 'nothing' || empty($entry['template'])) {
                continue;
            }
            $item = self::rollItem($entry['template'], $sourceLevel, $luckFactor, $affixPool, $rng, $balance, 'chest');
            $item['encounter_seq'] = null;
            $items[] = $item;
        }

        return $items;
    }

    public static function goldDrop(array $monster, array $place, float $goldFind, Rng $rng): int
    {
        $rolled = $rng->randint((int) $monster['gold_min'], (int) $monster['gold_max']);

        return max(0, Numbers::halfUp($rolled * (float) $place['gold_mult'] * $goldFind));
    }

    /**
     * Decide which new items are kept and which commons are auto-sold to make room (DESIGN.md §6.6).
     *
     * @param  list<array>  $newItems
     * @param  list<array{id: int, sell_value: int, name: string}>  $commons  owned, unequipped commons
     * @return array{kept: list<array>, sold_owned: list<array>, sold_new: list<array>}
     */
    public static function planCapacity(array $newItems, array $commons, int $capacity, int $inventoryCount): array
    {
        $pool = [];
        foreach ($commons as $row) {
            $pool[] = $row + ['origin' => 'owned'];
        }
        $kept = [];
        $soldOwned = [];
        $soldNew = [];
        $count = $inventoryCount;
        $addToPool = function (int $keptIndex, array $item) use (&$pool): void {
            if (($item['rarity'] ?? null) === 'common') {
                $pool[] = ['id' => null, 'kept_index' => $keptIndex, 'sell_value' => $item['sell_value'], 'name' => $item['display_name'], 'origin' => 'new'];
            }
        };
        foreach ($newItems as $item) {
            if ($count < $capacity) {
                $kept[] = $item;
                $count++;
                $addToPool(array_key_last($kept), $item);

                continue;
            }
            usort($pool, fn ($a, $b) => [$a['sell_value'], $a['id'] ?? 0] <=> [$b['sell_value'], $b['id'] ?? 0]);
            if ($pool === []) {
                $soldNew[] = $item;

                continue;
            }
            $victim = array_shift($pool);
            if ($victim['origin'] === 'owned') {
                unset($victim['origin']);
                $soldOwned[] = $victim;
            } else {
                $soldNew[] = $kept[$victim['kept_index']];
                unset($kept[$victim['kept_index']]);
            }
            $kept[] = $item;
            $addToPool(array_key_last($kept), $item);
        }

        return ['kept' => array_values($kept), 'sold_owned' => $soldOwned, 'sold_new' => $soldNew];
    }
}
