<?php

namespace App\Services;

/**
 * The cosmetic hero look stored in characters.look (DESIGN.md §2.3 "Hero look"). It only tells the website
 * how to draw the 3D hero; stats, combat, bots, and the simulation never read it.
 */
final class HeroLook
{
    public const GENDERS = ['male', 'female'];

    public const RACES = ['human', 'elf', 'dwarf', 'orc', 'undead'];

    public const HAIR = ['none', 'simple_parted', 'buzzed', 'buzzed_female', 'long', 'buns'];

    public const OUTFITS = ['peasant', 'ranger', 'ranger_hooded'];

    public const HEIGHT = [90, 110];

    public const BUILD = [85, 120];

    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'gender' => ['required', 'in:'.implode(',', self::GENDERS)],
            'race' => ['required', 'in:'.implode(',', self::RACES)],
            'skin' => ['required', 'string', 'regex:'.self::HEX],
            'hair' => ['required', 'in:'.implode(',', self::HAIR)],
            'hair_color' => ['nullable', 'string', 'regex:'.self::HEX],
            'beard' => ['required', 'boolean'],
            'outfit' => ['required', 'in:'.implode(',', self::OUTFITS)],
            'height' => ['required', 'integer', 'between:'.self::HEIGHT[0].','.self::HEIGHT[1]],
            'build' => ['required', 'integer', 'between:'.self::BUILD[0].','.self::BUILD[1]],
        ];
    }

    /** Canonical shape from validated input (female heroes have no beard). */
    public static function normalize(array $data): array
    {
        $gender = (string) $data['gender'];

        return [
            'gender' => $gender,
            'race' => (string) $data['race'],
            'skin' => strtolower((string) $data['skin']),
            'hair' => (string) $data['hair'],
            'hair_color' => isset($data['hair_color']) && $data['hair_color'] !== '' ? strtolower((string) $data['hair_color']) : null,
            'beard' => $gender === 'male' && filter_var($data['beard'], FILTER_VALIDATE_BOOLEAN),
            'outfit' => (string) $data['outfit'],
            'height' => (int) $data['height'],
            'build' => (int) $data['build'],
        ];
    }

    /** The weapon-based look heroes had before looks existed (keyed by the weapon's art family). */
    public static function presetForFamily(?string $family): array
    {
        $base = ['race' => 'human', 'skin' => '#ffffff', 'hair_color' => null, 'height' => 100, 'build' => 100];

        return match ($family) {
            'staff', 'wand' => $base + ['gender' => 'female', 'outfit' => 'peasant', 'hair' => 'long', 'beard' => false],
            'bow', 'dagger' => $base + ['gender' => 'male', 'outfit' => 'ranger_hooded', 'hair' => 'buzzed', 'beard' => false],
            'crossbow', 'spear' => $base + ['gender' => 'female', 'outfit' => 'ranger_hooded', 'hair' => 'buzzed_female', 'beard' => false],
            default => $base + ['gender' => 'male', 'outfit' => 'peasant', 'hair' => 'simple_parted', 'beard' => true],
        };
    }

    /** Stored JSON to a safe array, or null when unset or unreadable (the page then uses the weapon look). */
    public static function decode(mixed $json): ?array
    {
        if (! is_string($json) || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }
        $validator = validator($data, self::rules());

        return $validator->fails() ? null : self::normalize($data);
    }
}
