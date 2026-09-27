<?php

namespace App\Game\Bots;

use App\Game\ShopMath;

/** Bot shopping and belt filling (DESIGN.md §8.6). Uses the same prices and limits as the Market page. */
final class Shopping
{
    public const BUFFS = [
        'might' => 'elixir_of_might',
        'stoneskin' => 'stoneskin_tonic',
        'luck' => 'lucky_charm',
    ];

    public static function budget(array $state): int
    {
        $gold = (int) $state['gold'];

        return $gold - (int) ceil($gold * (int) $state['profile']['gold_reserve_pct'] / 100);
    }

    private static function listing(array $shop, string $code): ?array
    {
        foreach ($shop as $row) {
            if (($row['code'] ?? null) === $code && ($row['is_active'] ?? true)) {
                return $row;
            }
        }

        return null;
    }

    private static function count(array $state, string $code): int
    {
        foreach ($state['consumables'] as $row) {
            if ($row['code'] === $code) {
                return (int) $row['quantity'];
            }
        }

        return 0;
    }

    private static function addConsumable(array &$state, array $listing, int $qty = 1): void
    {
        foreach ($state['consumables'] as &$row) {
            if ($row['code'] === $listing['code']) {
                $row['quantity'] = (int) $row['quantity'] + $qty;

                return;
            }
        }
        unset($row);
        $c = $listing['consumable'];
        $state['consumables'][] = [
            'id' => $c['id'],
            'code' => $c['code'],
            'name' => $c['name'],
            'effect_type' => $c['effect_type'],
            'effect_value' => $c['effect_value'],
            'use_trigger' => $c['use_trigger'],
            'req_level' => $c['req_level'],
            'quantity' => $qty,
            'belt_qty' => 0,
            'sell_price' => $c['sell_price'],
        ];
    }

    private static function pay(array &$state, array $listing): void
    {
        $price = (int) $listing['price'];
        $state['gold'] = (int) $state['gold'] - $price;
        $state['ledger'][] = [
            'action' => 'buy',
            'listing_id' => $listing['id'] ?? null,
            'consumable_id' => $listing['consumable']['id'] ?? null,
            'item_template_id' => $listing['template']['id'] ?? null,
            'gold_delta' => -$price,
            'gold_after' => (int) $state['gold'],
            'quantity' => 1,
        ];
    }

    public static function bestHealingPotion(array $shop, int $level): ?array
    {
        $best = null;
        foreach ($shop as $row) {
            if (($row['kind'] ?? null) === 'consumable' && $row['consumable']['effect_type'] === 'heal_pct'
                && (int) $row['min_level'] <= $level && ($row['is_active'] ?? true)
                && ($best === null || (float) $row['consumable']['effect_value'] >= (float) $best['consumable']['effect_value'])) {
                $best = $row;
            }
        }

        return $best;
    }

    /** @return array{bought: list<array>} */
    public static function shopStep(array &$state, array $place, array $shop, array $balance): array
    {
        $profile = $state['profile'];
        $bought = [];
        $level = (int) $state['level'];

        if ((int) $state['injury_severity'] === 1 && ! ($profile['rest_when_injured'] ?? true)) {
            $bandage = self::listing($shop, 'bandage_kit');
            if ($bandage && (int) $bandage['min_level'] <= $level && self::budget($state) >= (int) $bandage['price']) {
                self::pay($state, $bandage);
                $state['injury_severity'] = 0;
                $state['injury_runs_left'] = 0;
                $bought[] = ['code' => 'bandage_kit', 'use' => true];
                $state['ledger'][] = [
                    'action' => 'use',
                    'consumable_id' => $bandage['consumable']['id'],
                    'gold_delta' => 0,
                    'gold_after' => (int) $state['gold'],
                    'quantity' => 1,
                ];
            }
        }

        $best = null;
        $currentScore = Gear::loadoutScore($state, $state['equipment'], $balance);
        if ($currentScore > 0) {
            foreach ($shop as $listing) {
                if (($listing['kind'] ?? null) !== 'gear' || ! ($listing['is_active'] ?? true)) {
                    continue;
                }
                if ((int) $listing['min_level'] > $level || (int) $listing['price'] > self::budget($state)) {
                    continue;
                }
                $probe = $state;
                $item = ShopMath::shopGearInstance($listing['template'], $balance);
                $item['id'] = -1;
                $probe['items'][] = $item;
                Gear::equipBest($probe, $balance);
                $gain = Gear::loadoutScore($probe, $probe['equipment'], $balance) / $currentScore - 1;
                if ($gain * 100 >= (int) $profile['shop_min_gain_pct']) {
                    $value = $gain / (int) $listing['price'];
                    if ($best === null || $value > $best[0]) {
                        $best = [$value, $listing];
                    }
                }
            }
        }
        if ($best) {
            $listing = $best[1];
            self::pay($state, $listing);
            $item = ShopMath::shopGearInstance($listing['template'], $balance);
            $state['next_temp'] = (int) ($state['next_temp'] ?? -1);
            $item['id'] = $state['next_temp'];
            $state['next_temp']--;
            $item['listing_id'] = $listing['id'] ?? null;
            $state['ledger'][array_key_last($state['ledger'])]['item_temp_id'] = $item['id'];
            $state['items'][] = $item;
            $state['new_items'][] = $item;
            Gear::equipBest($state, $balance);
            $bought[] = ['code' => $item['code'], 'kind' => 'gear', 'id' => $item['id']];
        }

        $potion = self::bestHealingPotion($shop, $level);
        while ($potion && self::count($state, $potion['code']) < (int) $profile['potion_target'] && self::budget($state) >= (int) $potion['price']) {
            if (self::count($state, $potion['code']) >= (int) $balance['shop']['max_stack']) {
                break;
            }
            self::pay($state, $potion);
            self::addConsumable($state, $potion);
            $bought[] = ['code' => $potion['code'], 'kind' => 'consumable'];
        }

        $dangerous = (int) ($place['rec_level'] ?? 0) > $level || ! empty($place['boss']) || ! empty($place['boss_monster_id']);
        $pref = ($profile['buff_preference'] ?? null) ?: 'none';
        if ($pref !== 'none' && isset(self::BUFFS[$pref]) && $dangerous && ($place['place_type'] ?? null) !== 'rest') {
            $buff = self::listing($shop, self::BUFFS[$pref]);
            if ($buff && (int) $buff['min_level'] <= $level && self::budget($state) >= (int) $buff['price']
                && self::count($state, $buff['code']) < (int) $balance['shop']['max_stack']) {
                self::pay($state, $buff);
                self::addConsumable($state, $buff);
                $bought[] = ['code' => $buff['code'], 'kind' => 'consumable'];
            }
        }

        return ['bought' => $bought];
    }

    /** @return list<array{id: int, code: string, belt_qty: int, quantity: int}> */
    public static function fillBelt(array &$state, array $balance): array
    {
        $slots = (int) $balance['combat']['belt_slots'];
        $profile = $state['profile'];
        foreach ($state['consumables'] as &$row) {
            $row['belt_qty'] = 0;
        }
        unset($row);

        $heals = [];
        foreach ($state['consumables'] as $key => $row) {
            if ($row['effect_type'] === 'heal_pct' && (int) $row['quantity'] > 0) {
                $heals[$key] = (float) $row['effect_value'];
            }
        }
        arsort($heals);
        $need = (int) $profile['potion_target'];
        foreach (array_keys($heals) as $key) {
            if ($need <= 0 || $slots <= 0) {
                break;
            }
            $take = min($need, $slots, (int) $state['consumables'][$key]['quantity']);
            $state['consumables'][$key]['belt_qty'] = $take;
            $need -= $take;
            $slots -= $take;
        }

        $pref = ($profile['buff_preference'] ?? null) ?: 'none';
        if ($slots > 0 && isset(self::BUFFS[$pref])) {
            foreach ($state['consumables'] as &$row) {
                if ($row['code'] === self::BUFFS[$pref] && (int) $row['quantity'] > (int) $row['belt_qty']) {
                    $row['belt_qty']++;
                    $slots--;
                    break;
                }
            }
            unset($row);
        }
        if ($slots > 0) {
            foreach ($state['consumables'] as &$row) {
                if ($row['effect_type'] === 'flee_bonus' && (int) $row['quantity'] > (int) $row['belt_qty']) {
                    $row['belt_qty']++;
                    $slots--;
                    break;
                }
            }
            unset($row);
        }

        return array_map(fn ($row) => [
            'id' => $row['id'],
            'code' => $row['code'],
            'belt_qty' => (int) $row['belt_qty'],
            'quantity' => (int) $row['quantity'],
        ], array_values($state['consumables']));
    }
}
