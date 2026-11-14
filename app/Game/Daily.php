<?php

namespace App\Game;

/**
 * Daily quests and activity chests (DESIGN.md §17). Day boundaries use the display timezone.
 * Progress counters are raised by the simulation (and by a login claim on the website); rewards are
 * claimed on the website (or by bots) into the material bag.
 *
 * State: ['day' => 'Y-m-d', 'counters' => array<type,int>, 'claimed' => list<code>, 'chests' => list<int at>]
 */
final class Daily
{
    public static function dayKey(\DateTimeInterface $now, string $timezone): string
    {
        return (new \DateTimeImmutable('@'.$now->getTimestamp()))
            ->setTimezone(new \DateTimeZone($timezone))
            ->format('Y-m-d');
    }

    /** Fresh or rolled state for today's date. */
    public static function state(mixed $json, string $day, array $balance): array
    {
        $raw = is_string($json) ? (json_decode($json, true) ?: []) : (array) ($json ?? []);
        if (($raw['day'] ?? null) !== $day) {
            return ['day' => $day, 'counters' => [], 'claimed' => [], 'chests' => []];
        }
        $counters = [];
        foreach ((array) ($raw['counters'] ?? []) as $type => $value) {
            if (is_string($type) && is_numeric($value)) {
                $counters[$type] = max(0, (int) $value);
            }
        }
        $claimed = [];
        foreach ((array) ($raw['claimed'] ?? []) as $code) {
            if (is_string($code) && ! in_array($code, $claimed, true)) {
                $claimed[] = $code;
            }
        }
        sort($claimed);
        $chests = [];
        foreach ((array) ($raw['chests'] ?? []) as $at) {
            $at = (int) $at;
            if ($at > 0 && ! in_array($at, $chests, true)) {
                $chests[] = $at;
            }
        }
        sort($chests);

        return ['day' => $day, 'counters' => $counters, 'claimed' => $claimed, 'chests' => $chests];
    }

    public static function encode(array $state): string
    {
        return json_encode([
            'day' => $state['day'],
            'counters' => (object) $state['counters'],
            'claimed' => array_values($state['claimed']),
            'chests' => array_values($state['chests']),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function activity(array $state, array $balance): int
    {
        $total = 0;
        foreach ($balance['daily']['quests'] as $quest) {
            if (in_array($quest['code'], $state['claimed'], true)) {
                $total += (int) $quest['activity'];
            }
        }

        return $total;
    }

    /** @return list<array> quest rows with progress / ready / claimed for the UI and claim rules */
    public static function board(array $state, array $balance): array
    {
        $board = [];
        foreach ($balance['daily']['quests'] as $quest) {
            $progress = min((int) $quest['target'], (int) ($state['counters'][$quest['type']] ?? 0));
            $claimed = in_array($quest['code'], $state['claimed'], true);
            $board[] = [
                'code' => $quest['code'],
                'name' => $quest['name'],
                'blurb' => $quest['blurb'],
                'type' => $quest['type'],
                'target' => (int) $quest['target'],
                'progress' => $progress,
                'activity' => (int) $quest['activity'],
                'rewards' => $quest['rewards'],
                'claimed' => $claimed,
                'ready' => ! $claimed && $progress >= (int) $quest['target'],
            ];
        }

        return $board;
    }

    /** @return list<array{at: int, rewards: array, claimed: bool, ready: bool}> */
    public static function chests(array $state, array $balance): array
    {
        $activity = self::activity($state, $balance);
        $out = [];
        foreach ($balance['daily']['activity_chests'] as $chest) {
            $at = (int) $chest['at'];
            $claimed = in_array($at, $state['chests'], true);
            $out[] = [
                'at' => $at,
                'rewards' => $chest['rewards'],
                'claimed' => $claimed,
                'ready' => ! $claimed && $activity >= $at,
            ];
        }

        return $out;
    }

    public static function markLogin(array &$state): void
    {
        $state['counters']['login'] = max(1, (int) ($state['counters']['login'] ?? 0));
    }

    /** Apply one finished trip to today's counters. Rest / rejected trips do not count. */
    public static function noteTrip(array &$state, array $result): void
    {
        $outcome = $result['outcome'] ?? '';
        if (in_array($outcome, ['rested', 'blocked_injured', 'order_rejected'], true)) {
            return;
        }
        $state['counters']['trips'] = (int) ($state['counters']['trips'] ?? 0) + 1;
        $kills = 0;
        foreach ($result['encounters'] ?? [] as $enc) {
            if (($enc['result'] ?? '') === 'won') {
                $kills += count($enc['monsters'] ?? []);
            }
        }
        if ($kills > 0) {
            $state['counters']['kills'] = (int) ($state['counters']['kills'] ?? 0) + $kills;
        }
        if ($outcome === 'cleared') {
            $state['counters']['clears'] = (int) ($state['counters']['clears'] ?? 0) + 1;
        }
        $gold = (int) ($result['gold_gained'] ?? 0);
        if ($gold > 0) {
            $state['counters']['gold'] = (int) ($state['counters']['gold'] ?? 0) + $gold;
        }
    }

    public static function noteArena(array &$state, bool $won): void
    {
        $state['counters']['arena'] = (int) ($state['counters']['arena'] ?? 0) + 1;
        if ($won) {
            $state['counters']['arena_wins'] = (int) ($state['counters']['arena_wins'] ?? 0) + 1;
        }
    }

    /**
     * Claim a finished quest into the material bag.
     *
     * @param  array<string, int>  $bag  code => qty
     * @return array{0: bool, 1: string|null, 2: array<string, int>}
     */
    public static function claimQuest(array &$state, array &$bag, string $code, array $balance): array
    {
        foreach (self::board($state, $balance) as $quest) {
            if ($quest['code'] !== $code) {
                continue;
            }
            if ($quest['claimed']) {
                return [false, 'Already claimed.', []];
            }
            if (! $quest['ready']) {
                return [false, 'Not finished yet.', []];
            }
            $state['claimed'][] = $code;
            sort($state['claimed']);
            $gained = self::grant($bag, $quest['rewards']);

            return [true, null, $gained];
        }

        return [false, 'Unknown quest.', []];
    }

    /**
     * @param  array<string, int>  $bag
     * @return array{0: bool, 1: string|null, 2: array<string, int>}
     */
    public static function claimChest(array &$state, array &$bag, int $at, array $balance): array
    {
        foreach (self::chests($state, $balance) as $chest) {
            if ($chest['at'] !== $at) {
                continue;
            }
            if ($chest['claimed']) {
                return [false, 'Chest already opened.', []];
            }
            if (! $chest['ready']) {
                return [false, 'Not enough activity yet.', []];
            }
            $state['chests'][] = $at;
            sort($state['chests']);
            $gained = self::grant($bag, $chest['rewards']);

            return [true, null, $gained];
        }

        return [false, 'Unknown chest.', []];
    }

    /**
     * Claim every ready quest and chest (bots). Deterministic order by code / chest at.
     *
     * @param  array<string, int>  $bag
     * @return list<array{kind: string, code?: string, at?: int, rewards: array<string, int>}>
     */
    public static function claimAll(array &$state, array &$bag, array $balance): array
    {
        $log = [];
        foreach ($balance['daily']['quests'] as $quest) {
            [$ok, , $gained] = self::claimQuest($state, $bag, $quest['code'], $balance);
            if ($ok) {
                $log[] = ['kind' => 'quest', 'code' => $quest['code'], 'rewards' => $gained];
            }
        }
        foreach ($balance['daily']['activity_chests'] as $chest) {
            [$ok, , $gained] = self::claimChest($state, $bag, (int) $chest['at'], $balance);
            if ($ok) {
                $log[] = ['kind' => 'chest', 'at' => (int) $chest['at'], 'rewards' => $gained];
            }
        }

        return $log;
    }

    /** @param  array<string, int>  $bag  @return array<string, int> */
    private static function grant(array &$bag, array $rewards): array
    {
        $gained = [];
        $codes = array_keys($rewards);
        sort($codes);
        foreach ($codes as $code) {
            $qty = max(0, (int) $rewards[$code]);
            if ($qty <= 0) {
                continue;
            }
            $bag[$code] = (int) ($bag[$code] ?? 0) + $qty;
            $gained[$code] = $qty;
        }

        return $gained;
    }
}
