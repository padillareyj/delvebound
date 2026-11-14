<?php

namespace App\Game\Bots;

use App\Game\Cards;
use App\Game\Daily;
use App\Game\Growth;
use App\Game\Premium;
use App\Game\Rng;
use App\Game\Systems;

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
        $cardSockets = ($state['is_bot'] ?? false) ? Cards::botSocket($state, $balance) : [];
        $trained = Growth::botTrain($state, $balance);
        $fed = Growth::botFeed($state, $balance);
        $premiumBefore = $state['premium'] ?? null;
        $premiumBought = ($state['is_bot'] ?? false) && $premium !== []
            ? Premium::botShop($state, $premium, $balance)
            : [];
        $premiumChanged = isset($state['premium']) && (
            $premiumBought !== []
            || ($premiumBefore['equipped'] ?? []) !== $state['premium']['equipped']
            || ($premiumBefore['active'] ?? []) !== $state['premium']['active']
        );

        $bag = [];
        foreach ($state['materials'] ?? [] as $mat) {
            if ((int) ($mat['qty'] ?? 0) > 0) {
                $bag[$mat['code']] = (int) $mat['qty'];
            }
        }
        $day = Daily::dayKey(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $balance['daily']['timezone']);
        $daily = Daily::state($state['daily'] ?? null, $day, $balance);
        Daily::markLogin($daily);
        $dailyClaimed = ($state['is_bot'] ?? false) ? Daily::claimAll($daily, $bag, $balance) : [];
        $systems = Systems::state($state['systems'] ?? null, $balance);
        $systemsUpgraded = ($state['is_bot'] ?? false) ? Systems::botUpgrade($systems, $bag, $balance) : [];
        foreach ($state['materials'] ?? [] as $i => $mat) {
            $state['materials'][$i]['qty'] = (int) ($bag[$mat['code']] ?? 0);
        }
        foreach ($bag as $code => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $found = false;
            foreach ($state['materials'] ?? [] as $mat) {
                if ($mat['code'] === $code) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $state['materials'][] = ['code' => $code, 'kind' => 'system', 'qty' => $qty, 'name' => $code, 'glyph' => '', 'description' => ''];
            }
        }
        $state['daily'] = $daily;
        $state['systems'] = $systems;

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
            'daily' => $daily,
            'systems' => $systems,
            'daily_claimed' => $dailyClaimed,
            'systems_upgraded' => $systemsUpgraded,
            'card_sockets' => $cardSockets,
            'why' => [
                'memory_blocked' => $memory['blocked'],
                'step_up' => $memory['step_up'],
                'last_outcome' => $memory['last_outcome'],
                'place' => $place['code'] ?? null,
                'place_mode' => ($state['auto_place'] ?? 'profile') === 'best' ? 'best' : 'profile',
                'trained' => $trained,
                'fed' => count($fed),
                'daily_claimed' => count($dailyClaimed),
                'systems_upgraded' => count($systemsUpgraded),
                'card_sockets' => count($cardSockets),
            ],
        ];
    }
}
