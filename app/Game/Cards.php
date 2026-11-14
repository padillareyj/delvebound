<?php

namespace App\Game;

/**
 * RO-style random card slots and monster cards (DESIGN.md §6.10).
 * Gear rolls card_slots on drop; monsters may drop a random card; website/bots socket them.
 */
final class Cards
{
    public static function rollSlots(string $rarity, float $luckFactor, array $balance, Rng $rng): int
    {
        $cards = $balance['cards'];
        $weights = $cards['slot_chances'][$rarity] ?? ['0' => 1];
        $boosted = [];
        foreach ($weights as $slots => $weight) {
            $n = (int) $slots;
            $w = (float) $weight;
            if ($n > 0) {
                $w *= 1 + $luckFactor * (float) $cards['slot_luck_bonus'] * $n;
            }
            $boosted[$n] = $w;
        }
        $picked = (int) Loot::weightedKey($boosted, $rng, fn ($w) => $w);

        return max(0, min((int) $cards['max_slots'], $picked));
    }

    public static function rollRarity(array $balance, float $luckFactor, Rng $rng): array
    {
        $tiers = $balance['cards']['rarity'];
        $weights = [];
        foreach ($tiers as $index => $tier) {
            $weight = (float) $tier['weight'];
            if ($index >= 1) {
                $weight *= 1 + $luckFactor * $index;
            }
            $weights[$index] = $weight;
        }

        return $tiers[(int) Loot::weightedKey($weights, $rng, fn ($w) => $w)];
    }

    public static function rollBonuses(int $ilvl, array $tier, array $balance, Rng $rng): array
    {
        $pool = $balance['cards']['bonus_pool'];
        $count = min((int) $tier['affixes'], count($pool));
        $bonuses = [];
        $used = [];
        for ($i = 0; $i < $count; $i++) {
            $eligible = [];
            foreach ($pool as $index => $row) {
                if (! in_array($row['stat_key'], $used, true)) {
                    $eligible[$index] = $row;
                }
            }
            if ($eligible === []) {
                break;
            }
            $pick = Loot::weightedPick($eligible, $rng, fn () => 1.0);
            $used[] = $pick['stat_key'];
            $lo = (float) $pick['min'] + (float) $pick['per_ilvl'] * max(0, $ilvl - 1);
            $hi = (float) $pick['max'] + (float) $pick['per_ilvl'] * max(0, $ilvl - 1);
            $hi = max($hi, $lo);
            $raw = $rng->uniform($lo, $hi);
            $value = str_ends_with($pick['stat_key'], '_pct')
                ? round($raw, 1)
                : max(1, Numbers::halfUp($raw));
            $bonuses[$pick['stat_key']] = ($bonuses[$pick['stat_key']] ?? 0) + $value;
        }
        ksort($bonuses);

        return $bonuses;
    }

    public static function sellValue(int $ilvl, array $tier, array $balance): int
    {
        $c = $balance['cards'];

        return max(1, Numbers::halfUp(
            (float) $c['sell_base']
            * (float) $tier['value_mult']
            * (1 + (float) $c['sell_per_ilvl'] * max(0, $ilvl - 1))
        ));
    }

    public static function rollCard(array $monster, float $luckFactor, array $balance, Rng $rng, string $source = 'drop'): array
    {
        $ilvl = max(1, (int) ($monster['level'] ?? 1));
        $tier = self::rollRarity($balance, $luckFactor, $rng);
        $bonuses = self::rollBonuses($ilvl, $tier, $balance, $rng);

        return [
            'id' => null,
            'display_name' => ($monster['base_name'] ?? $monster['name'] ?? 'Monster').' Card',
            'monster_code' => $monster['code'] ?? null,
            'rarity' => $tier['name'],
            'item_level' => $ilvl,
            'bonuses' => $bonuses,
            'sell_value' => self::sellValue($ilvl, $tier, $balance),
            'source' => $source,
            'status' => 'owned',
        ];
    }

    public static function tryDrop(array $monster, float $luckFactor, int $charLuk, array $balance, Rng $rng): ?array
    {
        $c = $balance['cards'];
        $chance = (float) $c['drop_chance_base'] * (1 + (float) $c['luk_drop_bonus'] * $charLuk);
        if (! empty($monster['is_boss'])) {
            $chance *= (float) $c['boss_drop_mult'];
        }
        $chance = min((float) $c['drop_chance_cap'], $chance);
        if ($rng->random() >= $chance) {
            return null;
        }

        return self::rollCard($monster, $luckFactor + (float) ($monster['is_boss'] ? 0.25 : 0), $balance, $rng, ! empty($monster['is_boss']) ? 'boss' : 'drop');
    }

    public static function score(array $card, array $balance): float
    {
        $weights = $balance['cards']['score_weights'];
        $total = 0.0;
        foreach ($card['bonuses'] ?? [] as $key => $value) {
            $total += (float) ($weights[$key] ?? 0.5) * (float) $value;
        }

        return $total;
    }

    /** Sum socketed card bonuses on a list of equipment items. */
    public static function bonusesFromEquipment(array $equipment): array
    {
        $total = [];
        foreach ($equipment as $item) {
            if ($item === null) {
                continue;
            }
            foreach ($item['cards'] ?? [] as $card) {
                if ($card === null) {
                    continue;
                }
                foreach ($card['bonuses'] ?? [] as $key => $value) {
                    $total[$key] = ($total[$key] ?? 0) + (float) $value;
                }
            }
        }

        return $total;
    }

    /** @return array{0: bool, 1: string|null} */
    public static function canInsert(array $item, array $card, int $slotIndex): array
    {
        if (($card['status'] ?? 'owned') !== 'owned') {
            return [false, 'That card is not free.'];
        }
        $slots = (int) ($item['card_slots'] ?? 0);
        if ($slots < 1) {
            return [false, 'That piece has no card slots.'];
        }
        if ($slotIndex < 0 || $slotIndex >= $slots) {
            return [false, 'That socket does not exist.'];
        }
        $filled = $item['cards'] ?? [];
        if (! empty($filled[$slotIndex])) {
            return [false, 'That socket already holds a card.'];
        }

        return [true, null];
    }

    public static function insert(array &$item, array &$card, int $slotIndex): void
    {
        $item['cards'] ??= array_fill(0, (int) $item['card_slots'], null);
        $item['cards'][$slotIndex] = $card;
        $card['status'] = 'socketed';
        $card['socket_item_id'] = $item['id'] ?? null;
        $card['socket_index'] = $slotIndex;
    }

    /** @return array{0: bool, 1: string|null} */
    public static function canRemove(array $item, int $slotIndex): array
    {
        $slots = (int) ($item['card_slots'] ?? 0);
        if ($slotIndex < 0 || $slotIndex >= $slots) {
            return [false, 'That socket does not exist.'];
        }
        if (empty($item['cards'][$slotIndex])) {
            return [false, 'That socket is empty.'];
        }

        return [true, null];
    }

    public static function remove(array &$item, int $slotIndex): array
    {
        $card = $item['cards'][$slotIndex];
        $item['cards'][$slotIndex] = null;
        $card['status'] = 'owned';
        unset($card['socket_item_id'], $card['socket_index']);

        return $card;
    }

    /**
     * Fill empty sockets on equipped gear with the best free cards (deterministic).
     *
     * @return list<array{item_id: int, slot_index: int, card_id: int}>
     */
    public static function botSocket(array &$state, array $balance): array
    {
        $log = [];
        $limit = (int) $balance['cards']['bot_socket_per_run'];
        $cards = [];
        foreach ($state['cards'] ?? [] as $i => $card) {
            if (($card['status'] ?? 'owned') === 'owned' && isset($card['id'])) {
                $cards[$i] = $card;
            }
        }
        for ($n = 0; $n < $limit; $n++) {
            $best = null;
            $bestScore = null;
            foreach ($state['equipment'] ?? [] as $equipSlot => $item) {
                if ($item === null || empty($item['id']) || (int) ($item['card_slots'] ?? 0) < 1) {
                    continue;
                }
                $item['cards'] ??= array_fill(0, (int) $item['card_slots'], null);
                for ($slotIndex = 0; $slotIndex < (int) $item['card_slots']; $slotIndex++) {
                    if (! empty($item['cards'][$slotIndex])) {
                        continue;
                    }
                    foreach ($cards as $ci => $card) {
                        $score = self::score($card, $balance);
                        $tie = [$score, - (int) $card['id'], (string) $equipSlot, $slotIndex];
                        if ($best === null || $tie > $bestScore) {
                            $bestScore = $tie;
                            $best = ['equip' => $equipSlot, 'slot_index' => $slotIndex, 'card_i' => $ci, 'item' => $item];
                        }
                    }
                }
            }
            if ($best === null) {
                break;
            }
            $card = $cards[$best['card_i']];
            $item = $state['equipment'][$best['equip']];
            self::insert($item, $card, $best['slot_index']);
            $state['equipment'][$best['equip']] = $item;
            if (isset($state['items'])) {
                foreach ($state['items'] as $ii => $owned) {
                    if (($owned['id'] ?? null) === ($item['id'] ?? null)) {
                        $state['items'][$ii] = $item;
                    }
                }
            }
            foreach ($state['cards'] as $ci => $row) {
                if (($row['id'] ?? null) === ($card['id'] ?? null)) {
                    $state['cards'][$ci] = $card;
                }
            }
            unset($cards[$best['card_i']]);
            $log[] = [
                'item_id' => (int) $item['id'],
                'slot_index' => (int) $best['slot_index'],
                'card_id' => (int) $card['id'],
            ];
        }

        return $log;
    }
}
