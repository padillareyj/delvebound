<?php

namespace App\Game\Bots;

use App\Game\Growth;
use App\Game\Premium;
use App\Game\Rng;

/** One bot's full decision for a run (DESIGN.md §8.2). Pure: state + world in, decision out. */
final class BotPlanner
{
    /** @param array<string, array> $premium the premium catalog keyed by code */
    public static function plan(array $state, array $places, array $shop, Rng $rng, array $balance, array $premium = []): array
    {
        $state['ledger'] ??= [];
        $state['new_items'] ??= [];
        $memory = Memory::read($state['reports'] ?? [], $state['profile'], (int) $state['level'], $balance);
        $points = Allocation::allocate($state, $rng, $balance);
        Gear::equipBest($state, $balance);
        $trained = Growth::botTrain($state, $balance);
        $fed = Growth::botFeed($state, $balance);
        $premiumBefore = $state['premium'] ?? null;
        $premiumBought = ($state['is_bot'] ?? false) && $premium !== []
            ? Premium::botShop($state, $premium, $balance)
            : [];
        $premiumChanged = isset($state['premium']) && (
            $premiumBought !== [] || ($premiumBefore['equipped'] ?? []) !== $state['premium']['equipped']
        );
        $sold = Gear::sellJunk($state, $balance);
        $place = Destination::choose($state, $places, $memory, $rng, $balance);
        $bought = Shopping::shopStep($state, $place, $shop, $balance);
        $belt = Shopping::fillBelt($state, $balance);
        $retreat = (int) $state['profile']['retreat_threshold_pct'];

        return [
            'points' => $points,
            'stats' => $state['stats'],
            'unspent' => (int) $state['unspent'],
            'gold' => (int) $state['gold'],
            'injury_severity' => (int) $state['injury_severity'],
            'injury_runs_left' => (int) $state['injury_runs_left'],
            'equipped' => array_map(fn ($item) => $item['id'] ?? null, $state['equipment']),
            'new_items' => $state['new_items'],
            'sold_items' => array_map(fn ($item) => $item['id'] ?? null, $sold['items']),
            'sold_consumables' => $sold['consumables'],
            'bought' => $bought['bought'],
            'consumables' => array_map(fn ($row) => [
                'id' => $row['id'],
                'quantity' => (int) $row['quantity'],
                'belt_qty' => (int) ($row['belt_qty'] ?? 0),
            ], array_values($state['consumables'])),
            'belt' => $belt,
            'ledger' => $state['ledger'],
            'place' => $place['code'] ?? null,
            'place_id' => $place['id'] ?? null,
            'retreat_threshold_pct' => $retreat,
            'potion_threshold_pct' => $retreat + (int) $balance['bots']['potion_threshold_offset'],
            'skills' => $state['skills'] ?? [],
            'materials' => array_values($state['materials'] ?? []),
            'horcrux' => $state['horcrux'] ?? ['tier' => 0, 'progress' => 0],
            'fed' => $fed,
            'premium' => $state['premium'] ?? null,
            'premium_bought' => $premiumBought,
            'premium_changed' => $premiumChanged,
            'why' => [
                'memory_blocked' => $memory['blocked'],
                'step_up' => $memory['step_up'],
                'last_outcome' => $memory['last_outcome'],
                'place' => $place['code'] ?? null,
                'place_mode' => ($state['auto_place'] ?? 'profile') === 'best' ? 'best' : 'profile',
                'trained' => $trained,
                'fed' => count($fed),
            ],
        ];
    }
}
