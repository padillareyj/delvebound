<?php

namespace App\Game;

/** Level gates and equip legality (DESIGN.md §4.0, §11.1). The only place these rules live. */
final class Rules
{
    public const SLOT_GROUP = [
        'weapon' => 'weapon',
        'offhand' => 'offhand',
        'head' => 'armor',
        'chest' => 'armor',
        'hands' => 'armor',
        'feet' => 'armor',
        'ring' => 'jewelry',
        'amulet' => 'jewelry',
    ];

    public const EQUIP_SLOTS = ['weapon', 'offhand', 'head', 'chest', 'hands', 'feet', 'ring1', 'ring2', 'amulet'];

    /** @return array{0: bool, 1: ?string} [ok, reason] where reason is inactive | too_low. max_level is not a gate (DESIGN.md §4.0). */
    public static function canEnter(int $level, array $place): array
    {
        $active = ! array_key_exists('is_active', $place) || (bool) $place['is_active'];
        if (! $active) {
            return [false, 'inactive'];
        }
        if (($place['place_type'] ?? '') === 'rest') {
            return [true, null];
        }
        if ($level < (int) $place['min_level']) {
            return [false, 'too_low'];
        }

        return [true, null];
    }

    public static function rejectMessage(int $level, array $place, ?string $reason): string
    {
        return match ($reason) {
            'too_low' => 'Requires level '.(int) $place['min_level'].'.',
            'too_high' => "You've outgrown {$place['name']} (max level ".(int) $place['max_level'].').',
            'inactive' => 'That place is not available.',
            default => "You can't go there.",
        };
    }

    public static function templateSlot(array $item): string
    {
        return $item['template_slot'] ?? $item['slot'];
    }

    /**
     * @return array{0: bool, 1: ?string} [ok, note]; note is level | slot | two_handed when refused, and
     *                                     clears_offhand when a two-handed weapon is legal but displaces a shield
     */
    public static function canEquip(array $item, string $equipSlot, int $level, array $loadout): array
    {
        if ((int) $item['req_level'] > $level) {
            return [false, 'level'];
        }
        $slot = self::templateSlot($item);
        if ($slot === 'ring') {
            if (! in_array($equipSlot, ['ring1', 'ring2'], true)) {
                return [false, 'slot'];
            }
        } elseif ($slot !== $equipSlot) {
            return [false, 'slot'];
        }
        $weapon = $loadout['weapon'] ?? null;
        if ($equipSlot === 'offhand' && $weapon && ! empty($weapon['two_handed'])) {
            return [false, 'two_handed'];
        }
        if ($equipSlot === 'weapon' && ! empty($item['two_handed']) && ! empty($loadout['offhand'])) {
            return [true, 'clears_offhand'];
        }

        return [true, null];
    }

    public static function equipMessage(?string $note): string
    {
        return match ($note) {
            'level' => 'Your level is too low for that item.',
            'slot' => "That item doesn't fit that slot.",
            'two_handed' => 'You cannot use an offhand with a two-handed weapon.',
            default => "You can't equip that.",
        };
    }

    /** Label used on the map and in "places open to you" lists. */
    public static function placeLabel(array $place): string
    {
        if (($place['place_type'] ?? '') === 'rest') {
            return $place['name'];
        }
        return "{$place['name']} (Lv {$place['rec_min_level']}–{$place['rec_max_level']})";
    }

    /** @return list<string> */
    public static function openPlaceNames(int $level, array $places): array
    {
        $names = [];
        foreach ($places as $place) {
            if (self::canEnter($level, $place)[0]) {
                $names[] = self::placeLabel($place);
            }
        }

        return $names;
    }
}
