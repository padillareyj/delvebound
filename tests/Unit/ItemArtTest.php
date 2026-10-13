<?php

namespace Tests\Unit;

use App\Game\ItemArt;

/** Local item art: every seeded template and legendary base maps to a real drawing. */
class ItemArtTest extends GameTestCase
{
    public function test_catalog_matches_the_svg_files_on_disk(): void
    {
        $dir = dirname(__DIR__, 2).'/public/icons/items';
        $files = [];
        foreach (glob($dir.'/*.svg') as $path) {
            $files[] = basename($path, '.svg');
        }
        sort($files);
        $stems = ItemArt::stems();
        sort($stems);
        $this->assertSame($files, $stems, 'Ship only the icons the mapper uses, and ship every icon it can return.');

        foreach ($files as $stem) {
            $svg = file_get_contents($dir.'/'.$stem.'.svg');
            $this->assertStringNotContainsString('<script', strtolower($svg), $stem);
            $this->assertDoesNotMatchRegularExpression('/https?:\/\/(?!www\.w3\.org\/2000\/svg)/', $svg, $stem);
        }
    }

    public function test_weapon_shapes_look_like_the_weapon(): void
    {
        $catalog = ItemArt::catalog();
        $this->assertSame(
            ['lorc-high-shot', 'lorc-pocket-bow', 'delapouite-bow-arrow'],
            $catalog['families']['bow'],
        );
        foreach ($catalog['families']['crossbow'] as $stem) {
            $this->assertStringContainsString('crossbow', $stem);
        }
        foreach ($catalog['families']['greatsword'] as $stem) {
            $this->assertStringContainsString('two-handed', $stem);
        }
        foreach ($catalog['families']['greataxe'] as $stem) {
            $this->assertStringContainsString('axe', $stem);
            $this->assertNotContains($stem, ['delapouite-hatchet', 'lorc-wood-axe', 'lorc-fire-axe']);
        }

        $great = ItemArt::resolve(['code' => 'iron_greatsword', 'name' => 'Iron Greatsword', 'slot' => 'weapon', 'item_type' => 'sword', 'two_handed' => true]);
        $this->assertSame('greatsword', $great['family']);
        $this->assertFalse($great['fallback']);
        $this->assertSame('delapouite-two-handed-sword', $great['icon']);

        $bow = ItemArt::resolve(['code' => 'hunters_shortbow', 'name' => "Hunter's Shortbow", 'slot' => 'weapon', 'item_type' => 'bow', 'two_handed' => true]);
        $this->assertSame('bow', $bow['family']);
        $this->assertContains($bow['icon'], $catalog['families']['bow']);

        $spear = ItemArt::resolve(['code' => 'ash_spear', 'name' => 'Ash Spear', 'slot' => 'weapon', 'item_type' => 'sword', 'two_handed' => true]);
        $this->assertSame('spear', $spear['family']);
        $this->assertContains($spear['icon'], $catalog['families']['spear']);

        $crossbow = ItemArt::resolve(['code' => 'heavy_crossbow', 'name' => 'Heavy Crossbow', 'slot' => 'weapon', 'item_type' => 'bow', 'two_handed' => true]);
        $this->assertSame('crossbow', $crossbow['family']);

        $mace = ItemArt::resolve(['code' => 'war_mace', 'name' => 'War Mace', 'slot' => 'weapon', 'item_type' => 'axe', 'two_handed' => false]);
        $this->assertSame('mace', $mace['family']);

        $cutlass = ItemArt::resolve(['code' => 'tide_cutlass', 'name' => 'Tide Cutlass', 'slot' => 'weapon', 'item_type' => 'sword', 'two_handed' => false]);
        $this->assertSame('cutlass', $cutlass['family']);

        $buckler = ItemArt::resolve(['code' => 'wooden_buckler', 'name' => 'Wooden Buckler', 'slot' => 'offhand', 'item_type' => 'shield']);
        $this->assertSame('buckler', $buckler['family']);
        $kite = ItemArt::resolve(['code' => 'iron_kite_shield', 'name' => 'Iron Kite Shield', 'slot' => 'offhand', 'item_type' => 'shield']);
        $this->assertSame('kite', $kite['family']);
        $aegis = ItemArt::resolve(['code' => 'crown_aegis', 'name' => 'Crown Aegis', 'slot' => 'offhand', 'item_type' => 'shield']);
        $this->assertSame('tower', $aegis['family']);
    }

    public function test_same_template_always_picks_the_same_icon(): void
    {
        $item = ['code' => 'rusty_sword', 'name' => 'Rusty Sword', 'display_name' => 'Sturdy Rusty Sword of the Bear', 'slot' => 'weapon', 'item_type' => 'sword', 'two_handed' => false];
        $otherName = $item;
        $otherName['display_name'] = 'Keen Rusty Sword of the Fox';
        $this->assertSame(ItemArt::icon($item), ItemArt::icon($otherName));
        $this->assertSame(ItemArt::icon($item), ItemArt::icon($item));
    }

    public function test_unknown_gear_falls_back_by_slot(): void
    {
        $weapon = ItemArt::resolve(['slot' => 'weapon']);
        $this->assertTrue($weapon['fallback']);
        $this->assertSame('sword', $weapon['family']);

        $mystery = ItemArt::resolve(['name' => 'Oddment']);
        $this->assertTrue($mystery['fallback']);
        $this->assertSame('bag', $mystery['family']);
        $this->assertSame('lorc-swap-bag', $mystery['icon']);
    }

    public function test_explicit_catalog_icon_is_kept(): void
    {
        $got = ItemArt::resolve([
            'icon' => 'lorc-wizard-staff',
            'name' => 'Rusty Sword',
            'slot' => 'weapon',
            'item_type' => 'sword',
        ]);
        $this->assertSame('lorc-wizard-staff', $got['icon']);
        $this->assertFalse($got['fallback']);

        $ignored = ItemArt::resolve([
            'icon' => '../etc/passwd',
            'name' => 'Rusty Sword',
            'slot' => 'weapon',
            'item_type' => 'sword',
        ]);
        $this->assertSame('sword', $ignored['family']);
        $this->assertDoesNotMatchRegularExpression('/[^a-z0-9-]/', $ignored['icon']);
    }

    public function test_every_seeded_template_and_base_resolves(): void
    {
        $catalog = ItemArt::catalog();
        $templates = $this->seedTemplates();
        $this->assertGreaterThanOrEqual(23, count($templates));

        foreach ($templates as $item) {
            $got = ItemArt::resolve($item);
            $this->assertFalse($got['fallback'], $item['code'].' should not use the slot fallback');
            $this->assertContains($got['icon'], $catalog['families'][$got['family']], $item['code']);
            $this->assertContains($got['family'], $this->allowedFamilies($item), $item['code'].' landed in '.$got['family']);
        }

        foreach ($this->seedNames() as $item) {
            $got = ItemArt::resolve($item);
            $this->assertFalse($got['fallback'], $item['code'].' ('.$item['name'].') should not use the slot fallback');
            $this->assertContains($got['family'], $this->allowedFamilies($item), $item['code'].' landed in '.$got['family']);
        }
    }

    public function test_seeded_consumables_have_icons(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2).'/database/seeders/sql/015_shop.sql');
        preg_match_all(
            "/\('([a-z0-9_]+)', '((?:[^']|'')*)', '([a-z_]+)'/",
            $sql,
            $matches,
            PREG_SET_ORDER,
        );
        $this->assertNotEmpty($matches);
        foreach ($matches as $row) {
            $icon = ItemArt::consumable([
                'code' => $row[1],
                'name' => str_replace("''", "'", $row[2]),
                'effect_type' => $row[3],
            ]);
            $this->assertContains($icon, ItemArt::stems(), $row[1]);
            $this->assertNotSame('lorc-swap-bag', $icon, $row[1]);
        }
    }

    /** @return list<array{code: string, name: string, slot: string, item_type: string, two_handed: bool}> */
    private function seedTemplates(): array
    {
        $items = [];
        $early = file_get_contents(dirname(__DIR__, 2).'/database/seeders/sql/010_content.sql');
        preg_match_all(
            "/\('([a-z0-9_]+)', '((?:[^']|'')*)', '(weapon|offhand|head|chest|hands|feet|ring|amulet)', '([a-z]+)', (?:NULL|'(?:str|dex|int)'), ([01]),/",
            $early,
            $rows,
            PREG_SET_ORDER,
        );
        foreach ($rows as $row) {
            $items[] = $this->template($row[1], $row[2], $row[3], $row[4], $row[5] === '1');
        }

        $later = file_get_contents(dirname(__DIR__, 2).'/database/seeders/sql/012_later_maps.sql');
        preg_match_all(
            "/SELECT '([a-z0-9_]+)' AS code, '((?:[^']|'')*)' AS name, '(weapon|offhand|head|chest|hands|feet|ring|amulet)' AS slot, '([a-z]+)' AS item_type, (?:NULL|'(?:str|dex|int)') AS scaling_stat, ([01]) AS two_handed/",
            $later,
            $rows,
            PREG_SET_ORDER,
        );
        foreach ($rows as $row) {
            $items[] = $this->template($row[1], $row[2], $row[3], $row[4], $row[5] === '1');
        }

        return $items;
    }

    /** @return list<array{code: string, name: string, slot: string, item_type: string, two_handed: bool}> */
    private function seedNames(): array
    {
        $sql = file_get_contents(dirname(__DIR__, 2).'/database/seeders/sql/040_legendary_names.sql');
        preg_match_all(
            "/SELECT '([a-z0-9_]+)' AS code, '(base|unique)' AS kind, '((?:[^']|'')*)' AS name, '([a-z]+)' AS item_type, '(weapon|offhand|head|chest|hands|feet|ring|amulet)' AS slot/",
            $sql,
            $rows,
            PREG_SET_ORDER,
        );
        $items = [];
        foreach ($rows as $row) {
            $two = in_array($row[4], ['staff', 'bow'], true);
            $items[] = $this->template($row[1], $row[3], $row[5], $row[4], $two);
        }

        return $items;
    }

    /** @return array{code: string, name: string, slot: string, item_type: string, two_handed: bool} */
    private function template(string $code, string $name, string $slot, string $type, bool $two): array
    {
        return [
            'code' => $code,
            'name' => str_replace("''", "'", $name),
            'slot' => $slot,
            'item_type' => $type,
            'two_handed' => $two,
        ];
    }

    /** @param  array{item_type: string, slot: string, two_handed: bool, name: string}  $item */
    private function allowedFamilies(array $item): array
    {
        $type = $item['item_type'];
        $slot = $item['slot'];
        if ($type === 'sword') {
            return $item['two_handed'] ? ['greatsword', 'spear', 'mace'] : ['sword', 'cutlass', 'spear', 'mace'];
        }
        if ($type === 'axe') {
            return $item['two_handed'] ? ['greataxe', 'mace', 'spear'] : ['axe', 'mace', 'greataxe'];
        }
        if ($type === 'bow') {
            return ['bow', 'crossbow'];
        }
        if (in_array($type, ['staff', 'wand', 'dagger'], true)) {
            return [$type];
        }
        if ($type === 'shield') {
            return ['buckler', 'kite', 'tower'];
        }
        if (in_array($type, ['cloth', 'leather', 'mail'], true)) {
            return [$type.'-'.$slot];
        }
        if ($type === 'jewelry') {
            return [$slot === 'amulet' ? 'amulet' : 'ring'];
        }

        return [];
    }
}
