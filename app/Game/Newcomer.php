<?php

namespace App\Game;

/**
 * One bot born at the start of a run (DESIGN.md §8.0).
 * Level 1, or somewhere in the top window strictly below the current highest level.
 * Above level 1 they arrive with a rolled kit. The bot phase spends their points and equips it.
 */
final class Newcomer
{
    private const GIVEN = [
        'Alden', 'Bram', 'Bryn', 'Cass', 'Cora', 'Dain', 'Dorrin', 'Eda',
        'Els', 'Faye', 'Fen', 'Galvin', 'Gilda', 'Hesta', 'Holt', 'Inga',
        'Ivo', 'Joren', 'Joss', 'Kessa', 'Kit', 'Lark', 'Ludo', 'Mara',
        'Meryl', 'Nessa', 'Nim', 'Opal', 'Orin', 'Pella', 'Perrin', 'Quinn',
        'Rhett', 'Rook', 'Sera', 'Syl', 'Tamsin', 'Torin', 'Ulric', 'Una',
        'Vera', 'Vesper', 'Wes', 'Wynn', 'Yael', 'Yarrow', 'Zara',
    ];

    private const EPITHET = [
        'Ash', 'Bark', 'Birch', 'Briar', 'Brook', 'Clay', 'Cloud', 'Cole',
        'Croft', 'Dawn', 'Dell', 'Drift', 'Dusk', 'Ember', 'Fern', 'Field',
        'Flint', 'Frost', 'Gale', 'Glen', 'Grey', 'Grove', 'Hart', 'Heath',
        'Hearth', 'Holm', 'Hollow', 'Ivy', 'Keen', 'Lane', 'Lyn', 'Marsh',
        'Mill', 'Moor', 'Moss', 'North', 'Oak', 'Pebble', 'Pike', 'Reed',
        'Ridge', 'Shaw', 'Shore', 'Sprig', 'Stone', 'Thorn', 'Vale', 'Weir',
        'Wick', 'Wild', 'Wood', 'Wren',
    ];

    /**
     * @param  list<array{id: int, archetype: string, preferred_scaling: string, retreat_threshold_pct: int}>  $profiles
     * @param  list<string>  $takenNames
     * @param  list<array>  $templates
     * @param  list<array>  $affixes
     * @return array<string, mixed>|null
     */
    public static function plan(int $activeBots, int $highest, array $profiles, array $takenNames, array $templates, array $affixes, Rng $rng, array $balance, array $legendaryNames = []): ?array
    {
        $rules = $balance['newcomers'];
        if ($profiles === [] || $activeBots >= (int) $rules['max_bots']) {
            return null;
        }
        if ($rng->random() >= (float) $rules['spawn_chance']) {
            return null;
        }
        $profile = $rng->choice($profiles);
        $level = self::level($highest, $rng, $balance);
        $name = self::name($rng, $takenNames, (float) $rules['mononym_chance']);
        $base = (int) $balance['character']['base_stat'];
        $retreat = (int) $profile['retreat_threshold_pct'];
        $potion = min(90, $retreat + (int) $balance['bots']['potion_threshold_offset']);
        $gold = (int) $balance['character']['starting_gold'] + max(0, $level - 1) * (int) $rules['gold_per_level'];
        $items = $level > 1
            ? self::rolledKit($templates, $affixes, (string) $profile['preferred_scaling'], $level, $rng, $balance, $legendaryNames)
            : self::starterKit($templates, (string) $profile['preferred_scaling'], $balance);

        $archetype = (string) $profile['archetype'];
        $body = $level > 1
            ? "A hero has been born.\n\n{$name}, a {$archetype}, arrives at level {$level}, already carrying gear."
            : "A hero has been born.\n\n{$name}, a {$archetype}, takes a first step in Lanternford.";

        return [
            'name' => $name,
            'level' => $level,
            'total_xp' => Progression::xpSpentToReach($level, $balance),
            'gold' => $gold,
            'profile_id' => (int) $profile['id'],
            'archetype' => $archetype,
            'base_stat' => $base,
            'unspent' => Progression::pointPool($level, $balance),
            'retreat' => $retreat,
            'potion' => $potion,
            'items' => $items,
            'potion_code' => (string) $balance['character']['starting_potion_code'],
            'potion_qty' => (int) $balance['character']['starting_potion_qty'],
            'potion_belt' => (int) $balance['character']['starting_potion_belt'],
            'subject' => 'A hero has been born',
            'body' => $body,
        ];
    }

    public static function level(int $highest, Rng $rng, array $balance): int
    {
        if ($highest <= 1) {
            return 1;
        }
        if ($rng->random() < (float) $balance['newcomers']['fresh_chance']) {
            return 1;
        }
        $low = max(1, $highest - (int) $balance['newcomers']['top_window']);
        $high = $highest - 1;

        return $low > $high ? 1 : $rng->randint($low, $high);
    }

    /**
     * A given name, sometimes with a surname. Surnames already in town are skipped until every surname has been used.
     *
     * @param  list<string>  $takenNames
     */
    public static function name(Rng $rng, array $takenNames, float $mononymChance): string
    {
        $taken = array_fill_keys($takenNames, true);
        $usedGiven = [];
        $usedSurnames = [];
        foreach ($takenNames as $full) {
            $parts = preg_split('/\s+/', trim($full)) ?: [];
            if ($parts === [] || $parts[0] === '') {
                continue;
            }
            $usedGiven[$parts[0]] = true;
            if (count($parts) >= 2) {
                $usedSurnames[$parts[count($parts) - 1]] = true;
            }
        }
        $givens = self::unused(self::GIVEN, $usedGiven);
        $surnames = self::unused(self::EPITHET, $usedSurnames);
        $single = $rng->random() < $mononymChance;
        for ($try = 0; $try < 32; $try++) {
            $given = $givens[$rng->randint(0, count($givens) - 1)];
            $name = $single ? $given : $given.' '.$surnames[$rng->randint(0, count($surnames) - 1)];
            if (! isset($taken[$name]) && strlen($name) <= 32) {
                return $name;
            }
        }
        $base = $givens[$rng->randint(0, count($givens) - 1)];
        $n = 2;
        while (isset($taken["{$base} {$n}"])) {
            $n++;
        }

        return "{$base} {$n}";
    }

    /**
     * @param  list<string>  $pool
     * @param  array<string, true>  $used
     * @return list<string>
     */
    private static function unused(array $pool, array $used): array
    {
        $open = array_values(array_filter($pool, fn ($word) => ! isset($used[$word])));

        return $open === [] ? $pool : $open;
    }

    /**
     * @param  list<array>  $templates
     * @return list<array>
     */
    private static function starterKit(array $templates, string $scaling, array $balance): array
    {
        $weapons = $balance['character']['starter_weapons'];
        $codes = array_merge(
            [($weapons[$scaling] ?? reset($weapons))],
            $balance['character']['starter_armor'],
        );
        $byCode = [];
        foreach ($templates as $template) {
            $byCode[$template['code']] = $template;
        }
        $common = Loot::tierByName($balance, 'common');
        $items = [];
        foreach ($codes as $code) {
            if (! isset($byCode[$code])) {
                continue;
            }
            $template = $byCode[$code];
            $items[] = Loot::buildInstance($template, (int) $template['req_level'], $common, [], 'starter', $balance);
        }

        return $items;
    }

    /**
     * @param  list<array>  $templates
     * @param  list<array>  $affixes
     * @return list<array>
     */
    private static function rolledKit(array $templates, array $affixes, string $scaling, int $level, Rng $rng, array $balance, array $legendaryNames): array
    {
        $items = [];
        $twoHanded = false;
        foreach ($balance['newcomers']['kit_slots'] as $slot) {
            if ($slot === 'offhand' && $twoHanded) {
                continue;
            }
            $template = self::pickTemplate($templates, (string) $slot, $level, $slot === 'weapon' ? $scaling : null, $rng, (int) $balance['newcomers']['gear_lag']);
            if ($template === null) {
                continue;
            }
            $item = Loot::rollItem($template, $level, 0.0, $affixes, $rng, $balance, 'starter', null, $legendaryNames);
            if (strlen($item['display_name']) > 96) {
                $item['display_name'] = substr($item['display_name'], 0, 96);
            }
            $items[] = $item;
            $twoHanded = $slot === 'weapon' && ! empty($template['two_handed']);
        }

        return $items;
    }

    /**
     * @param  list<array>  $templates
     */
    private static function pickTemplate(array $templates, string $slot, int $level, ?string $scaling, Rng $rng, int $lag): ?array
    {
        $pool = self::eligible($templates, $slot, $level, $scaling);
        if ($pool === [] && $scaling !== null) {
            $pool = self::eligible($templates, $slot, $level, null);
        }
        if ($pool === []) {
            return null;
        }
        $near = array_values(array_filter($pool, fn ($template) => (int) $template['req_level'] >= $level - $lag));

        return $rng->choice($near !== [] ? $near : $pool);
    }

    /**
     * @param  list<array>  $templates
     * @return list<array>
     */
    private static function eligible(array $templates, string $slot, int $level, ?string $scaling): array
    {
        return array_values(array_filter($templates, function ($template) use ($slot, $level, $scaling) {
            if (($template['slot'] ?? null) !== $slot || (int) $template['req_level'] > $level) {
                return false;
            }

            return $scaling === null || ($template['scaling_stat'] ?? null) === $scaling;
        }));
    }
}
