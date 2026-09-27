<?php

namespace App\Game;

/** Resolve one character's trip (DESIGN.md §4). Pure: context + Rng in, result out. */
final class Trip
{
    private const BUFF_KINDS = [
        'buff_attack_pct' => 'might',
        'buff_defense_pct' => 'stoneskin',
        'luck_bonus' => 'luck',
    ];

    public static function resolve(array $ctx, Rng $rng): array
    {
        $balance = $ctx['balance'];
        $char = $ctx['character'];
        $plan = $ctx['plan'];
        if ($plan === 'blocked') {
            return self::emptyResult($ctx, 'blocked_injured', $ctx['town'], 0);
        }
        $restGold = (int) $balance['trip']['rest_gold_per_level'] * (int) $char['level'];
        if ($plan === 'rejected') {
            return self::emptyResult($ctx, 'order_rejected', $ctx['place'], $restGold, $ctx['reject_reason'] ?? null);
        }
        if ($plan === 'rest' || ($ctx['place']['place_type'] ?? null) === 'rest') {
            return self::emptyResult($ctx, 'rested', $ctx['town'], $restGold);
        }

        $uses = [];
        $belt = self::beltCopy($char['belt'] ?? []);
        $buffs = self::tripBuffs($belt, $uses);
        $injury = (int) $char['injury_severity'];
        $level = (int) $char['level'];
        $stats = Stats::derived($char['stats'], $level, $char['equipment'] ?? [], $balance, $injury, $buffs);
        $maxHp = $stats['hp'];
        $actor = [
            'name' => $char['name'],
            'hp' => $maxHp,
            'max_hp' => $maxHp,
            'attack' => $stats['attack'],
            'defense' => $stats['defense'],
            'mres' => $stats['mres'],
            'speed' => $stats['speed'],
            'crit' => $stats['crit'],
            'crit_mult' => $stats['crit_mult'],
            'dodge' => $stats['dodge'],
            'damage_type' => $stats['damage_type'],
        ];
        $place = $ctx['place'];
        $planned = $rng->randint((int) $place['encounter_min'], (int) $place['encounter_max']);
        $retreat = (int) $char['retreat_threshold_pct'];
        $potionPct = (int) $char['potion_threshold_pct'];
        $affixes = $ctx['affixes'] ?? [];
        $encounters = [];
        $items = [];
        $pouchXp = 0;
        $pouchGold = 0;
        $kills = 0;
        $bossKills = 0;
        $bossMet = false;
        $outcome = 'cleared';
        $won = 0;

        for ($seq = 1; $seq <= $planned; $seq++) {
            [$group, $isBoss] = self::buildGroup($place, $seq, $planned, $rng, $balance);
            $bossMet = $bossMet || $isBoss;
            $fight = Combat::runEncounter($actor, $group, $belt, $retreat, $potionPct, $rng, $balance);
            array_push($uses, ...$fight['uses']);
            $xp = 0;
            $gold = 0;
            $drops = [];
            if ($fight['result'] === 'won') {
                $won++;
                foreach ($group as $monster) {
                    $kills++;
                    if ($monster['is_boss']) {
                        $bossKills++;
                    }
                    $xp += Progression::monsterXp($monster['xp'], $monster['level'], $level, $balance);
                    $gold += Loot::goldDrop($monster, $place, $stats['gold_find'], $rng);
                    $drop = Loot::tryDrop($monster, $monster['loot_table'], $stats['luk'], $stats['luck'], $place, $affixes, $rng, $balance, boss: $monster['is_boss']);
                    if ($drop) {
                        $drop['encounter_seq'] = $seq;
                        $drops[] = $drop;
                        $items[] = $drop;
                        $fight['log'][] = [
                            'r' => $fight['rounds'],
                            'ev' => 'loot',
                            't' => $monster['name'],
                            'name' => $drop['display_name'],
                            'rarity' => $drop['rarity'],
                        ];
                    }
                }
                $pouchXp += $xp;
                $pouchGold += $gold;
                $actor['hp'] = min($maxHp, $actor['hp'] + Numbers::halfUp($maxHp * $stats['regen'] / 100));
                $potion = Combat::choosePotion($actor['hp'], $maxHp, $potionPct, $belt);
                if ($potion !== null) {
                    $event = Combat::drink($actor, $belt[$potion], $uses);
                    $fight['log'][] = ['r' => $fight['rounds']] + array_merge($event, ['a' => $actor['name'], 'ev' => 'potion_topup']);
                }
            }
            $encounters[] = [
                'seq' => $seq,
                'is_boss' => $isBoss,
                'monsters' => array_map(fn ($m) => ['monster_id' => $m['monster_id'], 'code' => $m['code'], 'name' => $m['base_name'], 'hp' => $m['max_hp'], 'level' => $m['level'], 'is_boss' => $m['is_boss']], $group),
                'names' => array_column($group, 'name'),
                'result' => $fight['result'],
                'rounds' => $fight['rounds'],
                'damage_dealt' => $fight['damage_dealt'],
                'damage_taken' => $fight['damage_taken'],
                'hp_before' => $fight['hp_before'],
                'hp_after' => $fight['hp_after'],
                'xp' => $xp,
                'gold' => $gold,
                'log' => $fight['log'],
                'drops' => $drops,
            ];
            if ($fight['result'] === 'lost') {
                $outcome = 'defeated';
                break;
            }
            if (in_array($fight['result'], ['fled', 'exhausted'], true)) {
                $outcome = 'retreated';
                break;
            }
            if ($seq < $planned && $actor['hp'] < $retreat / 100 * $maxHp) {
                $outcome = 'retreated';
                $encounters[] = [
                    'seq' => $seq + 1, 'is_boss' => false, 'monsters' => [], 'names' => [], 'result' => 'left_early',
                    'rounds' => 0, 'damage_dealt' => 0, 'damage_taken' => 0,
                    'hp_before' => $actor['hp'], 'hp_after' => $actor['hp'], 'xp' => 0, 'gold' => 0,
                    'log' => [['ev' => 'left_early']], 'drops' => [],
                ];
                break;
            }
        }

        $clearBonus = 0;
        $lootBox = null;
        if ($outcome === 'cleared') {
            $clearBonus = Numbers::halfUp($pouchXp * $balance['trip']['clear_xp_bonus']);
            if (! empty($place['chest']['entries'])) {
                $lootBox = [
                    'seed' => $rng->randint(1, 2147483647),
                    'luck_factor' => round((float) $stats['luck'] + (float) ($place['rarity_bonus'] ?? 0) + (float) $balance['trip']['chest_luck_bonus'], 3),
                    'source_level' => (int) $place['rec_level'],
                    'rolls' => Loot::chestRollCount((int) $place['rec_level'], $balance),
                ];
            }
        }

        $xpGained = $pouchXp + $clearBonus;
        $goldGained = $pouchGold;
        $keptItems = $items;
        $lostItems = [];
        if ($outcome === 'defeated') {
            $xpGained = Numbers::halfUp($pouchXp * $balance['trip']['defeat_keep_mult']);
            $goldGained = Numbers::halfUp($pouchGold * $balance['trip']['defeat_keep_mult']);
            $clearBonus = 0;
            $lostItems = $items;
            $keptItems = [];
        }

        $progressed = Progression::applyXp($level, (int) $char['xp'], $xpGained, $balance);
        [$severity, $runsLeft] = Progression::nextInjury($outcome, $injury, (int) $char['injury_runs_left']);
        $autosell = ['kept' => $keptItems, 'sold_owned' => [], 'sold_new' => []];
        if ($keptItems) {
            $autosell = Loot::planCapacity($keptItems, $ctx['commons'] ?? [], (int) $balance['loot']['inventory_capacity'], (int) ($ctx['inventory_count'] ?? 0));
        }

        $best = false;
        if ($outcome === 'cleared') {
            $current = $char['best_place_rec'] ?? null;
            $best = $current === null || (int) $place['rec_level'] > (int) $current;
        }

        $snapshot = [
            'stats' => $char['stats'],
            'derived' => array_intersect_key($stats, array_flip(['hp', 'attack', 'defense', 'mres', 'crit', 'crit_mult', 'dodge', 'speed', 'regen', 'luck', 'gold_find', 'damage_type'])),
            'buffs' => $buffs,
            'equipment' => array_map(fn ($item) => [
                'id' => $item['id'] ?? null,
                'name' => $item['display_name'] ?? $item['name'] ?? null,
                'slot' => $item['equip_slot'] ?? $item['slot'] ?? null,
                'rarity' => $item['rarity'] ?? null,
            ], array_values(array_filter($char['equipment'] ?? []))),
            'belt' => array_map(fn ($item) => ['code' => $item['code'], 'qty' => $item['belt_qty']], $belt),
            'injury' => $injury,
        ];

        return [
            'outcome' => $outcome,
            'place' => $place,
            'order_id' => $ctx['order_id'] ?? null,
            'cancel_order' => false,
            'reject_reason' => null,
            'open_places' => [],
            'encounters' => $encounters,
            'encounters_planned' => $planned,
            'encounters_won' => $won,
            'boss_encountered' => $bossMet,
            'boss_killed' => $bossKills > 0,
            'max_hp' => $maxHp,
            'hp_end' => max(0, $actor['hp']),
            'xp_gained' => $xpGained,
            'gold_gained' => $goldGained,
            'level_gold' => $progressed['gold'],
            'level_before' => $level,
            'level_after' => $progressed['level'],
            'xp_after' => $progressed['xp'],
            'points_gained' => $progressed['points'],
            'levels' => $progressed['levels'],
            'items' => $autosell['kept'],
            'lost_items' => $lostItems,
            'autosell_owned' => $autosell['sold_owned'],
            'autosell_new' => $autosell['sold_new'],
            'uses' => self::mergeUses($uses),
            'kills' => $kills,
            'boss_kills' => $bossKills,
            'injury' => ['severity' => $severity, 'runs_left' => $runsLeft],
            'clear_bonus' => $clearBonus,
            'loot_box' => $lootBox,
            'snapshot' => $snapshot,
            'buffs' => $buffs,
            'belt_after' => $belt,
            'stats' => $stats,
            'best_place' => $best,
            'rng_seed' => ($ctx['run_seed'] ?? '').':'.$char['id'],
        ];
    }

    private static function emptyResult(array $ctx, string $outcome, array $place, int $gold, ?string $reason = null): array
    {
        $char = $ctx['character'];
        [$severity, $runsLeft] = Progression::nextInjury($outcome, (int) $char['injury_severity'], (int) $char['injury_runs_left']);

        return [
            'outcome' => $outcome,
            'place' => $place,
            'order_id' => $ctx['order_id'] ?? null,
            'cancel_order' => $outcome === 'order_rejected',
            'reject_reason' => $reason,
            'open_places' => $ctx['open_places'] ?? [],
            'encounters' => [],
            'encounters_planned' => 0,
            'encounters_won' => 0,
            'boss_encountered' => false,
            'boss_killed' => false,
            'max_hp' => 1,
            'hp_end' => 1,
            'xp_gained' => 0,
            'gold_gained' => $gold,
            'level_gold' => 0,
            'level_before' => (int) $char['level'],
            'level_after' => (int) $char['level'],
            'xp_after' => (int) $char['xp'],
            'points_gained' => 0,
            'levels' => [],
            'items' => [],
            'lost_items' => [],
            'autosell_owned' => [],
            'autosell_new' => [],
            'uses' => [],
            'kills' => 0,
            'boss_kills' => 0,
            'injury' => ['severity' => $severity, 'runs_left' => $runsLeft],
            'clear_bonus' => 0,
            'loot_box' => null,
            'snapshot' => ['plan' => $outcome, 'reason' => $reason],
            'buffs' => [],
            'belt_after' => [],
            'stats' => [],
            'best_place' => false,
            'rng_seed' => ($ctx['run_seed'] ?? '').':'.$char['id'],
        ];
    }

    /** Belt rows the trip can use automatically (manual items such as bandages stay home). */
    private static function beltCopy(array $belt): array
    {
        $copy = [];
        foreach ($belt as $item) {
            if ((int) ($item['belt_qty'] ?? 0) > 0 && ($item['use_trigger'] ?? null) !== 'manual') {
                $copy[] = $item;
            }
        }

        return $copy;
    }

    private static function tripBuffs(array &$belt, array &$uses): array
    {
        $buffs = [];
        foreach ($belt as &$item) {
            if (($item['use_trigger'] ?? null) !== 'trip_start') {
                continue;
            }
            $kind = self::BUFF_KINDS[$item['effect_type'] ?? ''] ?? null;
            if (! $kind || in_array($kind, $buffs, true) || (int) ($item['belt_qty'] ?? 0) <= 0) {
                continue;
            }
            $item['belt_qty'] = (int) $item['belt_qty'] - 1;
            $uses[] = ['code' => $item['code'], 'qty' => 1, 'id' => $item['id'] ?? null];
            $buffs[] = $kind;
        }
        unset($item);

        return $buffs;
    }

    private static function monsterActors(array $monster, int $count, array $balance): array
    {
        $actors = [];
        for ($i = 1; $i <= $count; $i++) {
            $actors[] = [
                'name' => $count > 1 ? "{$monster['name']} #{$i}" : $monster['name'],
                'base_name' => $monster['name'],
                'monster_id' => $monster['id'] ?? null,
                'code' => $monster['code'] ?? null,
                'hp' => (int) $monster['hp'],
                'max_hp' => (int) $monster['hp'],
                'attack' => (int) $monster['attack'],
                'defense' => (int) $monster['defense'],
                'mres' => (int) $monster['mres'],
                'speed' => (int) $monster['speed'],
                'crit' => (float) $monster['crit_pct'],
                'crit_mult' => (float) $balance['derived']['crit_mult_base'],
                'dodge' => (float) $monster['dodge_pct'],
                'damage_type' => $monster['damage_type'],
                'tie' => $i,
                'level' => (int) $monster['level'],
                'xp' => (int) $monster['xp'],
                'gold_min' => (int) $monster['gold_min'],
                'gold_max' => (int) $monster['gold_max'],
                'drop_chance' => (float) $monster['drop_chance'],
                'loot_table' => $monster['loot_table'] ?? null,
                'is_boss' => (bool) ($monster['is_boss'] ?? false),
            ];
        }

        return $actors;
    }

    /** Group size k is chosen with probability proportional to 1/k. */
    private static function pickGroupSize(int $min, int $max, Rng $rng): int
    {
        $options = range($min, $max);

        return Loot::weightedPick($options, $rng, fn ($k) => 1 / $k);
    }

    /** @return array{0: list<array>, 1: bool} [monsters, is_boss] */
    private static function buildGroup(array $place, int $seq, int $planned, Rng $rng, array $balance): array
    {
        $boss = $place['boss'] ?? null;
        if ($seq === $planned && $boss && $rng->random() < (float) ($place['boss_chance'] ?? 0)) {
            return [self::monsterActors($boss, 1, $balance), true];
        }
        $row = Loot::weightedPick($place['spawns'], $rng, fn ($entry) => $entry['weight']) ?? end($place['spawns']);
        $size = self::pickGroupSize((int) $row['min_group'], (int) $row['max_group'], $rng);

        return [self::monsterActors($row['monster'], $size, $balance), false];
    }

    private static function mergeUses(array $uses): array
    {
        $merged = [];
        foreach ($uses as $use) {
            foreach ($merged as &$row) {
                if ($row['code'] === $use['code']) {
                    $row['qty'] += $use['qty'];
                    continue 2;
                }
            }
            unset($row);
            $merged[] = ['code' => $use['code'], 'qty' => $use['qty'], 'id' => $use['id'] ?? null];
        }
        unset($row);

        return $merged;
    }
}
