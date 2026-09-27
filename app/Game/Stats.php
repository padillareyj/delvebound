<?php

namespace App\Game;

/** Derived stats (DESIGN.md §3.2). Used by the character sheet, the bots, and combat. */
final class Stats
{
    public const PRIMARY = ['str', 'dex', 'int', 'vit', 'luk'];

    public static function derived(array $stats, int $level, array $equipment, ?array $balance = null, int $injury = 0, array $buffs = []): array
    {
        $balance ??= Balance::get();
        $d = $balance['derived'];
        $equipment = array_values(array_filter($equipment));

        $primary = [];
        foreach (self::PRIMARY as $key) {
            $primary[$key] = (float) $stats[$key] + self::gearSum($equipment, $key);
        }

        $weapon = null;
        foreach ($equipment as $item) {
            if (self::isWeapon($item)) {
                $weapon = $item;
                break;
            }
        }
        if ($weapon === null) {
            $power = (float) $d['unarmed_power'];
            $scaling = 'str';
        } else {
            $power = (float) ($weapon['power'] ?? $weapon['base_power'] ?? 0);
            $scaling = ($weapon['scaling_stat'] ?? null) ?: 'str';
        }
        $damageType = $scaling === 'int' ? 'magic' : 'physical';

        ['str' => $str, 'dex' => $dex, 'int' => $int, 'vit' => $vit, 'luk' => $luk] = $primary;
        $off = (float) $d['attack_offstat_div'];
        $attack = match ($scaling) {
            'dex' => $power + $dex + floor($str / $off),
            'int' => $power + $int + floor($luk / $off),
            default => $power + $str + floor($dex / $off),
        } + self::gearSum($equipment, 'attack');

        $armor = 0.0;
        $mres = 0.0;
        foreach ($equipment as $item) {
            $armor += (float) ($item['armor'] ?? $item['base_armor'] ?? 0);
            $mres += (float) ($item['mres'] ?? $item['base_mres'] ?? 0);
        }
        $defense = $armor + floor($vit / $d['def_vit_div']) + floor($str / $d['def_str_div']) + self::gearSum($equipment, 'armor');
        $magicRes = $mres + floor($int / $d['mres_int_div']) + floor($vit / $d['mres_vit_div']) + self::gearSum($equipment, 'mres');
        $hp = $d['hp_base'] + $d['hp_per_vit'] * $vit + $d['hp_per_level'] * $level + self::gearSum($equipment, 'hp');
        $crit = $d['crit_base'] + $d['crit_per_dex'] * $dex + $d['crit_per_luk'] * $luk + self::gearSum($equipment, 'crit_pct');
        $critMult = $d['crit_mult_base'] + self::gearSum($equipment, 'crit_dmg_pct') / 100;
        $dodge = $d['dodge_base'] + $d['dodge_per_dex'] * $dex + $d['dodge_per_luk'] * $luk + self::gearSum($equipment, 'dodge_pct');
        $speed = $d['speed_base'] + $dex + self::gearSum($equipment, 'speed');
        $regen = $d['regen_base'] + $d['regen_per_vit'] * $vit;
        $luck = $d['luck_per_luk'] * $luk + self::gearSum($equipment, 'magic_find_pct') / 100;
        $goldFind = $d['gold_find_base'] + $d['gold_find_per_luk'] * $luk + self::gearSum($equipment, 'gold_find_pct') / 100;

        $crit = min((float) $crit, (float) $d['crit_cap']);
        $critMult = min((float) $critMult, (float) $d['crit_mult_cap']);
        $dodge = min((float) $dodge, (float) $d['dodge_cap']);
        $regen = min((float) $regen, (float) $d['regen_cap']);
        $luck = min((float) $luck, (float) $d['luck_cap']);
        $goldFind = min((float) $goldFind, (float) $d['gold_find_cap']);

        if ($injury >= 1) {
            $hp = Numbers::halfUp($hp * $balance['injury']['wounded_hp_mult']);
            $attack = Numbers::halfUp($attack * $balance['injury']['wounded_attack_mult']);
        }
        if (in_array('might', $buffs, true)) {
            $attack = Numbers::halfUp($attack * $balance['buffs']['might_attack_mult']);
        }
        if (in_array('stoneskin', $buffs, true)) {
            $defense = Numbers::halfUp($defense * $balance['buffs']['stoneskin_mult']);
            $magicRes = Numbers::halfUp($magicRes * $balance['buffs']['stoneskin_mult']);
        }
        if (in_array('luck', $buffs, true)) {
            $luck = min((float) $d['luck_cap'], $luck + $balance['buffs']['lucky_charm_luck']);
        }

        return [
            'hp' => (int) $hp,
            'attack' => (int) $attack,
            'defense' => (int) $defense,
            'mres' => (int) $magicRes,
            'crit' => $crit,
            'crit_mult' => $critMult,
            'dodge' => $dodge,
            'speed' => (int) $speed,
            'regen' => $regen,
            'luck' => $luck,
            'gold_find' => $goldFind,
            'damage_type' => $damageType,
            'str' => (int) $str,
            'dex' => (int) $dex,
            'int' => (int) $int,
            'vit' => (int) $vit,
            'luk' => (int) $luk,
        ];
    }

    public static function xpToNext(int $level, ?array $balance = null): int
    {
        return Progression::xpToNext($level, $balance ?? Balance::get());
    }

    public static function pointPool(int $level, ?array $balance = null): int
    {
        return Progression::pointPool($level, $balance ?? Balance::get());
    }

    /** @return array<string, mixed> implicit mods of an item, whether stored decoded or as JSON text */
    public static function implicit(array $item): array
    {
        $raw = $item['implicit'] ?? $item['implicit_mods'] ?? [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }

        return (array) $raw;
    }

    private static function gearSum(array $equipment, string $key): float
    {
        $total = 0.0;
        foreach ($equipment as $item) {
            $implicit = self::implicit($item);
            if (isset($implicit[$key])) {
                $total += (float) $implicit[$key];
            }
            foreach ($item['affixes'] ?? [] as $affix) {
                if (($affix['stat_key'] ?? null) === $key) {
                    $total += (float) $affix['value'];
                }
            }
        }

        return $total;
    }

    private static function isWeapon(array $item): bool
    {
        if (($item['equip_slot'] ?? null) === 'weapon') {
            return true;
        }

        return ($item['template_slot'] ?? $item['slot'] ?? null) === 'weapon';
    }
}
