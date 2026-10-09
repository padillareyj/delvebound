<?php

namespace App\Game;

use InvalidArgumentException;

/**
 * Combine two unequipped pieces of the same slot (DESIGN.md §6.9).
 * The attempt always spends gold and consumes both pieces. It cracks, yields a new piece
 * at the better input's rarity, or sings and returns something finer.
 */
final class Forge
{
    /** Luck factor from the LUK stat. Gear magic find does not move the forge. */
    public static function luckFactor(int $luk, array $balance): float
    {
        $forge = $balance['forge'];

        return min((float) $forge['luck_cap'], (float) $forge['luck_per_luk'] * max(0, $luk));
    }

    public static function cost(array $left, array $right, array $balance): int
    {
        $forge = $balance['forge'];
        $ilvl = max((int) $left['item_level'], (int) $right['item_level']);
        $higher = self::higherTier($left, $right, $balance);
        $mult = (float) ($forge['rarity_gold_mult'][$higher['name']] ?? 1);
        $raw = ((int) $forge['base_gold'] + (int) $forge['gold_per_ilvl'] * $ilvl) * $mult;

        return max(1, Numbers::halfUp($raw));
    }

    /**
     * Outcome weights after the lower rarity and luck have shifted them.
     *
     * @return array{fail: float, craft: float, masterpiece: float}
     */
    public static function weights(array $left, array $right, float $luck, array $balance): array
    {
        $forge = $balance['forge'];
        $quality = min(self::tierIndex($left['rarity'], $balance), self::tierIndex($right['rarity'], $balance));
        $failScale = max((float) $forge['fail_floor'], 1 - $quality * (float) $forge['fail_cut_per_tier']);

        return [
            'fail' => (float) $forge['fail_weight'] * $failScale,
            'craft' => (float) $forge['craft_weight'] * (1 + $luck * (float) $forge['craft_luck']),
            'masterpiece' => (float) $forge['masterpiece_weight']
                * (1 + $quality * (float) $forge['masterpiece_per_tier'])
                * (1 + $luck * (float) $forge['masterpiece_luck']),
        ];
    }

    /**
     * What the page shows before the player strikes. No roll.
     *
     * @return array{ok: bool, reason: ?string, gold: int, chances: ?array{fail: float, craft: float, masterpiece: float}, slot: ?string, left_id: int, right_id: int}
     */
    public static function quote(array $left, array $right, int $gold, int $luk, array $balance): array
    {
        $quote = [
            'ok' => false,
            'reason' => null,
            'gold' => 0,
            'chances' => null,
            'slot' => null,
            'left_id' => (int) ($left['id'] ?? 0),
            'right_id' => (int) ($right['id'] ?? 0),
        ];
        if ((int) ($left['id'] ?? 0) === (int) ($right['id'] ?? 0)) {
            $quote['reason'] = 'Choose two different pieces.';

            return $quote;
        }
        if (($left['slot'] ?? null) !== ($right['slot'] ?? null)) {
            $quote['reason'] = 'Both pieces have to be the same slot.';

            return $quote;
        }
        $quote['gold'] = self::cost($left, $right, $balance);
        $quote['chances'] = self::chancePercents(self::weights($left, $right, self::luckFactor($luk, $balance), $balance));
        $quote['slot'] = $left['slot'];
        if (! empty($left['equipped']) || ! empty($right['equipped'])) {
            $quote['reason'] = 'Unequip both pieces before they go in the forge.';

            return $quote;
        }
        if ($gold < $quote['gold']) {
            $quote['reason'] = "The forge costs {$quote['gold']} gold.";

            return $quote;
        }
        $quote['ok'] = true;

        return $quote;
    }

    /**
     * Roll the attempt. The caller has already checked gold, ownership, and that neither piece is worn.
     *
     * @param  list<array>  $templates
     * @param  list<array>  $affixPool
     * @param  list<array>  $legendaryNames
     * @return array{outcome: string, gold: int, item: ?array}
     */
    public static function combine(array $left, array $right, array $templates, array $affixPool, array $legendaryNames, int $luk, array $balance, Rng $rng): array
    {
        if ((int) ($left['id'] ?? 0) === (int) ($right['id'] ?? 0) || ($left['slot'] ?? null) !== ($right['slot'] ?? null)) {
            throw new InvalidArgumentException('Forge inputs must be two different pieces of the same slot.');
        }
        $weights = self::weights($left, $right, self::luckFactor($luk, $balance), $balance);
        $outcome = Loot::weightedKey($weights, $rng, fn ($w) => $w);
        if (! is_string($outcome)) {
            throw new InvalidArgumentException('Forge weights are empty.');
        }
        $gold = self::cost($left, $right, $balance);
        if ($outcome === 'fail') {
            return ['outcome' => 'fail', 'gold' => $gold, 'item' => null];
        }

        $average = Numbers::halfUp(((int) $left['item_level'] + (int) $right['item_level']) / 2);
        $sourceLevel = max(1, $average);
        $tierIndex = max(self::tierIndex($left['rarity'], $balance), self::tierIndex($right['rarity'], $balance));
        if ($outcome === 'masterpiece') {
            $sourceLevel += (int) $balance['forge']['masterpiece_ilvl_bonus'];
            $tierIndex = min($tierIndex + 1, count($balance['loot']['rarity']) - 1);
        }
        $rarity = $balance['loot']['rarity'][$tierIndex];
        $template = self::pickTemplate($templates, (string) $left['slot'], $sourceLevel, $balance, $rng);
        $ilvl = max((int) $template['req_level'], $sourceLevel);
        $affixes = Loot::rollAffixes(
            $affixPool,
            $template,
            $ilvl,
            (int) $rarity['affixes'],
            $rng,
            $balance,
            $rarity['name'] === 'legendary',
        );
        $item = Loot::buildInstance($template, $ilvl, $rarity, $affixes, 'craft', $balance, $legendaryNames, $rng);

        return ['outcome' => $outcome, 'gold' => $gold, 'item' => $item];
    }

    public static function message(array $result): string
    {
        if (($result['outcome'] ?? '') === 'fail') {
            return 'The forge cracks. Both pieces are lost.';
        }
        $name = (string) ($result['item']['display_name'] ?? 'a new piece');
        if (($result['outcome'] ?? '') === 'masterpiece') {
            return "The forge sings. You draw {$name}.";
        }

        return "The forge yields {$name}.";
    }

    /** @param array{fail: float, craft: float, masterpiece: float} $weights
     * @return array{fail: float, craft: float, masterpiece: float} percents that add up to 100
     */
    public static function chancePercents(array $weights): array
    {
        $total = $weights['fail'] + $weights['craft'] + $weights['masterpiece'];
        if ($total <= 0) {
            throw new InvalidArgumentException('Forge weights are empty.');
        }
        $fail = round(100 * $weights['fail'] / $total, 1);
        $craft = round(100 * $weights['craft'] / $total, 1);

        return [
            'fail' => $fail,
            'craft' => $craft,
            'masterpiece' => round(100 - $fail - $craft, 1),
        ];
    }

    /** @param list<array> $templates */
    private static function pickTemplate(array $templates, string $slot, int $sourceLevel, array $balance, Rng $rng): array
    {
        $near = (float) $balance['forge']['template_near'];
        $pool = [];
        $fallback = null;
        foreach ($templates as $template) {
            if (($template['slot'] ?? null) !== $slot) {
                continue;
            }
            if ($fallback === null || (int) $template['req_level'] < (int) $fallback['req_level']
                || ((int) $template['req_level'] === (int) $fallback['req_level'] && (int) ($template['id'] ?? 0) < (int) ($fallback['id'] ?? 0))) {
                $fallback = $template;
            }
            if ((int) $template['req_level'] > $sourceLevel) {
                continue;
            }
            $distance = abs((int) $template['req_level'] - $sourceLevel);
            $pool[] = $template + ['weight' => $near / (1 + $distance)];
        }
        if ($pool === []) {
            if ($fallback === null) {
                throw new InvalidArgumentException('No template for that slot.');
            }
            $pool = [$fallback + ['weight' => 1]];
        }
        $picked = Loot::weightedPick($pool, $rng, fn ($row) => $row['weight']);
        if (! is_array($picked)) {
            throw new InvalidArgumentException('No template for that slot.');
        }
        unset($picked['weight']);

        return $picked;
    }

    private static function higherTier(array $left, array $right, array $balance): array
    {
        $index = max(self::tierIndex($left['rarity'], $balance), self::tierIndex($right['rarity'], $balance));

        return $balance['loot']['rarity'][$index];
    }

    private static function tierIndex(string $rarity, array $balance): int
    {
        foreach ($balance['loot']['rarity'] as $index => $tier) {
            if ($tier['name'] === $rarity) {
                return (int) $index;
            }
        }
        throw new InvalidArgumentException("Unknown rarity {$rarity}");
    }
}
