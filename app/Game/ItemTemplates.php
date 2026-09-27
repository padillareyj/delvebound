<?php

namespace App\Game;

/** Authoring rules for `item_templates` (DESIGN.md §2.1, §7.3). Pure: arrays in, arrays out. */
final class ItemTemplates
{
    public const SLOTS = ['weapon', 'offhand', 'head', 'chest', 'hands', 'feet', 'ring', 'amulet'];

    public const ITEM_TYPES = ['sword', 'dagger', 'staff', 'wand', 'bow', 'axe', 'shield', 'cloth', 'leather', 'mail', 'jewelry'];

    public const STAT_KEYS = [
        'str', 'dex', 'int', 'vit', 'luk', 'hp', 'attack', 'armor', 'mres',
        'crit_pct', 'crit_dmg_pct', 'dodge_pct', 'speed', 'gold_find_pct', 'magic_find_pct',
    ];

    public const TYPES_BY_SLOT = [
        'weapon' => ['sword', 'dagger', 'staff', 'wand', 'bow', 'axe'],
        'offhand' => ['shield'],
        'head' => ['cloth', 'leather', 'mail'],
        'chest' => ['cloth', 'leather', 'mail'],
        'hands' => ['cloth', 'leather', 'mail'],
        'feet' => ['cloth', 'leather', 'mail'],
        'ring' => ['jewelry'],
        'amulet' => ['jewelry'],
    ];

    public static function codeFromName(string $name): string
    {
        $code = strtolower(trim($name));
        $code = preg_replace('/[^a-z0-9]+/', '_', $code) ?? '';
        $code = trim($code, '_');

        return substr($code, 0, 40);
    }

    /**
     * Normalize a posted gear form into a template row.
     *
     * @return array{0: bool, 1: ?string, 2: array}
     */
    public static function prepare(array $input): array
    {
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        if ($code === '') {
            $code = self::codeFromName((string) ($input['name'] ?? ''));
        }
        if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $code)) {
            return [false, 'Code must be a lowercase identifier (letters, digits, underscores), starting with a letter.', []];
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || strlen($name) > 64) {
            return [false, 'Name is required (max 64 characters).', []];
        }

        $slot = (string) ($input['slot'] ?? '');
        if (! in_array($slot, self::SLOTS, true)) {
            return [false, 'Pick a valid equipment slot.', []];
        }

        $itemType = (string) ($input['item_type'] ?? '');
        $allowedTypes = self::TYPES_BY_SLOT[$slot];
        if (! in_array($itemType, $allowedTypes, true)) {
            return [false, "A {$slot} uses type: ".implode(', ', $allowedTypes).'.', []];
        }

        $scaling = $input['scaling_stat'] ?? null;
        $scaling = ($scaling === '' || $scaling === null) ? null : (string) $scaling;
        if ($slot === 'weapon') {
            if (! in_array($scaling, ['str', 'dex', 'int'], true)) {
                return [false, 'Weapons must scale with strength, dexterity, or intellect.', []];
            }
        } elseif ($scaling !== null) {
            return [false, 'Only weapons have a scaling stat.', []];
        }

        $twoHanded = ! empty($input['two_handed']);
        if ($twoHanded && $slot !== 'weapon') {
            return [false, 'Only weapons can be two-handed.', []];
        }

        $implicit = self::normalizeImplicit($input['implicit'] ?? []);
        if ($implicit === false) {
            return [false, 'Implicit mods must use the shared stat-key vocabulary (DESIGN.md §2.1).', []];
        }

        $reqLevel = (int) ($input['req_level'] ?? 1);
        $baseValue = (int) ($input['base_value'] ?? 1);
        if ($reqLevel < 1 || $reqLevel > 30) {
            return [false, 'Required level must be 1–30.', []];
        }
        if ($baseValue < 1) {
            return [false, 'Base value must be at least 1 gold.', []];
        }

        $description = trim((string) ($input['description'] ?? ''));
        $icon = trim((string) ($input['icon'] ?? ''));

        return [true, null, [
            'code' => $code,
            'name' => $name,
            'slot' => $slot,
            'item_type' => $itemType,
            'scaling_stat' => $scaling,
            'two_handed' => $twoHanded ? 1 : 0,
            'base_power' => max(0, (int) ($input['base_power'] ?? 0)),
            'base_armor' => max(0, (int) ($input['base_armor'] ?? 0)),
            'base_mres' => max(0, (int) ($input['base_mres'] ?? 0)),
            'implicit_mods' => $implicit === [] ? null : $implicit,
            'req_level' => $reqLevel,
            'base_value' => $baseValue,
            'description' => $description === '' ? null : substr($description, 0, 255),
            'icon' => $icon === '' ? null : substr($icon, 0, 64),
        ]];
    }

    /** @return array<string, float|int>|false */
    private static function normalizeImplicit(mixed $raw): array|false
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return false;
            }
            $raw = $decoded;
        }
        if (! is_array($raw)) {
            return false;
        }

        $out = [];
        $isList = array_is_list($raw);
        foreach ($raw as $key => $value) {
            if ($isList) {
                if (! is_array($value)) {
                    return false;
                }
                $stat = (string) ($value['key'] ?? $value['stat_key'] ?? '');
                $amount = $value['value'] ?? null;
            } else {
                $stat = (string) $key;
                $amount = $value;
            }
            if ($stat === '' || $amount === null || $amount === '') {
                continue;
            }
            if (! in_array($stat, self::STAT_KEYS, true) || ! is_numeric($amount)) {
                return false;
            }
            $out[$stat] = str_contains($stat, 'pct') || str_contains((string) $amount, '.')
                ? (float) $amount
                : (int) $amount;
        }

        return $out;
    }
}
