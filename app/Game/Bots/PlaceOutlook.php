<?php

namespace App\Game\Bots;

use App\Game\Combat;
use App\Game\Numbers;
use App\Game\Premium;
use App\Game\Stats;

/**
 * Deterministic trip preview for auto mode's "best map" (DESIGN.md §8.7).
 * Uses the combat damage formula at its average roll, the character's current
 * stats, gear, skills, horcrux, injury, and the potions already owned.
 */
final class PlaceOutlook
{
    private const BUFF_KINDS = [
        'buff_attack_pct' => 'might',
        'buff_defense_pct' => 'stoneskin',
        'luck_bonus' => 'luck',
    ];

    /**
     * @return array{clears: bool, hp_end: int, max_hp: int}
     */
    public static function preview(array $state, array $place, array $balance): array
    {
        $belt = self::plannedBelt($state, $balance);
        $buffs = self::tripBuffs($belt);
        $bonus = Premium::combinedBonus($state, $balance);
        $stats = Stats::derived(
            $state['stats'],
            (int) $state['level'],
            array_values(array_filter($state['equipment'] ?? [])),
            $balance,
            (int) ($state['injury_severity'] ?? 0),
            $buffs,
            $bonus,
        );
        $maxHp = (int) $stats['hp'];
        $hp = $maxHp;
        $retreat = (int) ($state['profile']['retreat_threshold_pct'] ?? $state['retreat_threshold_pct'] ?? 0);
        $potionPct = $retreat + (int) $balance['bots']['potion_threshold_offset'];
        $encounters = max(1, (int) ($place['encounter_max'] ?? 1));
        $pack = self::worstPack($place, $stats, $balance);
        $boss = ($place['boss'] ?? null) && (float) ($place['boss_chance'] ?? 0) > 0
            ? self::actors($place['boss'], 1, $balance)
            : [];
        if ($pack === [] && $boss === []) {
            return ['clears' => false, 'hp_end' => 0, 'max_hp' => $maxHp];
        }

        for ($i = 1; $i <= $encounters; $i++) {
            $group = ($boss !== [] && $i === $encounters) ? self::copyActors($boss) : self::copyActors($pack);
            if ($group === []) {
                return ['clears' => false, 'hp_end' => max(0, $hp), 'max_hp' => $maxHp];
            }
            $outcome = self::fight($hp, $maxHp, $stats, $group, $belt, $retreat, $potionPct, $balance);
            $hp = $outcome['hp'];
            if ($outcome['result'] !== 'won') {
                return ['clears' => false, 'hp_end' => max(0, $hp), 'max_hp' => $maxHp];
            }
            $hp = min($maxHp, $hp + Numbers::halfUp($maxHp * (float) $stats['regen'] / 100));
        }

        return ['clears' => true, 'hp_end' => $hp, 'max_hp' => $maxHp];
    }

    /** @return list<array> */
    private static function plannedBelt(array $state, array $balance): array
    {
        $copy = $state;
        $copy['consumables'] = array_values($state['consumables'] ?? []);
        $copy['profile'] ??= ['potion_target' => 0, 'buff_preference' => 'none'];
        Shopping::fillBelt($copy, $balance);

        return array_values(array_filter($copy['consumables'], fn ($row) => (int) ($row['belt_qty'] ?? 0) > 0));
    }

    /** @param  list<array>  $belt */
    private static function tripBuffs(array &$belt): array
    {
        $buffs = [];
        foreach ($belt as &$item) {
            if (($item['use_trigger'] ?? null) !== 'trip_start' || (int) ($item['belt_qty'] ?? 0) <= 0) {
                continue;
            }
            $kind = self::BUFF_KINDS[$item['effect_type'] ?? ''] ?? null;
            if (! $kind || in_array($kind, $buffs, true)) {
                continue;
            }
            $item['belt_qty'] = (int) $item['belt_qty'] - 1;
            $buffs[] = $kind;
        }
        unset($item);

        return $buffs;
    }

    /** The spawn pack that leaves the character worst off, at full health and before potions. */
    private static function worstPack(array $place, array $stats, array $balance): array
    {
        $worst = [];
        $worstHp = PHP_INT_MAX;
        foreach ($place['spawns'] ?? [] as $spawn) {
            $monster = $spawn['monster'] ?? null;
            if (! $monster) {
                continue;
            }
            $count = max(1, (int) ($spawn['max_group'] ?? 1));
            $actors = self::actors($monster, $count, $balance);
            $hp = (int) $stats['hp'];
            $belt = [];
            $left = self::fight($hp, (int) $stats['hp'], $stats, $actors, $belt, 0, 0, $balance);
            if ($left['hp'] < $worstHp) {
                $worstHp = $left['hp'];
                $worst = self::actors($monster, $count, $balance);
            }
        }

        return $worst;
    }

    /** @return list<array> */
    private static function actors(array $monster, int $count, array $balance): array
    {
        $actors = [];
        for ($i = 1; $i <= $count; $i++) {
            $actors[] = [
                'hp' => (int) $monster['hp'],
                'attack' => (int) $monster['attack'],
                'defense' => (int) $monster['defense'],
                'mres' => (int) $monster['mres'],
                'crit' => (float) ($monster['crit_pct'] ?? 0),
                'crit_mult' => (float) $balance['derived']['crit_mult_base'],
                'dodge' => (float) ($monster['dodge_pct'] ?? 0),
                'damage_type' => $monster['damage_type'] ?? 'physical',
                'tie' => $i,
            ];
        }

        return $actors;
    }

    /** @param  list<array>  $actors */
    private static function copyActors(array $actors): array
    {
        return array_map(fn ($actor) => $actor, $actors);
    }

    /**
     * @param  list<array>  $monsters
     * @param  list<array>  $belt
     * @return array{result: string, hp: int}
     */
    private static function fight(int &$hp, int $maxHp, array $stats, array &$monsters, array &$belt, int $retreat, int $potionPct, array $balance): array
    {
        $actor = [
            'name' => 'you',
            'hp' => $hp,
            'max_hp' => $maxHp,
            'attack' => (int) $stats['attack'],
            'defense' => (int) $stats['defense'],
            'mres' => (int) $stats['mres'],
            'crit' => (float) $stats['crit'],
            'crit_mult' => (float) $stats['crit_mult'],
            'dodge' => (float) $stats['dodge'],
            'lifesteal' => (float) ($stats['lifesteal'] ?? 0),
            'penetration' => (float) ($stats['penetration'] ?? 0),
            'dmg_reduction' => (float) ($stats['dmg_reduction'] ?? 0),
            'damage_type' => $stats['damage_type'],
        ];
        $uses = [];
        $maxRounds = (int) $balance['combat']['max_rounds'];
        for ($round = 1; $round <= $maxRounds; $round++) {
            if (self::allDead($monsters)) {
                $hp = $actor['hp'];

                return ['result' => 'won', 'hp' => $hp];
            }
            $potion = Combat::choosePotion($actor['hp'], $maxHp, $potionPct, $belt);
            if ($potion !== null) {
                Combat::drink($actor, $belt[$potion], $uses);
            } else {
                $target = self::lowest($monsters);
                if ($target !== null) {
                    self::strike($actor, $monsters[$target], $balance);
                }
            }
            if (self::allDead($monsters)) {
                $hp = $actor['hp'];

                return ['result' => 'won', 'hp' => $hp];
            }
            foreach ($monsters as &$monster) {
                if ($monster['hp'] <= 0) {
                    continue;
                }
                self::strike($monster, $actor, $balance);
                if ($actor['hp'] <= 0) {
                    $hp = 0;

                    return ['result' => 'lost', 'hp' => 0];
                }
            }
            unset($monster);
            if ($retreat > 0 && $actor['hp'] < $retreat / 100 * $maxHp) {
                $hp = $actor['hp'];

                return ['result' => 'fled', 'hp' => $hp];
            }
        }
        $hp = $actor['hp'];

        return ['result' => 'exhausted', 'hp' => $hp];
    }

    /** @param  list<array>  $monsters */
    private static function allDead(array $monsters): bool
    {
        foreach ($monsters as $monster) {
            if ($monster['hp'] > 0) {
                return false;
            }
        }

        return $monsters !== [];
    }

    /** @param  list<array>  $monsters */
    private static function lowest(array $monsters): int|string|null
    {
        $key = null;
        foreach ($monsters as $i => $monster) {
            if ($monster['hp'] <= 0) {
                continue;
            }
            if ($key === null || [$monster['hp'], $monster['tie']] < [$monsters[$key]['hp'], $monsters[$key]['tie']]) {
                $key = $i;
            }
        }

        return $key;
    }

    /** Average-roll hit from the combat formula (DESIGN.md §4.3). */
    private static function strike(array &$attacker, array &$target, array $balance): void
    {
        $defense = ($attacker['damage_type'] ?? 'physical') === 'physical' ? $target['defense'] : $target['mres'];
        $defense *= 1 - (float) ($attacker['penetration'] ?? 0) / 100;
        $attack = (int) $attacker['attack'];
        $denom = $attack + $defense;
        $base = $denom <= 0 ? $attack : ($attack * $attack) / $denom;
        $c = $balance['combat'];
        $mid = ((float) $c['variance_min'] + (float) $c['variance_max']) / 2;
        $damage = $base * $mid;
        $crit = min(100, max(0, (float) ($attacker['crit'] ?? 0))) / 100;
        $damage *= 1 + $crit * ((float) ($attacker['crit_mult'] ?? 1) - 1);
        $dodge = min(100, max(0, (float) ($target['dodge'] ?? 0))) / 100;
        $damage *= 1 - $dodge;
        $damage *= 1 - (float) ($target['dmg_reduction'] ?? 0) / 100;
        $dealt = max((int) $c['min_damage'], Numbers::halfUp($damage));
        $target['hp'] -= $dealt;
        Combat::lifesteal($attacker, $dealt);
    }
}
