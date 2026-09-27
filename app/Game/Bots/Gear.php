<?php

namespace App\Game\Bots;

use App\Game\Rules;
use App\Game\Stats;

/** Loadout scoring, equipping, and selling junk (DESIGN.md §8.4, §8.5). */
final class Gear
{
    public static function loadoutScore(array $state, array $loadout, array $balance): float
    {
        $derived = Stats::derived($state['stats'], (int) $state['level'], array_values(array_filter($loadout)), $balance);
        $values = [
            'hp' => $derived['hp'],
            'attack' => $derived['attack'],
            'defense' => $derived['defense'],
            'mres' => $derived['mres'],
            'crit' => $derived['crit'],
            'dodge' => $derived['dodge'],
            'speed' => $derived['speed'],
            'mf' => $derived['luck'],
        ];
        $weights = $state['profile']['gear_weights'];
        $score = 0.0;
        foreach ($balance['bots']['gear_units'] as $key => $unit) {
            $score += (float) ($weights[$key] ?? 0) * $values[$key] / $unit;
        }

        return $score;
    }

    private static function same(?array $a, ?array $b): bool
    {
        return $a !== null && $b !== null && ($a['id'] ?? null) !== null && ($a['id'] ?? null) === ($b['id'] ?? null);
    }

    /** Same item, or both empty. */
    private static function sameSlot(?array $a, ?array $b): bool
    {
        return ($a === null && $b === null) || self::same($a, $b);
    }

    public static function withItem(array $loadout, string $slot, ?array $item): array
    {
        if ($item !== null) {
            foreach ($loadout as $key => $current) {
                if ($key !== $slot && self::same($current, $item)) {
                    $loadout[$key] = null;
                }
            }
        }
        $loadout[$slot] = $item;
        $weapon = $loadout['weapon'] ?? null;
        if (! empty($loadout['offhand']) && $weapon && ! empty($weapon['two_handed'])) {
            $loadout['offhand'] = null;
        }

        return $loadout;
    }

    public static function candidatesFor(string $slot, array $items, array $loadout): array
    {
        $weapon = $loadout['weapon'] ?? null;
        if ($slot === 'offhand' && $weapon && ! empty($weapon['two_handed'])) {
            return [];
        }
        $used = [];
        foreach ($loadout as $key => $current) {
            if ($key !== $slot && $current && ($current['id'] ?? null) !== null) {
                $used[] = $current['id'];
            }
        }
        $found = [];
        foreach ($items as $item) {
            if (in_array($item['id'] ?? null, $used, true)) {
                continue;
            }
            $kind = Rules::templateSlot($item);
            $fits = in_array($slot, ['ring1', 'ring2'], true) ? $kind === 'ring' : $kind === $slot;
            if ($fits) {
                $found[] = $item;
            }
        }

        return $found;
    }

    private static function ownedLegal(array $state): array
    {
        return array_values(array_filter($state['items'], fn ($item) => ($item['status'] ?? 'owned') === 'owned'
            && (int) $item['req_level'] <= (int) $state['level']));
    }

    private static function bestOffhand(array $state, array $loadout, array $items, array $balance): array
    {
        $trial = $loadout;
        $trial['offhand'] = null;
        $best = $trial;
        $bestScore = self::loadoutScore($state, $trial, $balance);
        foreach (self::candidatesFor('offhand', $items, $trial) as $item) {
            $candidate = self::withItem($trial, 'offhand', $item);
            $score = self::loadoutScore($state, $candidate, $balance);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$candidate, $score];
            }
        }

        return $best;
    }

    public static function equipBest(array &$state, array $balance): array
    {
        $items = self::ownedLegal($state);
        $slots = $balance['bots']['slots'];
        $current = [];
        foreach ($slots as $slot) {
            $current[$slot] = $state['equipment'][$slot] ?? null;
        }
        $hysteresis = (float) $balance['bots']['equip_hysteresis'];
        for ($pass = 0; $pass < (int) $balance['bots']['equip_passes']; $pass++) {
            foreach ($slots as $slot) {
                if ($slot === 'weapon') {
                    $bestLoadout = $current;
                    $bestScore = self::loadoutScore($state, $current, $balance);
                    $seen = [];
                    foreach ([null, ...self::candidatesFor('weapon', $items, $current)] as $weapon) {
                        $marker = $weapon === null ? 'none' : (string) $weapon['id'];
                        if (isset($seen[$marker])) {
                            continue;
                        }
                        $seen[$marker] = true;
                        $trial = self::withItem($current, 'weapon', $weapon);
                        if ($weapon && empty($weapon['two_handed'])) {
                            $trial = self::bestOffhand($state, $trial, $items, $balance);
                        }
                        if (self::sameSlot($weapon, $current['weapon']) && self::sameSlot($trial['offhand'], $current['offhand'])) {
                            continue;
                        }
                        $score = self::loadoutScore($state, $trial, $balance);
                        if ($score > $bestScore * $hysteresis) {
                            [$bestLoadout, $bestScore] = [$trial, $score];
                        }
                    }
                    $current = $bestLoadout;

                    continue;
                }
                $best = $current[$slot];
                $bestScore = self::loadoutScore($state, $current, $balance);
                foreach (self::candidatesFor($slot, $items, $current) as $item) {
                    $score = self::loadoutScore($state, self::withItem($current, $slot, $item), $balance);
                    if ($score > $bestScore * $hysteresis) {
                        [$best, $bestScore] = [$item, $score];
                    }
                }
                $current = self::withItem($current, $slot, $best);
            }
        }
        $state['equipment'] = $current;

        return $current;
    }

    private static function improves(array $state, array $item, array $balance): bool
    {
        $items = self::ownedLegal($state);
        $current = $state['equipment'];
        $base = self::loadoutScore($state, $current, $balance);
        $kind = Rules::templateSlot($item);
        $slots = $kind === 'ring' ? ['ring1', 'ring2'] : [$kind];
        foreach ($slots as $slot) {
            $trial = ($slot === 'weapon' && empty($item['two_handed']))
                ? self::bestOffhand($state, self::withItem($current, 'weapon', $item), $items, $balance)
                : self::withItem($current, $slot, $item);
            if (self::loadoutScore($state, $trial, $balance) > $base) {
                return true;
            }
        }

        return false;
    }

    /** @return array{items: list<array>, consumables: list<array>} what was sold */
    public static function sellJunk(array &$state, array $balance): array
    {
        $profile = $state['profile'];
        $soldItems = [];
        $soldConsumables = [];
        if (! ($profile['sell_junk'] ?? true)) {
            return ['items' => $soldItems, 'consumables' => $soldConsumables];
        }
        $bots = $balance['bots'];
        $cap = (int) $balance['loot']['inventory_capacity'];
        $equippedIds = [];
        foreach ($state['equipment'] as $item) {
            if ($item && ($item['id'] ?? null) !== null) {
                $equippedIds[] = $item['id'];
            }
        }
        $unequippedCount = function () use (&$state, $equippedIds): int {
            $n = 0;
            foreach ($state['items'] as $item) {
                if (($item['status'] ?? 'owned') === 'owned' && ! in_array($item['id'] ?? null, $equippedIds, true)) {
                    $n++;
                }
            }

            return $n;
        };

        if ($unequippedCount() >= floor($cap * $bots['junk_start_ratio'])) {
            $junk = [];
            foreach ($state['items'] as $key => $item) {
                if (($item['status'] ?? 'owned') !== 'owned' || in_array($item['id'] ?? null, $equippedIds, true)) {
                    continue;
                }
                $tooHigh = (int) $item['req_level'] > (int) $state['level'] + $bots['junk_level_ahead'];
                if ($tooHigh || ! self::improves($state, $item, $balance)) {
                    $junk[] = $key;
                }
            }
            usort($junk, fn ($a, $b) => [$state['items'][$a]['sell_value'] ?? 0, $state['items'][$a]['id'] ?? 0]
                <=> [$state['items'][$b]['sell_value'] ?? 0, $state['items'][$b]['id'] ?? 0]);
            $target = floor($cap * $bots['junk_stop_ratio']);
            foreach ($junk as $key) {
                if ($unequippedCount() <= $target) {
                    break;
                }
                $state['items'][$key]['status'] = 'sold';
                $value = (int) ($state['items'][$key]['sell_value'] ?? 0);
                $state['gold'] = (int) $state['gold'] + $value;
                $soldItems[] = $state['items'][$key];
                $state['ledger'][] = [
                    'action' => 'sell',
                    'item_id' => $state['items'][$key]['id'] ?? null,
                    'gold_delta' => $value,
                    'gold_after' => (int) $state['gold'],
                    'quantity' => 1,
                ];
            }
        }

        $limit = (int) $profile['potion_target'] * (int) $bots['surplus_potion_mult'];
        foreach ($state['consumables'] as &$consumable) {
            $extra = (int) $consumable['quantity'] - $limit;
            if ($extra <= 0) {
                continue;
            }
            $payout = $extra * (int) ($consumable['sell_price'] ?? 0);
            $consumable['quantity'] = (int) $consumable['quantity'] - $extra;
            $consumable['belt_qty'] = min((int) ($consumable['belt_qty'] ?? 0), $consumable['quantity']);
            $state['gold'] = (int) $state['gold'] + $payout;
            $soldConsumables[] = ['id' => $consumable['id'], 'code' => $consumable['code'], 'qty' => $extra];
            $state['ledger'][] = [
                'action' => 'sell',
                'consumable_id' => $consumable['id'],
                'gold_delta' => $payout,
                'gold_after' => (int) $state['gold'],
                'quantity' => $extra,
            ];
        }
        unset($consumable);

        return ['items' => $soldItems, 'consumables' => $soldConsumables];
    }
}
