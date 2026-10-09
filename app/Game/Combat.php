<?php

namespace App\Game;

/** Encounter resolution: initiative, attacks, potions, and fleeing (DESIGN.md §4.3, §4.4). */
final class Combat
{
    /** The belt row to drink now, or null. Picks the weakest potion that lifts HP to the threshold. */
    public static function choosePotion(int $hp, int $maxHp, int $thresholdPct, array $belt): int|string|null
    {
        $need = $thresholdPct / 100 * $maxHp;
        if ($hp >= $need) {
            return null;
        }
        $heals = [];
        foreach ($belt as $key => $item) {
            if (($item['effect_type'] ?? null) === 'heal_pct' && (int) ($item['belt_qty'] ?? 0) > 0) {
                $heals[$key] = $item;
            }
        }
        if ($heals === []) {
            return null;
        }
        uasort($heals, fn ($a, $b) => [(float) $a['effect_value'], $a['code'] ?? ''] <=> [(float) $b['effect_value'], $b['code'] ?? '']);
        foreach ($heals as $key => $item) {
            if ($hp + Numbers::halfUp($maxHp * (float) $item['effect_value'] / 100) >= $need) {
                return $key;
            }
        }

        return array_key_last($heals);
    }

    public static function fleeChance(int $speed, array $monsterSpeeds, array $balance, bool $smoke = false): float
    {
        $fastest = $monsterSpeeds === [] ? 0 : max($monsterSpeeds);
        $c = $balance['combat'];
        $chance = (float) Numbers::clamp($c['flee_base'] + $c['flee_per_speed'] * ($speed - $fastest), $c['flee_min'], $c['flee_max']);
        if ($smoke) {
            $chance = min((float) $c['smoke_flee_cap'], $chance + $c['smoke_flee_bonus']);
        }

        return $chance;
    }

    public static function resolveAttack(array &$attacker, array &$target, Rng $rng, array $balance): array
    {
        if ($rng->random() * 100 < $target['dodge']) {
            return ['ev' => 'dodge'];
        }
        $defense = $attacker['damage_type'] === 'physical' ? $target['defense'] : $target['mres'];
        $penetration = (float) ($attacker['penetration'] ?? 0);
        if ($penetration > 0) {
            $defense *= 1 - $penetration / 100;
        }
        $attack = $attacker['attack'];
        $denom = $attack + $defense;
        $base = $denom <= 0 ? $attack : ($attack * $attack) / $denom;
        $c = $balance['combat'];
        $damage = $base * $rng->uniform((float) $c['variance_min'], (float) $c['variance_max']);
        $crit = false;
        if ($rng->random() * 100 < $attacker['crit']) {
            $damage *= $attacker['crit_mult'];
            $crit = true;
        }
        $reduction = (float) ($target['dmg_reduction'] ?? 0);
        if ($reduction > 0) {
            $damage *= 1 - $reduction / 100;
        }
        $damage = max((int) $c['min_damage'], Numbers::halfUp($damage));
        $target['hp'] -= $damage;
        $event = ['ev' => 'hit', 'dmg' => $damage, 'crit' => $crit, 'thp' => max(0, $target['hp'])];
        $heal = self::lifesteal($attacker, $damage);
        if ($heal > 0) {
            $event['heal'] = $heal;
            $event['ahp'] = $attacker['hp'];
        }

        return $event;
    }

    /** Heal the attacker by its lifesteal share of the damage dealt, up to max HP. */
    public static function lifesteal(array &$attacker, int $damage): int
    {
        $pct = (float) ($attacker['lifesteal'] ?? 0);
        if ($pct <= 0 || ! isset($attacker['max_hp']) || $attacker['hp'] <= 0) {
            return 0;
        }
        $heal = min(Numbers::halfUp($damage * $pct / 100), (int) $attacker['max_hp'] - (int) $attacker['hp']);
        if ($heal <= 0) {
            return 0;
        }
        $attacker['hp'] += $heal;

        return $heal;
    }

    /**
     * @param  array  $actor  the character (hp, max_hp, attack, …)
     * @param  list<array>  $monsters  living and dead monsters of this encounter
     * @param  list<array>  $belt  belt rows (belt_qty is decremented as items are used)
     */
    public static function runEncounter(array &$actor, array &$monsters, array &$belt, int $retreatPct, int $potionPct, Rng $rng, array $balance): array
    {
        $log = [];
        $dealt = 0;
        $taken = 0;
        $rounds = 0;
        $hpBefore = $actor['hp'];
        $uses = [];
        $finish = function (string $result) use (&$log, &$dealt, &$taken, &$rounds, &$uses, &$actor, $hpBefore): array {
            return [
                'result' => $result,
                'rounds' => $rounds,
                'damage_dealt' => $dealt,
                'damage_taken' => $taken,
                'hp_before' => $hpBefore,
                'hp_after' => max(0, $actor['hp']),
                'log' => $log,
                'uses' => $uses,
            ];
        };
        $allDead = function () use (&$monsters): bool {
            foreach ($monsters as $m) {
                if ($m['hp'] > 0) {
                    return false;
                }
            }

            return true;
        };

        for ($rnd = 1; $rnd <= (int) $balance['combat']['max_rounds']; $rnd++) {
            $rounds = $rnd;
            foreach (self::initiative($actor, $monsters, $rng, $balance) as $unit) {
                if ($actor['hp'] <= 0 || $allDead()) {
                    break;
                }
                if ($unit === 'character') {
                    $potion = self::choosePotion($actor['hp'], $actor['max_hp'], $potionPct, $belt);
                    if ($potion !== null) {
                        $event = self::drink($actor, $belt[$potion], $uses);
                        $log[] = ['r' => $rnd, 'a' => $actor['name']] + $event;

                        continue;
                    }
                    $targetKey = null;
                    foreach ($monsters as $key => $m) {
                        if ($m['hp'] > 0 && ($targetKey === null || [$m['hp'], $m['tie']] < [$monsters[$targetKey]['hp'], $monsters[$targetKey]['tie']])) {
                            $targetKey = $key;
                        }
                    }
                    $event = self::resolveAttack($actor, $monsters[$targetKey], $rng, $balance);
                    if ($event['ev'] === 'hit') {
                        $dealt += $event['dmg'];
                    }
                    $log[] = $event + ['r' => $rnd, 'a' => $actor['name'], 't' => $monsters[$targetKey]['name']];
                } else {
                    if ($monsters[$unit]['hp'] <= 0) {
                        continue;
                    }
                    $event = self::resolveAttack($monsters[$unit], $actor, $rng, $balance);
                    if ($event['ev'] === 'hit') {
                        $taken += $event['dmg'];
                    }
                    $log[] = $event + ['r' => $rnd, 'a' => $monsters[$unit]['name'], 't' => $actor['name']];
                }
            }
            if ($allDead()) {
                return $finish('won');
            }
            if ($actor['hp'] <= 0) {
                return $finish('lost');
            }
            if ($actor['hp'] < $retreatPct / 100 * $actor['max_hp']) {
                $smoke = null;
                foreach ($belt as $key => $item) {
                    if (($item['effect_type'] ?? null) === 'flee_bonus' && (int) ($item['belt_qty'] ?? 0) > 0) {
                        $smoke = $key;
                        break;
                    }
                }
                $speeds = [];
                foreach ($monsters as $m) {
                    if ($m['hp'] > 0) {
                        $speeds[] = $m['speed'];
                    }
                }
                $chance = self::fleeChance($actor['speed'], $speeds, $balance, $smoke !== null);
                if ($smoke !== null) {
                    $belt[$smoke]['belt_qty'] = (int) $belt[$smoke]['belt_qty'] - 1;
                    $uses[] = ['code' => $belt[$smoke]['code'], 'qty' => 1, 'id' => $belt[$smoke]['id'] ?? null];
                }
                $shown = round($chance, 2);
                if ($rng->random() < $chance) {
                    $log[] = ['r' => $rnd, 'ev' => 'flee', 'p' => $shown];

                    return $finish('fled');
                }
                $log[] = ['r' => $rnd, 'ev' => 'flee_fail', 'p' => $shown];
                foreach ($monsters as $key => $m) {
                    if ($m['hp'] <= 0) {
                        continue;
                    }
                    $event = self::resolveAttack($m, $actor, $rng, $balance);
                    if ($event['ev'] === 'hit') {
                        $event['ev'] = 'free_hit';
                        $taken += $event['dmg'];
                    }
                    $log[] = $event + ['r' => $rnd, 'a' => $m['name'], 't' => $actor['name']];
                    if ($actor['hp'] <= 0) {
                        return $finish('lost');
                    }
                }
            }
        }

        return $finish('exhausted');
    }

    /** Drink a healing potion from the belt. */
    public static function drink(array &$actor, array &$potion, array &$uses): array
    {
        $heal = Numbers::halfUp($actor['max_hp'] * (float) $potion['effect_value'] / 100);
        $actor['hp'] = min($actor['max_hp'], $actor['hp'] + $heal);
        $potion['belt_qty'] = (int) $potion['belt_qty'] - 1;
        $uses[] = ['code' => $potion['code'], 'qty' => 1, 'id' => $potion['id'] ?? null];

        return ['ev' => 'potion', 'code' => $potion['code'], 'heal' => $heal, 'thp' => $actor['hp']];
    }

    /** @return list<'character'|int> turn order for living units: 'character' or a monster key */
    private static function initiative(array $actor, array $monsters, Rng $rng, array $balance): array
    {
        $die = (int) $balance['combat']['initiative_die'];
        $rolled = [];
        if ($actor['hp'] > 0) {
            $rolled[] = [$actor['speed'] + $rng->randint(0, $die), -1, 'character'];
        }
        $index = 1;
        foreach ($monsters as $key => $m) {
            if ($m['hp'] > 0) {
                $rolled[] = [$m['speed'] + $rng->randint(0, $die), $index, $key];
            }
            $index++;
        }
        usort($rolled, fn ($a, $b) => [-$a[0], $a[1]] <=> [-$b[0], $b[1]]);

        return array_column($rolled, 2);
    }
}
