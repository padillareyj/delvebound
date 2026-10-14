<?php

namespace App\Game;

use RuntimeException;

/**
 * Picks a local painted icon (or an SVG fallback) for gear or a consumable.
 * Pure: arrays in, a filename out. The React UI only displays that file.
 */
final class ItemArt
{
    private static ?array $catalog = null;

    /** @var array<string, true>|null */
    private static ?array $stems = null;

    /** @return array{icon: string, family: string, fallback: bool} */
    public static function resolve(array $item): array
    {
        $explicit = strtolower(trim((string) ($item['icon'] ?? $item['template_icon'] ?? '')));
        if ($explicit !== '' && isset(self::stemSet()[$explicit])) {
            return [
                'icon' => $explicit,
                'family' => self::familyOf($explicit) ?? 'assigned',
                'fallback' => false,
            ];
        }

        $text = self::haystack($item);
        $slot = self::slotOf($item);
        $type = (string) ($item['item_type'] ?? '');
        $two = self::twoHanded($item);
        [$family, $fallback] = self::family($text, $slot, $type, $two);
        $variants = self::catalog()['families'][$family] ?? self::catalog()['families']['bag'];

        return [
            'icon' => self::pick($variants, self::stableKey($item), self::rarityOf($item)),
            'family' => $family,
            'fallback' => $fallback,
        ];
    }

    public static function icon(array $item): string
    {
        return self::resolve($item)['icon'];
    }

    public static function isFallback(array $item): bool
    {
        return self::resolve($item)['fallback'];
    }

    /** Shop potions, tonics, and kits. */
    public static function consumable(array $item): string
    {
        $map = self::catalog()['consumables'];
        $effect = (string) ($item['effect_type'] ?? '');
        $text = self::haystack($item);

        $key = match ($effect) {
            'heal_pct' => self::has($text, 'greater') ? 'heal-greater' : (self::has($text, 'minor') ? 'heal-minor' : 'heal'),
            'buff_attack_pct' => 'might',
            'buff_defense_pct' => 'defense',
            'luck_bonus' => 'luck',
            'flee_bonus' => 'flee',
            'cure_injury' => 'bandage',
            default => 'heal',
        };

        return $map[$key] ?? $map['heal'];
    }

    /** @return array{families: array<string, list<string>>, fallback: array<string, string>, consumables: array<string, string>} */
    public static function catalog(): array
    {
        if (self::$catalog === null) {
            $path = dirname(__DIR__, 2).'/resources/item-art/catalog.json';
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException('Item art catalog not found.');
            }
            self::$catalog = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$catalog;
    }

    /** @return list<string> */
    public static function stems(): array
    {
        return array_keys(self::stemSet());
    }

    /** @return array<string, true> */
    private static function stemSet(): array
    {
        if (self::$stems === null) {
            $stems = [];
            foreach (self::catalog()['families'] as $icons) {
                foreach ($icons as $icon) {
                    $stems[$icon] = true;
                }
            }
            foreach (self::catalog()['consumables'] as $icon) {
                $stems[$icon] = true;
            }
            self::$stems = $stems;
        }

        return self::$stems;
    }

    private static function familyOf(string $stem): ?string
    {
        foreach (self::catalog()['families'] as $family => $icons) {
            if (in_array($stem, $icons, true)) {
                return $family;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: bool} */
    private static function family(string $text, string $slot, string $type, bool $two): array
    {
        $weaponSlot = $slot === 'weapon' || in_array($type, ['sword', 'dagger', 'staff', 'wand', 'bow', 'axe'], true);
        if ($weaponSlot) {
            $shape = self::weaponShape($text, $type, $two);
            if ($shape !== null) {
                return [$shape, false];
            }
        }

        if ($slot === 'offhand' || $type === 'shield') {
            return [self::shieldShape($text), false];
        }

        $armorSlot = in_array($slot, ['head', 'chest', 'hands', 'feet'], true);
        $armorType = in_array($type, ['cloth', 'leather', 'mail'], true);
        if ($armorSlot || $armorType) {
            $material = $armorType ? $type : self::armorMaterial($text);
            $place = $armorSlot ? $slot : self::armorPlace($text);
            if ($material !== null && $place !== null) {
                $family = $material.'-'.$place;
                if (isset(self::catalog()['families'][$family])) {
                    return [$family, false];
                }
            }
            if ($place !== null) {
                return [self::fallbackFamily($place), true];
            }
        }

        if (in_array($slot, ['ring', 'ring1', 'ring2', 'amulet'], true) || $type === 'jewelry') {
            if ($slot === 'amulet' || ($slot === '' && self::has($text, 'amulet', 'pendant', 'torc', 'necklace'))) {
                return ['amulet', false];
            }

            return ['ring', $type !== 'jewelry' && ! in_array($slot, ['ring', 'ring1', 'ring2'], true)];
        }

        return [self::fallbackFamily($slot), true];
    }

    private static function weaponShape(string $text, string $type, bool $two): ?string
    {
        $named = null;
        if (self::has($text, 'crossbow')) {
            $named = 'crossbow';
        } elseif (self::has($text, 'bow', 'shortbow', 'longbow', 'recurve', 'yew')) {
            $named = 'bow';
        } elseif (self::has($text, 'staff', 'crook', 'quarterstaff')) {
            $named = 'staff';
        } elseif (self::has($text, 'wand', 'scepter', 'sceptre', 'rod')) {
            $named = 'wand';
        } elseif (self::has($text, 'dagger', 'kris', 'shiv', 'stiletto', 'knife', 'fang')) {
            $named = 'dagger';
        } elseif (self::has($text, 'spear', 'pike', 'javelin', 'lance', 'halberd', 'trident')) {
            $named = 'spear';
        } elseif (self::has($text, 'maul')) {
            $named = 'maul';
        } elseif (self::has($text, 'mace', 'flail', 'hammer', 'club')) {
            $named = $two ? 'maul' : 'mace';
        } elseif (self::has($text, 'greataxe', 'battleaxe')) {
            $named = 'greataxe';
        } elseif (self::has($text, 'greatsword', 'claymore', 'zweihander')) {
            $named = 'greatsword';
        } elseif (self::has($text, 'cutlass', 'scimitar', 'sabre', 'saber', 'falchion', 'katana')) {
            $named = $two ? 'greatsword' : 'cutlass';
        } elseif (self::has($text, 'axe', 'cleaver', 'hewer', 'hatchet', 'tomahawk')) {
            $named = $two ? 'greataxe' : 'axe';
        } elseif (self::has($text, 'sword', 'blade', 'edge', 'gladius', 'longsword')) {
            $named = $two ? 'greatsword' : 'sword';
        }

        if ($named !== null) {
            return $named;
        }

        return match ($type) {
            'bow' => 'bow',
            'staff' => 'staff',
            'wand' => 'wand',
            'dagger' => 'dagger',
            'axe' => $two ? 'greataxe' : 'axe',
            'sword' => $two ? 'greatsword' : 'sword',
            default => null,
        };
    }

    private static function shieldShape(string $text): string
    {
        if (self::has($text, 'buckler', 'targe', 'round')) {
            return 'buckler';
        }
        if (self::has($text, 'kite')) {
            return 'kite';
        }
        if (self::has($text, 'aegis', 'tower', 'bulwark', 'scutum')) {
            return 'tower';
        }

        return 'kite';
    }

    private static function armorMaterial(string $text): ?string
    {
        if (self::has($text, 'cloth', 'robe', 'tunic', 'slipper', 'slippers')) {
            return 'cloth';
        }
        if (self::has($text, 'leather', 'hide', 'jerkin', 'cap', 'cowl')) {
            return 'leather';
        }
        if (self::has($text, 'mail', 'chainmail', 'hauberk', 'plate', 'cuirass', 'helm', 'helmet', 'gauntlet', 'gauntlets', 'visor')) {
            return 'mail';
        }

        return null;
    }

    private static function armorPlace(string $text): ?string
    {
        if (self::has($text, 'helm', 'helmet', 'cowl', 'cap', 'hood', 'crown', 'visor')) {
            return 'head';
        }
        if (self::has($text, 'glove', 'gloves', 'gauntlet', 'gauntlets', 'grip', 'grips', 'fist', 'fists', 'bracer')) {
            return 'hands';
        }
        if (self::has($text, 'boot', 'boots', 'slipper', 'slippers', 'tread', 'treads', 'strider', 'striders', 'greave', 'greaves')) {
            return 'feet';
        }
        if (self::has($text, 'tunic', 'robe', 'jerkin', 'hauberk', 'plate', 'cuirass', 'vest', 'hide', 'chest')) {
            return 'chest';
        }

        return null;
    }

    private static function fallbackFamily(string $slot): string
    {
        $map = self::catalog()['fallback'];

        return $map[$slot] ?? $map['_default'];
    }

    private static function slotOf(array $item): string
    {
        return (string) ($item['template_slot'] ?? $item['slot'] ?? '');
    }

    private static function twoHanded(array $item): bool
    {
        $flag = $item['two_handed'] ?? false;

        return $flag === true || $flag === 1 || $flag === '1';
    }

    private static function stableKey(array $item): string
    {
        foreach (['code', 'name', 'template_name', 'display_name'] as $key) {
            $value = strtolower(trim((string) ($item[$key] ?? '')));
            if ($value !== '') {
                return $value;
            }
        }

        return self::slotOf($item).'|'.(string) ($item['item_type'] ?? '');
    }

    private static function rarityOf(array $item): string
    {
        $rarity = strtolower(trim((string) ($item['rarity'] ?? 'common')));

        return in_array($rarity, ['common', 'uncommon', 'rare', 'epic', 'legendary'], true) ? $rarity : 'common';
    }

    /**
     * Variants are ordered plain to fancy. Higher rarity shifts the window
     * toward the fancy end. The same code and rarity always pick the same file.
     *
     * @param  list<string>  $variants
     */
    private static function pick(array $variants, string $key, string $rarity = 'common'): string
    {
        $variants = array_values($variants);
        $count = count($variants);
        if ($count === 0) {
            return self::catalog()['families']['bag'][0];
        }
        if ($count === 1) {
            return $variants[0];
        }
        $rank = ['common' => 0, 'uncommon' => 1, 'rare' => 2, 'epic' => 3, 'legendary' => 4][$rarity] ?? 0;
        $span = max(2, (int) ceil($count * 0.55));
        if ($span > $count) {
            $span = $count;
        }
        $startMax = $count - $span;
        $start = $startMax === 0 ? 0 : (int) floor($startMax * ($rank / 4));
        $n = hexdec(substr(hash('sha256', 'delvebound-item-art:'.$key), 0, 8));
        $offset = (int) fmod((float) $n, (float) $span);

        return $variants[$start + $offset];
    }

    private static function haystack(array $item): string
    {
        $text = strtolower(implode(' ', [
            (string) ($item['code'] ?? ''),
            (string) ($item['name'] ?? ''),
            (string) ($item['template_name'] ?? ''),
            (string) ($item['display_name'] ?? ''),
        ]));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;
        $text = str_replace(['great axe', 'great sword'], ['greataxe', 'greatsword'], $text);

        return ' '.trim($text).' ';
    }

    private static function has(string $text, string ...$words): bool
    {
        foreach ($words as $word) {
            if (str_contains($text, ' '.$word.' ')) {
                return true;
            }
        }

        return false;
    }
}
