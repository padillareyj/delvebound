<?php

namespace App\Game;

/**
 * The premium store: titles, wings, auras, pets, frames, and gold bundles bought with play dollars (DESIGN.md §16).
 * Pure: catalog rows and a character's premium state in, decisions out. Money is in cents.
 *
 * State shape: ['cash' => int, 'owned' => list<string code>, 'equipped' => array<category, code>, 'gear' => list<catalog row>]
 */
final class Premium
{
    public const BUNDLE = 'bundle';

    /** A premium_items row with JSON decoded and numbers typed. */
    public static function row(array $row): array
    {
        $bonuses = $row['bonuses'] ?? null;
        if (is_string($bonuses)) {
            $bonuses = json_decode($bonuses, true) ?: [];
        }

        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'tier' => (string) $row['tier'],
            'glyph' => (string) $row['glyph'],
            'style' => (string) $row['style'],
            'description' => $row['description'] ?? null,
            'price_cents' => (int) $row['price_cents'],
            'bonuses' => (array) ($bonuses ?? []),
            'power' => (int) $row['power'],
            'grant_gold' => (int) $row['grant_gold'],
            'is_active' => (bool) $row['is_active'],
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    /** Stored cash, or the starting allowance when the column was never written. */
    public static function cash(?int $stored, array $balance): int
    {
        return $stored ?? (int) $balance['premium']['starting_cash_cents'];
    }

    public static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    /**
     * Build a state from the stored column values. Unknown or inactive codes are dropped; an equipped code
     * must also be owned and sit in its own category.
     *
     * @param  array<string, array>  $catalog  rows keyed by code
     */
    public static function state(?int $storedCash, mixed $json, array $catalog, array $balance): array
    {
        $raw = is_string($json) ? (json_decode($json, true) ?: []) : (array) ($json ?? []);
        $owned = [];
        foreach ((array) ($raw['owned'] ?? []) as $code) {
            if (is_string($code) && isset($catalog[$code]) && $catalog[$code]['category'] !== self::BUNDLE && ! in_array($code, $owned, true)) {
                $owned[] = $code;
            }
        }
        sort($owned);
        $equipped = [];
        foreach ((array) ($raw['equipped'] ?? []) as $category => $code) {
            if (is_string($code) && in_array($code, $owned, true) && $catalog[$code]['category'] === $category
                && in_array($category, $balance['premium']['categories'], true)) {
                $equipped[$category] = $code;
            }
        }
        ksort($equipped);

        return [
            'cash' => self::cash($storedCash, $balance),
            'owned' => $owned,
            'equipped' => $equipped,
            'gear' => self::gear($equipped, $catalog),
        ];
    }

    /** The JSON written back to characters.premium. */
    public static function encode(array $state): string
    {
        return json_encode([
            'owned' => array_values($state['owned']),
            'equipped' => (object) $state['equipped'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<array> catalog rows of the equipped codes, in category order */
    public static function gear(array $equipped, array $catalog): array
    {
        ksort($equipped);
        $rows = [];
        foreach ($equipped as $code) {
            if (isset($catalog[$code])) {
                $rows[] = $catalog[$code];
            }
        }

        return $rows;
    }

    /** Flat and percent mods from the equipped premium gear (Stats::BONUS_KEYS only). */
    public static function bonuses(array $gear): array
    {
        $total = [];
        foreach ($gear as $row) {
            foreach ((array) ($row['bonuses'] ?? []) as $key => $value) {
                if (in_array($key, Stats::BONUS_KEYS, true)) {
                    $total[$key] = ($total[$key] ?? 0.0) + (float) $value;
                }
            }
        }

        return $total;
    }

    public static function power(array $gear): int
    {
        $total = 0;
        foreach ($gear as $row) {
            $total += (int) ($row['power'] ?? 0);
        }

        return $total;
    }

    /** Skills + horcrux + premium: the bonus array Stats::derived takes. */
    public static function combinedBonus(array $char, array $balance): array
    {
        return Stats::mergeBonus(
            Growth::bonuses($char['skills'] ?? [], (int) ($char['horcrux']['tier'] ?? 0), $balance),
            self::bonuses($char['premium']['gear'] ?? []),
        );
    }

    /** @return array{0: bool, 1: string|null} */
    public static function canBuy(array $item, array $state): array
    {
        if (! ($item['is_active'] ?? true)) {
            return [false, 'That item is no longer sold.'];
        }
        if ($item['category'] !== self::BUNDLE && in_array($item['code'], $state['owned'], true)) {
            return [false, "You already own {$item['name']}."];
        }
        if ((int) $state['cash'] < (int) $item['price_cents']) {
            return [false, $item['name'].' costs '.self::money((int) $item['price_cents']).'; you have '.self::money((int) $state['cash']).'.'];
        }

        return [true, null];
    }

    /**
     * Apply a purchase to the state. Gear is owned and worn in its category; a bundle only returns its gold.
     *
     * @param  array<string, array>  $catalog
     * @return int gold granted
     */
    public static function buy(array &$state, array $item, array $catalog): int
    {
        $state['cash'] = (int) $state['cash'] - (int) $item['price_cents'];
        if ($item['category'] === self::BUNDLE) {
            return (int) $item['grant_gold'];
        }
        $state['owned'][] = $item['code'];
        sort($state['owned']);
        self::wear($state, $item['code'], $catalog);

        return 0;
    }

    /** @return array{0: bool, 1: string|null} */
    public static function canWear(array $item, array $state): array
    {
        if ($item['category'] === self::BUNDLE) {
            return [false, 'Bundles cannot be worn.'];
        }
        if (! in_array($item['code'], $state['owned'], true)) {
            return [false, "You do not own {$item['name']}."];
        }

        return [true, null];
    }

    public static function wear(array &$state, string $code, array $catalog): void
    {
        $state['equipped'][$catalog[$code]['category']] = $code;
        ksort($state['equipped']);
        $state['gear'] = self::gear($state['equipped'], $catalog);
    }

    public static function takeOff(array &$state, string $category, array $catalog): void
    {
        unset($state['equipped'][$category]);
        $state['gear'] = self::gear($state['equipped'], $catalog);
    }

    /**
     * Bot step (DESIGN.md §16.4): wear the best owned piece per category, then buy up to bot_buys_per_run
     * pieces that raise the profile's score by more than bot_min_gain. Largest gain first; ties go to the
     * cheaper piece, then the code. Bundles are never bought. No randomness.
     *
     * @param  array<string, array>  $catalog
     * @return list<array{code: string, name: string, price_cents: int, cash_after: int}>
     */
    public static function botShop(array &$state, array $catalog, array $balance): array
    {
        $state['premium'] ??= self::state(null, null, $catalog, $balance);
        $p = $balance['premium'];
        $codes = array_keys($catalog);
        sort($codes);

        foreach ($p['categories'] as $category) {
            $best = $state['premium']['equipped'][$category] ?? null;
            $bestScore = self::score($state, $balance);
            foreach ($state['premium']['owned'] as $code) {
                if ($catalog[$code]['category'] !== $category || $code === $best) {
                    continue;
                }
                $trial = $state;
                self::wear($trial['premium'], $code, $catalog);
                $score = self::score($trial, $balance);
                if ($score > $bestScore) {
                    $best = $code;
                    $bestScore = $score;
                }
            }
            if ($best !== null) {
                self::wear($state['premium'], $best, $catalog);
            }
        }

        $bought = [];
        for ($n = 0; $n < (int) $p['bot_buys_per_run']; $n++) {
            $base = self::score($state, $balance);
            $pick = null;
            foreach ($codes as $code) {
                $item = $catalog[$code];
                if ($item['category'] === self::BUNDLE || ! self::canBuy($item, $state['premium'])[0]) {
                    continue;
                }
                $trial = $state;
                self::wear($trial['premium'], $code, $catalog);
                $trial['premium']['owned'][] = $code;
                $gain = self::score($trial, $balance) - $base;
                if ($gain <= (float) $p['bot_min_gain']) {
                    continue;
                }
                $key = [$gain, -(int) $item['price_cents']];
                if ($pick === null || $key > $pick['key']) {
                    $pick = ['code' => $code, 'key' => $key];
                }
            }
            if ($pick === null) {
                break;
            }
            $item = $catalog[$pick['code']];
            self::buy($state['premium'], $item, $catalog);
            $bought[] = [
                'code' => $item['code'],
                'name' => $item['name'],
                'price_cents' => (int) $item['price_cents'],
                'cash_after' => (int) $state['premium']['cash'],
            ];
        }

        return $bought;
    }

    /** A bot's view of its own strength: profile gear weights on derived stats, plus the premium-only stats. */
    public static function score(array $state, array $balance): float
    {
        $derived = Stats::derived(
            $state['stats'],
            (int) $state['level'],
            array_values(array_filter($state['equipment'] ?? [])),
            $balance,
            0,
            [],
            self::combinedBonus($state, $balance),
        );
        $values = [
            'hp' => $derived['hp'],
            'attack' => $derived['attack'],
            'defense' => $derived['defense'],
            'mres' => $derived['mres'],
            'crit' => $derived['crit'],
            'dodge' => $derived['dodge'],
            'speed' => $derived['speed'],
            'mf' => $derived['luck'],
        ];
        $weights = $state['profile']['gear_weights'] ?? [];
        $score = 0.0;
        foreach ($balance['bots']['gear_units'] as $key => $unit) {
            $score += (float) ($weights[$key] ?? 0) * $values[$key] / $unit;
        }
        $extra = $balance['premium']['bot_extra_weights'];
        $score += (float) $extra['lifesteal'] * $derived['lifesteal'];
        $score += (float) $extra['penetration'] * $derived['penetration'];
        $score += (float) $extra['dmg_reduction'] * $derived['dmg_reduction'];
        $score += (float) $extra['xp_pct'] * ($derived['xp_mult'] - 1) * 100;

        return $score;
    }
}
