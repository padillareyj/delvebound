<?php

namespace Tests\Feature;

use App\Game\ShopMath;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminGearTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetGame();
    }

    public function test_admin_can_create_edit_and_delete_gear(): void
    {
        [$admin] = $this->makePlayer('rey');
        $forest = (int) DB::table('loot_tables')->where('code', 'lt_forest')->value('id');

        $this->actingAs($admin)->post('/admin/gear', [
            'code' => 'ash_spear',
            'name' => 'Ash Spear',
            'slot' => 'weapon',
            'item_type' => 'sword',
            'scaling_stat' => 'str',
            'two_handed' => true,
            'base_power' => 7,
            'base_armor' => 0,
            'base_mres' => 0,
            'req_level' => 2,
            'base_value' => 12,
            'description' => 'A long ash shaft.',
            'implicit' => [['key' => 'speed', 'value' => 1]],
            'loot' => [['loot_table_id' => $forest, 'weight' => 8]],
            'in_shop' => true,
        ])->assertRedirect();

        $row = (array) DB::table('item_templates')->where('code', 'ash_spear')->first();
        $this->assertSame('Ash Spear', $row['name']);
        $this->assertSame(7, (int) $row['base_power']);
        $this->assertSame(1, (int) $row['two_handed']);
        $implicit = is_string($row['implicit_mods']) ? json_decode($row['implicit_mods'], true) : $row['implicit_mods'];
        $this->assertSame(['speed' => 1], $implicit);
        $this->assertSame(8, (int) DB::table('loot_table_entries')->where('item_template_id', $row['id'])->where('loot_table_id', $forest)->value('weight'));
        $listing = DB::table('shop_listings')->where('item_template_id', $row['id'])->first();
        $this->assertNotNull($listing);
        $this->assertSame(1, (int) $listing->is_active);
        $this->assertSame(ShopMath::gearPrice(12, 2), (int) $listing->price);
        $this->assertSame(2, (int) $listing->min_level);

        $this->actingAs($admin)->put('/admin/gear/'.$row['id'], [
            'code' => 'ash_spear',
            'name' => 'Ashwood Spear',
            'slot' => 'weapon',
            'item_type' => 'sword',
            'scaling_stat' => 'str',
            'two_handed' => true,
            'base_power' => 8,
            'base_armor' => 0,
            'base_mres' => 0,
            'req_level' => 2,
            'base_value' => 12,
            'implicit' => [['key' => 'speed', 'value' => 2]],
            'loot' => [['loot_table_id' => $forest, 'weight' => 0]],
            'in_shop' => false,
        ])->assertRedirect();

        $updated = (array) DB::table('item_templates')->where('id', $row['id'])->first();
        $this->assertSame('Ashwood Spear', $updated['name']);
        $this->assertSame(8, (int) $updated['base_power']);
        $this->assertSame(0, (int) DB::table('loot_table_entries')->where('item_template_id', $row['id'])->count());
        $this->assertSame(0, (int) DB::table('shop_listings')->where('item_template_id', $row['id'])->value('is_active'));

        $this->actingAs($admin)->delete('/admin/gear/'.$row['id'])->assertRedirect('/admin');
        $this->assertSame(0, DB::table('item_templates')->where('code', 'ash_spear')->count());
    }

    public function test_starter_templates_cannot_be_deleted_or_renamed_by_code(): void
    {
        [$admin] = $this->makePlayer('rey');
        $id = (int) DB::table('item_templates')->where('code', 'rusty_sword')->value('id');

        $this->actingAs($admin)->delete('/admin/gear/'.$id)->assertRedirect();
        $this->assertSame(1, DB::table('item_templates')->where('code', 'rusty_sword')->count());

        $this->actingAs($admin)->put('/admin/gear/'.$id, [
            'code' => 'not_rusty',
            'name' => 'Rusty Sword',
            'slot' => 'weapon',
            'item_type' => 'sword',
            'scaling_stat' => 'str',
            'two_handed' => false,
            'base_power' => 4,
            'base_armor' => 0,
            'base_mres' => 0,
            'req_level' => 1,
            'base_value' => 5,
            'implicit' => [],
            'loot' => [],
            'in_shop' => true,
        ])->assertRedirect();
        $this->assertSame('rusty_sword', DB::table('item_templates')->where('id', $id)->value('code'));
    }

    public function test_weapon_without_scaling_is_rejected(): void
    {
        [$admin] = $this->makePlayer('rey');
        $before = DB::table('item_templates')->count();
        $this->actingAs($admin)->post('/admin/gear', [
            'name' => 'Broken Blade',
            'slot' => 'weapon',
            'item_type' => 'sword',
            'base_power' => 4,
            'base_armor' => 0,
            'base_mres' => 0,
            'req_level' => 1,
            'base_value' => 5,
        ])->assertRedirect();
        $this->assertSame($before, DB::table('item_templates')->count());
    }
}
