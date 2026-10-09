<?php

namespace App\Game;

/** Skills, horcrux feeding, material drops, and power level (DESIGN.md §6.7–§6.8). */
final class Growth
{
    /** Flat mods added inside Stats::derived, before caps. */
    public static function bonuses(array $ranks, int $horcruxTier, ?array $balance = null): array
    {
        $balance ??= Balance::get();
        $bonus = ['attack' => 0.0, 'defense' => 0.0, 'mres' => 0.0, 'hp' => 0.0, 'crit_pct' => 0.0, 'magic_find_pct' => 0.0];
        foreach ($balance['growth']['skills'] as $skill) {
            $rank = (int) ($ranks[$skill['code']] ?? 0);
            if ($rank <= 0) {
                continue;
            }
            foreach (['attack', 'defense', 'mres', 'hp', 'crit_pct', 'magic_find_pct'] as $key) {
                if (isset($skill[$key])) {
                    $bonus[$key] += (float) $skill[$key] * $rank;
                }
            }
        }
        $per = $balance['growth']['horcrux']['per_tier'];
        $tier = max(0, $horcruxTier);
        foreach (['hp', 'attack', 'defense', 'mres'] as $key) {
            if (isset($per[$key])) {
                $bonus[$key] += (float) $per[$key] * $tier;
            }
        }

        return $bonus;
    }

    public static function powerLevel(int $level, array $ranks, int $horcruxTier, ?array $balance = null, int $premiumPower = 0): int
    {
        $balance ??= Balance::get();
        $g = $balance['growth'];
        $skillRanks = 0;
        foreach ($g['skills'] as $skill) {
            $skillRanks += (int) ($ranks[$skill['code']] ?? 0);
        }

        return (int) $g['power_per_level'] * $level
            + (int) $g['power_per_skill_rank'] * $skillRanks
            + (int) $g['power_per_horcrux_tier'] * max(0, $horcruxTier)
            + max(0, $premiumPower);
    }

    public static function rankCost(int $rank, ?array $balance = null): ?array
    {
        $balance ??= Balance::get();
        foreach ($balance['growth']['ranks'] as $row) {
            if ((int) $row['rank'] === $rank) {
                return $row;
            }
        }

        return null;
    }

    public static function skillByCode(string $code, ?array $balance = null): ?array
    {
        $balance ??= Balance::get();
        foreach ($balance['growth']['skills'] as $skill) {
            if ($skill['code'] === $code) {
                return $skill;
            }
        }

        return null;
    }

    /** @param list<array{code: string, qty: int, kind: string}> $materials */
    public static function materialQty(array $materials, string $codeOrKind): int
    {
        $total = 0;
        foreach ($materials as $mat) {
            if ($mat['code'] === $codeOrKind || ($codeOrKind === 'essence' && ($mat['kind'] ?? '') === 'essence')) {
                $total += (int) $mat['qty'];
            }
        }

        return $total;
    }

    /**
     * Spend a rank cost out of a material list. Essence spends the first stacks in code order.
     *
     * @param list<array{code: string, qty: int, kind: string}> $materials
     */
    public static function pay(array &$materials, string $need, int $qty): bool
    {
        if ($need === 'essence') {
            $pool = [];
            foreach ($materials as $i => $mat) {
                if (($mat['kind'] ?? '') === 'essence' && (int) $mat['qty'] > 0) {
                    $pool[] = $i;
                }
            }
            usort($pool, fn ($a, $b) => $materials[$a]['code'] <=> $materials[$b]['code']);
            $left = $qty;
            $plan = [];
            foreach ($pool as $i) {
                $take = min($left, (int) $materials[$i]['qty']);
                if ($take > 0) {
                    $plan[$i] = $take;
                    $left -= $take;
                }
                if ($left === 0) {
                    break;
                }
            }
            if ($left > 0) {
                return false;
            }
            foreach ($plan as $i => $take) {
                $materials[$i]['qty'] -= $take;
            }

            return true;
        }
        foreach ($materials as $i => $mat) {
            if ($mat['code'] === $need) {
                if ((int) $mat['qty'] < $qty) {
                    return false;
                }
                $materials[$i]['qty'] -= $qty;

                return true;
            }
        }

        return false;
    }

    /** @param list<array{code: string, name: string, kind: string}> $catalog */
    public static function rollKill(array $catalog, bool $boss, Rng $rng, ?array $balance = null): array
    {
        $balance ??= Balance::get();
        $catalog = array_values($catalog);
        usort($catalog, fn ($a, $b) => $a['code'] <=> $b['code']);
        $found = [];
        foreach ($catalog as $mat) {
            $rate = $balance['growth']['drops'][$mat['kind']] ?? null;
            if ($rate === null) {
                continue;
            }
            $chance = min(0.95, (float) $rate['chance'] * ($boss ? (float) $rate['boss_mult'] : 1));
            if ($rng->random() >= $chance) {
                continue;
            }
            $qty = $rng->randint((int) $rate['qty_min'], (int) $rate['qty_max']);
            $found[] = [
                'code' => $mat['code'],
                'name' => $mat['name'],
                'kind' => $mat['kind'],
                'qty' => $qty,
            ];
        }

        return $found;
    }

    public static function feedValue(array $item, ?array $balance = null): int
    {
        $balance ??= Balance::get();
        $feed = $balance['growth']['horcrux']['feed'];
        $rarity = $item['rarity'] ?? 'common';
        $base = (int) ($feed[$rarity] ?? $feed['common']);
        $ilvl = (int) ($item['item_level'] ?? $item['ilvl'] ?? 1);

        return $base + (int) $feed['per_ilvl'] * $ilvl;
    }

    public static function tierFor(int $progress, ?array $balance = null): int
    {
        $balance ??= Balance::get();
        $tier = 0;
        foreach ($balance['growth']['horcrux']['thresholds'] as $index => $need) {
            if ($progress >= (int) $need) {
                $tier = (int) $index;
            }
        }

        return $tier;
    }

    public static function maxTier(?array $balance = null): int
    {
        $balance ??= Balance::get();

        return count($balance['growth']['horcrux']['thresholds']) - 1;
    }

    /** True when the next rank of some skill wants a place essence the character does not have. */
    public static function missingEssence(array $state, ?array $balance = null): bool
    {
        $balance ??= Balance::get();
        $materials = array_values($state['materials'] ?? []);
        foreach ($balance['growth']['skills'] as $skill) {
            $rank = (int) ($state['skills'][$skill['code']] ?? 0);
            $cost = self::rankCost($rank + 1, $balance);
            if ($cost && ($cost['material'] ?? '') === 'essence' && self::materialQty($materials, 'essence') < (int) $cost['qty']) {
                return true;
            }
        }

        return false;
    }

    public static function placeHasEssence(array $place): bool
    {
        foreach ($place['materials'] ?? [] as $mat) {
            if (($mat['kind'] ?? '') === 'essence') {
                return true;
            }
        }

        return false;
    }

    /**
     * Raise the lowest-ranked affordable skill, at most bot_ranks_per_run times.
     *
     * @return list<string> skill codes trained
     */
    public static function botTrain(array &$state, ?array $balance = null): array
    {
        $balance ??= Balance::get();
        $trained = [];
        $cap = (int) $balance['growth']['bot_ranks_per_run'];
        $materials = array_values($state['materials'] ?? []);
        $state['skills'] ??= [];
        for ($n = 0; $n < $cap; $n++) {
            $pick = null;
            foreach ($balance['growth']['skills'] as $index => $skill) {
                $code = $skill['code'];
                $rank = (int) ($state['skills'][$code] ?? 0);
                $cost = self::rankCost($rank + 1, $balance);
                if ($cost === null || (int) $state['gold'] < (int) $cost['gold']) {
                    continue;
                }
                if (self::materialQty($materials, $cost['material']) < (int) $cost['qty']) {
                    continue;
                }
                $candidate = [$rank, (int) $cost['gold'], $index];
                if ($pick === null || $candidate < $pick[0]) {
                    $pick = [$candidate, $cost, $code];
                }
            }
            if ($pick === null) {
                break;
            }
            [, $cost, $code] = $pick;
            if (! self::pay($materials, $cost['material'], (int) $cost['qty'])) {
                break;
            }
            $state['gold'] = (int) $state['gold'] - (int) $cost['gold'];
            $state['skills'][$code] = (int) ($state['skills'][$code] ?? 0) + 1;
            $trained[] = $code;
        }
        $state['materials'] = $materials;

        return $trained;
    }

    /**
     * Feed gear that would not improve the loadout into the horcrux, cheapest fuel last,
     * stopping at the next tier or bot_feed_per_run items.
     *
     * @return list<int> item ids destroyed
     */
    public static function botFeed(array &$state, ?array $balance = null): array
    {
        $balance ??= Balance::get();
        $state['horcrux'] ??= ['tier' => 0, 'progress' => 0];
        if ((int) $state['horcrux']['tier'] >= self::maxTier($balance)) {
            return [];
        }
        $equipped = [];
        foreach ($state['equipment'] ?? [] as $item) {
            if ($item && ($item['id'] ?? null) !== null) {
                $equipped[] = $item['id'];
            }
        }
        $junk = [];
        foreach ($state['items'] ?? [] as $key => $item) {
            if (($item['status'] ?? 'owned') !== 'owned' || in_array($item['id'] ?? null, $equipped, true)) {
                continue;
            }
            if (Bots\Gear::isUseful($state, $item, $balance)) {
                continue;
            }
            $junk[] = $key;
        }
        usort($junk, function ($a, $b) use ($state, $balance) {
            $ia = $state['items'][$a];
            $ib = $state['items'][$b];

            return [self::feedValue($ib, $balance), $ib['id'] ?? 0] <=> [self::feedValue($ia, $balance), $ia['id'] ?? 0];
        });
        $startTier = (int) $state['horcrux']['tier'];
        $fed = [];
        $limit = (int) $balance['growth']['bot_feed_per_run'];
        foreach ($junk as $key) {
            if (count($fed) >= $limit) {
                break;
            }
            if ((int) $state['horcrux']['tier'] > $startTier) {
                break;
            }
            $item = $state['items'][$key];
            $state['horcrux']['progress'] = (int) $state['horcrux']['progress'] + self::feedValue($item, $balance);
            $state['horcrux']['tier'] = self::tierFor((int) $state['horcrux']['progress'], $balance);
            $state['items'][$key]['status'] = 'destroyed';
            if (($item['id'] ?? null) !== null) {
                $fed[] = (int) $item['id'];
            }
        }

        return $fed;
    }

    /** @param list<array{code: string, name: string, qty: int}> $bag */
    public static function addStack(array &$bag, array $drop): void
    {
        foreach ($bag as &$row) {
            if ($row['code'] === $drop['code']) {
                $row['qty'] += (int) $drop['qty'];

                return;
            }
        }
        unset($row);
        $bag[] = ['code' => $drop['code'], 'name' => $drop['name'], 'qty' => (int) $drop['qty']];
    }
}
