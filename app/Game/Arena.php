<?php

namespace App\Game;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Single-elimination arena (DESIGN.md §15). Pure: fighters + a seeded Rng in, bracket out.
 * Each duel starts at full HP and ends when one fighter reaches 0, or at the round cap.
 */
final class Arena
{
    private const BUFF_KINDS = [
        'buff_attack_pct' => 'might',
        'buff_defense_pct' => 'stoneskin',
        'luck_bonus' => 'luck',
    ];

    /**
     * Snapshot a loaded character into a fighter. Trip-start belt buffs are drunk once, here.
     *
     * @return array{id: int, name: string, level: int, is_bot: bool, hp: int, max_hp: int, attack: int, defense: int, mres: int, speed: int, crit: float, crit_mult: float, dodge: float, damage_type: string, potion_pct: int, belt: list<array>, uses: list<array>}
     */
    public static function fighter(array $char, array $balance): array
    {
        $uses = [];
        $belt = self::beltCopy($char['belt'] ?? []);
        $buffs = self::openingBuffs($belt, $uses);
        $stats = Stats::derived(
            $char['stats'],
            (int) $char['level'],
            array_values(array_filter($char['equipment'] ?? [])),
            $balance,
            (int) ($char['injury_severity'] ?? 0),
            $buffs,
        );

        return [
            'id' => (int) $char['id'],
            'name' => (string) $char['name'],
            'level' => (int) $char['level'],
            'is_bot' => (bool) ($char['is_bot'] ?? false),
            'hp' => $stats['hp'],
            'max_hp' => $stats['hp'],
            'attack' => $stats['attack'],
            'defense' => $stats['defense'],
            'mres' => $stats['mres'],
            'speed' => $stats['speed'],
            'crit' => $stats['crit'],
            'crit_mult' => $stats['crit_mult'],
            'dodge' => $stats['dodge'],
            'damage_type' => $stats['damage_type'],
            'potion_pct' => (int) ($char['potion_threshold_pct'] ?? $balance['character']['default_potion_pct']),
            'belt' => $belt,
            'uses' => $uses,
        ];
    }

    /** Fisher–Yates using the seeded generator. */
    public static function deal(array $ids, Rng $rng): array
    {
        $ids = array_values($ids);
        for ($i = count($ids) - 1; $i > 0; $i--) {
            $j = $rng->randint(0, $i);
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        }

        return $ids;
    }

    /**
     * @param  array<int, array>  $fighters  keyed by character id; belts are mutated as potions are drunk
     * @return array{champion_id: int, matches: list<array>, entrants: list<array>}
     */
    public static function bracket(array $fighters, int $seed, array $balance): array
    {
        if (count($fighters) < 2) {
            throw new \InvalidArgumentException('An arena needs at least two entrants.');
        }
        $ids = self::deal(array_keys($fighters), Rng::arena($seed));
        $matches = [];
        $round = 1;
        while (count($ids) > 1) {
            $next = [];
            $slot = 1;
            $count = count($ids);
            for ($i = 0; $i < $count; $i += 2) {
                $aId = (int) $ids[$i];
                if ($i + 1 >= $count) {
                    $matches[] = self::bye($round, $slot, $fighters[$aId]);
                    $next[] = $aId;
                } else {
                    $bId = (int) $ids[$i + 1];
                    $fight = self::duel($fighters[$aId], $fighters[$bId], Rng::duel($seed, $round, $slot, $aId, $bId), $balance);
                    $matches[] = ['round' => $round, 'slot' => $slot] + $fight;
                    $next[] = $fight['winner_id'];
                }
                $slot++;
            }
            $ids = $next;
            $round++;
        }

        return [
            'champion_id' => (int) $ids[0],
            'matches' => $matches,
            'entrants' => array_values($fighters),
        ];
    }

    /**
     * One knockout. Both fighters are set to full HP. The belt on each fighter persists across calls.
     *
     * @return array{character_a_id: int, character_b_id: int, winner_id: int, loser_id: int, reason: string, rounds: int, a_hp_end: int, b_hp_end: int, log: list<array>}
     */
    public static function duel(array &$a, array &$b, Rng $rng, array $balance): array
    {
        $a['hp'] = $a['max_hp'];
        $b['hp'] = $b['max_hp'];
        $log = [];
        $rounds = 0;
        $max = (int) $balance['combat']['max_rounds'];
        $die = (int) $balance['combat']['initiative_die'];

        for ($rnd = 1; $rnd <= $max; $rnd++) {
            $rounds = $rnd;
            foreach (self::initiative($a, $b, $rng, $die) as $side) {
                if ($a['hp'] <= 0 || $b['hp'] <= 0) {
                    break;
                }
                if ($side === 'a') {
                    if (self::drinkIfNeeded($a, $rnd, $log)) {
                        continue;
                    }
                    $event = Combat::resolveAttack($a, $b, $rng, $balance);
                    $log[] = $event + ['r' => $rnd, 'a' => $a['name'], 't' => $b['name']];
                } else {
                    if (self::drinkIfNeeded($b, $rnd, $log)) {
                        continue;
                    }
                    $event = Combat::resolveAttack($b, $a, $rng, $balance);
                    $log[] = $event + ['r' => $rnd, 'a' => $b['name'], 't' => $a['name']];
                }
                if ($a['hp'] <= 0 || $b['hp'] <= 0) {
                    return self::finishDuel($a, $b, 'knockout', $rounds, $log);
                }
            }
        }

        $winner = self::timeWinner($a, $b);
        $log[] = ['r' => $rounds, 'ev' => 'time', 'winner' => $winner === 'a' ? $a['name'] : $b['name']];

        return self::finishDuel($a, $b, 'time', $rounds, $log, $winner);
    }

    private static function drinkIfNeeded(array &$actor, int $round, array &$log): bool
    {
        $potion = Combat::choosePotion($actor['hp'], $actor['max_hp'], $actor['potion_pct'], $actor['belt']);
        if ($potion === null) {
            return false;
        }
        $event = Combat::drink($actor, $actor['belt'][$potion], $actor['uses']);
        $log[] = ['r' => $round, 'a' => $actor['name']] + $event;

        return true;
    }

    /** Gold and XP for knockout wins, plus the champion's bonus. A bye pays nothing. */
    public static function purse(int $level, int $knockoutWins, bool $champion, array $balance): array
    {
        $rates = $balance['arena'];
        $gold = $knockoutWins * (int) $rates['win_gold_per_level'] * $level;
        $xp = $knockoutWins * (int) $rates['win_xp_per_level'] * $level;
        if ($champion) {
            $gold += (int) $rates['champion_gold_per_level'] * $level;
            $xp += (int) $rates['champion_xp_per_level'] * $level;
        }

        return ['gold' => $gold, 'xp' => $xp];
    }

    /**
     * @return array{0: string, 1: string, 2: array}
     */
    public static function report(array $fighter, array $bracket, array $progress, array $purse, int $tournamentId, DateTimeInterface|string $when, string $displayTz = 'Asia/Bangkok'): array
    {
        $path = self::pathFor((int) $fighter['id'], $bracket['matches']);
        $champion = (int) $bracket['champion_id'] === (int) $fighter['id'];
        $outcome = $champion ? 'champion' : 'eliminated';
        $last = $path === [] ? null : $path[array_key_last($path)];
        $subject = $champion
            ? "[Arena #{$tournamentId}] Champion"
            : "[Arena #{$tournamentId}] Knocked out by ".($last['opponent'] ?? 'a rival');
        if ($progress['level'] !== (int) $fighter['level']) {
            $subject .= " (Lv {$fighter['level']} → {$progress['level']}!)";
        }
        $stamp = self::stamp($when, $displayTz);
        $lines = [
            self::line(),
            "  DELVEBOUND · ARENA REPORT · Arena #{$tournamentId} · {$stamp}",
            self::line(),
            '',
            "  {$fighter['name']}  ·  Lv {$fighter['level']}",
            '',
        ];
        foreach ($path as $step) {
            if ($step['reason'] === 'bye') {
                $lines[] = "  Round {$step['round']}: Bye. You wait on the sand.";

                continue;
            }
            $how = $step['reason'] === 'time' ? 'when the round limit fell' : "in {$step['rounds']} rounds";
            $lines[] = $step['won']
                ? "  Round {$step['round']}: You knocked out {$step['opponent']} {$how}."
                : "  Round {$step['round']}: {$step['opponent']} knocked you out {$how}.";
        }
        $lines[] = '';
        $lines[] = "  Prize: {$purse['gold']} gold, {$purse['xp']} XP.";
        if ($progress['gold'] > 0) {
            $lines[] = "  Level-up gold: {$progress['gold']}.";
        }
        if ($champion) {
            $lines[] = '  You are the champion of the arena.';
        }
        $lines[] = '  You leave healthy. A knockout here does not wound you.';
        $lines[] = '';
        $lines[] = self::line('─');

        $opponent = null;
        foreach (array_reverse($path) as $step) {
            if ($step['reason'] !== 'bye') {
                $opponent = $step['opponent'];
                break;
            }
        }

        return [
            mb_substr($subject, 0, 160),
            implode("\n", $lines)."\n",
            [
                'outcome' => $outcome,
                'tournament_id' => $tournamentId,
                'wins' => self::knockoutWins((int) $fighter['id'], $bracket['matches']),
                'gold' => $purse['gold'] + $progress['gold'],
                'xp' => $purse['xp'],
                'level_before' => (int) $fighter['level'],
                'level_after' => $progress['level'],
                'opponent' => $opponent,
            ],
        ];
    }

    /** @return list<array{round: int, reason: string, won: bool, opponent: ?string, rounds: int}> */
    public static function pathFor(int $characterId, array $matches): array
    {
        $path = [];
        foreach ($matches as $match) {
            $a = (int) $match['character_a_id'];
            $b = $match['character_b_id'] === null ? null : (int) $match['character_b_id'];
            if ($a !== $characterId && $b !== $characterId) {
                continue;
            }
            $won = (int) $match['winner_id'] === $characterId;
            $opponent = $a === $characterId ? ($match['b_name'] ?? null) : ($match['a_name'] ?? null);
            $path[] = [
                'round' => (int) $match['round'],
                'reason' => $match['reason'],
                'won' => $won,
                'opponent' => $opponent,
                'rounds' => (int) $match['rounds'],
            ];
        }

        return $path;
    }

    public static function knockoutWins(int $characterId, array $matches): int
    {
        $wins = 0;
        foreach ($matches as $match) {
            if ((int) $match['winner_id'] === $characterId && $match['reason'] !== 'bye') {
                $wins++;
            }
        }

        return $wins;
    }

    private static function bye(int $round, int $slot, array $fighter): array
    {
        return [
            'round' => $round,
            'slot' => $slot,
            'character_a_id' => (int) $fighter['id'],
            'character_b_id' => null,
            'a_name' => $fighter['name'],
            'b_name' => null,
            'winner_id' => (int) $fighter['id'],
            'loser_id' => null,
            'reason' => 'bye',
            'rounds' => 0,
            'a_hp_end' => (int) $fighter['max_hp'],
            'b_hp_end' => null,
            'log' => [],
        ];
    }

    private static function finishDuel(array $a, array $b, string $reason, int $rounds, array $log, ?string $winnerSide = null): array
    {
        if ($winnerSide === null) {
            $winnerSide = $a['hp'] > 0 ? 'a' : 'b';
        }
        $winner = $winnerSide === 'a' ? $a : $b;
        $loser = $winnerSide === 'a' ? $b : $a;

        return [
            'character_a_id' => (int) $a['id'],
            'character_b_id' => (int) $b['id'],
            'a_name' => $a['name'],
            'b_name' => $b['name'],
            'winner_id' => (int) $winner['id'],
            'loser_id' => (int) $loser['id'],
            'reason' => $reason,
            'rounds' => $rounds,
            'a_hp_end' => max(0, (int) $a['hp']),
            'b_hp_end' => max(0, (int) $b['hp']),
            'log' => $log,
        ];
    }

    /** Higher remaining HP fraction, then higher HP, then the lower character id. */
    private static function timeWinner(array $a, array $b): string
    {
        $cmp = ($a['hp'] * $b['max_hp']) <=> ($b['hp'] * $a['max_hp']);
        if ($cmp === 0) {
            $cmp = $a['hp'] <=> $b['hp'];
        }
        if ($cmp === 0) {
            return $a['id'] < $b['id'] ? 'a' : 'b';
        }

        return $cmp > 0 ? 'a' : 'b';
    }

    /** @return list<'a'|'b'> */
    private static function initiative(array $a, array $b, Rng $rng, int $die): array
    {
        $lowFirst = $a['id'] < $b['id'];
        $rollLow = ($lowFirst ? $a['speed'] : $b['speed']) + $rng->randint(0, $die);
        $rollHigh = ($lowFirst ? $b['speed'] : $a['speed']) + $rng->randint(0, $die);
        $sideLow = $lowFirst ? 'a' : 'b';
        $sideHigh = $lowFirst ? 'b' : 'a';
        if ($rollLow === $rollHigh) {
            return [$sideLow, $sideHigh];
        }

        return $rollLow > $rollHigh ? [$sideLow, $sideHigh] : [$sideHigh, $sideLow];
    }

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

    private static function openingBuffs(array &$belt, array &$uses): array
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

    private static function line(string $char = '═'): string
    {
        return str_repeat($char, 59);
    }

    private static function stamp(DateTimeInterface|string $when, string $tz): string
    {
        $utc = new DateTimeZone('UTC');
        $at = is_string($when) ? new DateTimeImmutable($when, $utc) : DateTimeImmutable::createFromInterface($when);

        return $at->setTimezone(new DateTimeZone($tz))->format('d M Y H:i');
    }
}
