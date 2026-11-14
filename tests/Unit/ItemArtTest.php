<?php

namespace Tests\Unit;

use App\Game\ItemArt;

/** Local item art: every seeded template and legendary base maps to a real drawing. */
class ItemArtTest extends GameTestCase
{
    public function test_catalog_matches_the_files_on_disk(): void
    {
        $dir = dirname(__DIR__, 2).'/public/item-art';
        $files = [];
        foreach (array_merge(glob($dir.'/*.webp') ?: [], glob($dir.'/*.svg') ?: []) as $path) {
            $files[] = basename($path);
        }
        sort($files);
        $stems = ItemArt::stems();
        sort($stems);
        $this->assertSame($files, $stems, 'Ship only the icons the mapper uses, and ship every icon it can return.');
        $this->assertContains('lorc-dust-cloud.svg', $files);
        $this->assertContains('lorc-bandage-roll.svg', $files);

        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            if (str_ends_with($file, '.svg')) {
                $svg = strtolower((string) file_get_contents($path));
                $this->assertStringNotContainsString('<script', $svg, $file);
                $this->assertDoesNotMatchRegularExpression('/https?:\/\/(?!www\.w3\.org\/2000\/svg)/', $svg, $file);
                continue;
            }
            $size = getimagesize($path);
            $this->assertIsArray($size, $file);
            $this->assertSame(128, $size[0], $file);
            $this->assertSame(128, $size[1], $file);
        }
    }

    public function test_each_class_has_several_distinct_icons(): void
    {
        $mins = [
            'sword' => 4, 'greatsword' => 2, 'axe' => 4, 'greataxe' => 3,
            'mace' => 3, 'maul' => 2, 'dagger' => 3, 'spear' => 3,
            'staff' => 3, 'wand' => 3, 'bow' => 4, 'crossbow' => 3,
            'buckler' => 2, 'kite' => 3, 'tower' => 2,
            'mail-head' => 3, 'mail-chest' => 3, 'mail-hands' => 3, 'mail-feet' => 3,
            'leather-head' => 2, 'leather-chest' => 2, 'leather-hands' => 2, 'leather-feet' => 2,
            'cloth-head' => 1, 'cloth-chest' => 2, 'cloth-hands' => 2, 'cloth-feet' => 2,
            'ring' => 3, 'amulet' => 3,
        ];
        $families = ItemArt::catalog()['families'];
        foreach ($mins as $family => $min) {
            $icons = array_values(array_unique($families[$family]));
            $this->assertGreaterThanOrEqual($min, count($icons), $family);
            $this->assertSame($icons, $families[$family], $family.' lists each file once');
        }
        $this->assertSame(
            ['flare-zweihander.webp', 'osare-greatsword.webp'],
            $families['greatsword'],
        );
        foreach ($families['crossbow'] as $file) {
            $this->assertStringContainsString('xbow', $file);
        }
    }

    public function test_different_codes_spread_across_a_family(): void
    {
        $icons = [];
        for ($i = 0; $i < 12; $i++) {
            $icons[] = ItemArt::icon([
                'code' => 'bow_'.$i,
                'name' => 'Yew Bow',
                'slot' => 'weapon',
                'item_type' => 'bow',
                'two_handed' => true,
            ]);
        }
        $this->assertGreaterThanOrEqual(4, count(array_unique($icons)));
    }

    public function test_higher_rarity_leans_fancier_without_changing_the_code(): void
    {
        $item = ['code' => 'rusty_sword', 'name' => 'Rusty Sword', 'slot' => 'weapon', 'item_type' => 'sword', 'two_handed' => false];
        $common = ItemArt::icon($item);
        $legendaryItem = $item;
        $legendaryItem['rarity'] = 'legendary';
        $legendary = ItemArt::icon($legendaryItem);
        $list = ItemArt::catalog()['families']['sword'];
        $this->assertGreaterThanOrEqual(
            array_search($common, $list, true),
            array_search($legendary, $list, true),
        );
        $legendaryItem['display_name'] = 'Excalibur';
        $this->assertSame($legendary, ItemArt::icon($legendaryItem));
    }

    public function test_weapon_shapes_look_like_the_weapon(): void
    {
        $catalog = ItemArt::catalog();

        $great = ItemArt::resolve(['code' => 'iron_greatsword', 'name' => 'Iron Greatsword', 'slot' => 'weapon', 'item_type' => 'sword', 'two_handed' => true]);
        $this->assertSame('greatsword', $great['family']);
        $this->assertFalse($great['fallback']);
        $this->assertContains($great['icon'], $catalog['families']['greatsword']);

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

        $maul = ItemArt::resolve(['code' => 'oak_maul', 'name' => 'Oak Maul', 'slot' => 'weapon', 'item_type' => 'axe', 'two_handed' => true]);
        $this->assertSame('maul', $maul['family']);
        $hammer = ItemArt::resolve(['code' => 'war_hammer', 'name' => 'War Hammer', 'slot' => 'weapon', 'item_type' => 'axe', 'two_handed' => true]);
        $this->assertSame('maul', $hammer['family']);

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
        $this->assertSame('raven-backpack.webp', $mystery['icon']);
    }

    public function test_explicit_catalog_icon_is_kept(): void
    {
        $got = ItemArt::resolve([
            'icon' => 'osare-greatstaff.webp',
            'name' => 'Rusty Sword',
            'slot' => 'weapon',
            'item_type' => 'sword',
        ]);
        $this->assertSame('osare-greatstaff.webp', $got['icon']);
        $this->assertFalse($got['fallback']);

        $ignored = ItemArt::resolve([
            'icon' => '../etc/passwd',
            'name' => 'Rusty Sword',
            'slot' => 'weapon',
            'item_type' => 'sword',
        ]);
        $this->assertSame('sword', $ignored['family']);
        $this->assertDoesNotMatchRegularExpression('/[^a-z0-9.-]/', $ignored['icon']);
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
            $this->assertNotSame('raven-backpack.webp', $icon, $row[1]);
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
            return $item['two_handed'] ? ['greatsword', 'spear', 'maul', 'greataxe'] : ['sword', 'cutlass', 'spear', 'mace'];
        }
        if ($type === 'axe') {
            return $item['two_handed'] ? ['greataxe', 'maul', 'spear'] : ['axe', 'mace', 'greataxe'];
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
