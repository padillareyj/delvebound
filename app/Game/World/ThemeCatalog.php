<?php

namespace App\Game\World;

/** Name pools for generated regions. One theme per map; places and creatures speak that theme. */
final class ThemeCatalog
{
    /**
     * Place-name patterns, keyed by place_type. {adj} and {noun} come from the theme.
     *
     * @var array<string, list<string>>
     */
    public const PLACE_NAMES = [
        'coast' => ['{adj} Strand', '{noun} Shoals', 'Shore of the {noun}'],
        'mountain' => ['{noun} Climb', '{adj} Ridge', 'Steps of the {noun}'],
        'ruins' => ['{adj} Ruin', 'Ruin of the {noun}', '{noun} Wreck'],
        'temple' => ['Shrine of the {noun}', '{adj} Chantry', 'Temple of the {adj}'],
        'dungeon' => ['The {noun} Hold', '{adj} Vault', 'Dungeon of the {noun}'],
        'marsh' => ['{adj} Fen', '{noun} Mire', 'The {noun} Bog'],
        'forest' => ['{adj} Wood', '{noun} Thicket', 'Grove of the {noun}'],
        'cave' => ['{noun} Hollow', '{adj} Cave', 'Mouth of the {noun}'],
        'mine' => ['{noun} Dig', '{adj} Mine', 'The {noun} Seam'],
    ];

    /**
     * @return list<array{code: string, glyph: string, mood: string, boss_magic: bool, adjectives: list<string>, nouns: list<string>, places: list<string>, skirmishers: list<string>, brutes: list<string>, casters: list<string>, bosses: list<string>}>
     */
    public static function all(): array
    {
        return [
            self::theme('gale', '⚡', 'Wind cuts the rock and does not apologize.', false,
                ['Howling', 'Pale', 'Keen', 'Broken'], ['Gale', 'Squall', 'Stormwall', 'Headwind'],
                ['coast', 'mountain', 'ruins', 'temple', 'dungeon'],
                ['Skua', 'Lancer', 'Crier', 'Dart', 'Scree'],
                ['Hulk', 'Ram', 'Bulk', 'Titan', 'Colossus'],
                ['Chanter', 'Witch', 'Oracle', 'Seer', 'Canticle'],
                ['Warden', 'Matron', 'Captain', 'Keeper', 'Regent']),
            self::theme('gilt', '🪙', 'Gold that spends the people who touch it.', false,
                ['Gilded', 'Bright', 'Hungry', 'Silent'], ['Vault', 'Coin', 'Aurum', 'Miser'],
                ['ruins', 'mine', 'temple', 'cave', 'dungeon'],
                ['Auditor', 'Clerk', 'Picker', 'Scribe', 'Counter'],
                ['Hoarder', 'Bullion', 'Strongbox', 'Ingot', 'Coffer'],
                ['Minter', 'Assayer', 'Ledger', 'Tally', 'Usurer'],
                ['Treasurer', 'Magnate', 'Banker', 'Exchequer', 'Paymaster']),
            self::theme('mycel', '🍄', 'The ground is a mouth, and it is patient.', true,
                ['Pale', 'Soft', 'Deep', 'Quiet'], ['Spore', 'Mycel', 'Cap', 'Hypha'],
                ['marsh', 'forest', 'cave', 'ruins', 'dungeon'],
                ['Puff', 'Sprout', 'Drifter', 'Tendril', 'Motile'],
                ['Capbeast', 'Truffle', 'Bolete', 'Stalk', 'Toad'],
                ['Chorister', 'Bloom', 'Sporer', 'Hymn', 'Pollen'],
                ['Fruiting', 'Mothercap', 'Host', 'Colony', 'Bloomking']),
            self::theme('void', '🌑', 'Light arrives late, and leaves early.', true,
                ['Starless', 'Hollow', 'Thin', 'Dim'], ['Void', 'Rift', 'Umbral', 'Nightwell'],
                ['cave', 'ruins', 'mountain', 'temple', 'dungeon'],
                ['Mote', 'Flicker', 'Gutter', 'Shard', 'Wisp'],
                ['Absence', 'Null', 'Hush', 'Blank', 'Pit'],
                ['Eclipse', 'Whisper', 'Lens', 'Umbra', 'Veil'],
                ['Astronomer', 'Unmaker', 'Witness', 'Threshold', 'Nightwarden']),
            self::theme('coil', '🐍', 'The path bends the way a snake does.', false,
                ['Coiled', 'Venom', 'Scaled', 'Low'], ['Serpent', 'Fang', 'Adder', 'Coil'],
                ['marsh', 'cave', 'ruins', 'temple', 'dungeon'],
                ['Asp', 'Darter', 'Krait', 'Whip', 'Flick'],
                ['Constrictor', 'Boa', 'Python', 'Anaconda', 'Crush'],
                ['Hood', 'Spitter', 'Charm', 'Hiss', 'Venomer'],
                ['Basilisk', 'Naga', 'Ophidian', 'Coillord', 'Kingfang']),
            self::theme('brass', '⚙️', 'Something down here is still keeping time.', false,
                ['Brass', 'Clocked', 'Oiled', 'Loud'], ['Foundry', 'Gear', 'Piston', 'Anvil'],
                ['mine', 'ruins', 'temple', 'mountain', 'dungeon'],
                ['Cog', 'Ratchet', 'Spring', 'Tick', 'Pinion'],
                ['Hammer', 'Press', 'Ramblock', 'Ingot', 'Flywheel'],
                ['Bell', 'Caliper', 'Chorus', 'Meter', 'Chime'],
                ['Horologist', 'Foreman', 'Engine', 'Mainspring', 'Clocklord']),
            self::theme('dune', '🏜️', 'The sand remembers every foot, then covers it.', false,
                ['Dry', 'Buried', 'Glass', 'Wandering'], ['Dune', 'Mirage', 'Sirocco', 'Kiln'],
                ['cave', 'ruins', 'mountain', 'temple', 'dungeon'],
                ['Skimmer', 'Hopper', 'Dust', 'Scarab', 'Runner'],
                ['Barrow', 'Dunehulk', 'Slab', 'Burial', 'Monolith'],
                ['Mirage', 'Simoom', 'Glasswitch', 'Shimmer', 'Heat'],
                ['Sandking', 'Qanat', 'Sirocco', 'Dunelord', 'Kilnkeeper']),
            self::theme('crimson', '🩸', 'The stone is warm because something under it is not dead.', true,
                ['Crimson', 'Red', 'Warm', 'Scarred'], ['Marrow', 'Vein', 'Heart', 'Scar'],
                ['forest', 'cave', 'ruins', 'temple', 'dungeon'],
                ['Leech', 'Tick', 'Bleeder', 'Pulse', 'Ichor'],
                ['Clot', 'Heartbulk', 'Veinbrute', 'Scarhide', 'Marrow'],
                ['Chorister', 'Pulsewitch', 'Sang', 'Beat', 'Cantor'],
                ['Hemomancer', 'Heartlord', 'Veinregent', 'Scarking', 'Marrowqueen']),
        ];
    }

    /**
     * @param  list<string>  $adjectives
     * @param  list<string>  $nouns
     * @param  list<string>  $places
     * @param  list<string>  $skirmishers
     * @param  list<string>  $brutes
     * @param  list<string>  $casters
     * @param  list<string>  $bosses
     * @return array{code: string, glyph: string, mood: string, boss_magic: bool, adjectives: list<string>, nouns: list<string>, places: list<string>, skirmishers: list<string>, brutes: list<string>, casters: list<string>, bosses: list<string>}
     */
    private static function theme(
        string $code,
        string $glyph,
        string $mood,
        bool $bossMagic,
        array $adjectives,
        array $nouns,
        array $places,
        array $skirmishers,
        array $brutes,
        array $casters,
        array $bosses,
    ): array {
        return [
            'code' => $code,
            'glyph' => $glyph,
            'mood' => $mood,
            'boss_magic' => $bossMagic,
            'adjectives' => $adjectives,
            'nouns' => $nouns,
            'places' => $places,
            'skirmishers' => $skirmishers,
            'brutes' => $brutes,
            'casters' => $casters,
            'bosses' => $bosses,
        ];
    }
}
