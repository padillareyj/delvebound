<?php

namespace App\Game;

/** Shop prices and respec cost (DESIGN.md §7.7). */
final class ShopMath
{
    public static function gearPrice(int $baseValue, int $reqLevel, ?array $balance = null): int
    {
        $shop = ($balance ?? Balance::get())['shop'];
        $raw = $baseValue * $shop['price_value_mult'] * (1 + $shop['price_level_factor'] * $reqLevel);

        return max((int) $shop['price_min'], Numbers::roundTo($raw, (int) $shop['price_round_to']));
    }

    public static function sellValue(int $baseValue, float $valueMult, int $ilvl, ?array $balance = null): int
    {
        return Loot::sellValue($baseValue, $valueMult, $ilvl, $balance ?? Balance::get());
    }

    public static function respecCost(int $level, ?array $balance = null): int
    {
        return (int) ($balance ?? Balance::get())['shop']['respec_gold_per_level'] * $level;
    }

    /** A shop-bought (or starter) item: common, item level = required level, no affixes. */
    public static function shopGearInstance(array $template, ?array $balance = null): array
    {
        $balance ??= Balance::get();

        return Loot::buildInstance($template, (int) $template['req_level'], Loot::tierByName($balance, 'common'), [], 'shop', $balance);
    }
}
