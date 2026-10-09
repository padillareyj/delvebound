<?php

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Forge;
use App\Models\Account;
use App\Services\ForgeService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GameWritesTest extends TestCase
{
    private Account $account;

    private array $character;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetGame();
        [$this->account, $this->character] = $this->makePlayer('rey');
    }

    private function fresh(): array
    {
        return (array) DB::table('characters')->where('id', $this->character['id'])->first();
    }

    private function version(): int
    {
        return (int) $this->fresh()['row_version'];
    }

    private function listing(string $code): int
    {
        return (int) DB::table('shop_listings as sl')
            ->leftJoin('item_templates as it', 'it.id', '=', 'sl.item_template_id')
            ->leftJoin('consumable_templates as ct', 'ct.id', '=', 'sl.consumable_id')
            ->where(fn ($q) => $q->where('it.code', $code)->orWhere('ct.code', $code))
            ->value('sl.id');
    }

    public function test_csrf_protection_is_on_for_web_routes(): void
    {
        $this->assertContains(ValidateCsrfToken::class, app(Kernel::class)->getMiddlewareGroups()['web']);
    }

    public function test_auto_mode_saves_a_personality(): void
    {
        $this->actingAs($this->account)
            ->post('/character/prep', [
                'retreat_threshold_pct' => 25,
                'potion_threshold_pct' => 40,
                'auto_repeat' => 1,
                'auto_mode' => 1,
                'auto_profile' => 'shade',
                'auto_place' => 'profile',
                'row_version' => $this->version(),
            ])
            ->assertSessionHas('success');

        $char = $this->fresh();
        $this->assertSame(1, (int) $char['auto_mode']);
        $this->assertSame(
            (int) DB::table('bot_profiles')->where('code', 'shade')->value('id'),
            (int) $char['auto_profile_id'],
        );
        $this->assertSame(0, (int) $char['is_bot']);
        $this->assertSame('profile', $char['auto_place']);

        $this->actingAs($this->account)
            ->post('/character/prep', [
                'retreat_threshold_pct' => 25,
                'potion_threshold_pct' => 40,
                'auto_repeat' => 1,
                'auto_mode' => 1,
                'auto_profile' => 'shade',
                'auto_place' => 'best',
                'row_version' => $this->version(),
            ])
            ->assertSessionHas('success');
        $this->assertSame('best', $this->fresh()['auto_place']);

        $this->actingAs($this->account)
            ->post('/character/prep', [
                'retreat_threshold_pct' => 25,
                'potion_threshold_pct' => 40,
                'auto_repeat' => 1,
                'auto_mode' => 1,
                'auto_profile' => 'not-a-rival',
                'auto_place' => 'best',
                'row_version' => $this->version(),
            ])
            ->assertSessionHas('error');
        $this->assertSame(1, (int) $this->fresh()['auto_mode']);
        $this->assertSame('best', $this->fresh()['auto_place']);
    }

    public function test_spending_points_checks_the_row_version(): void
    {
        $this->actingAs($this->account)
            ->post('/character/points', ['points' => ['str' => 3, 'vit' => 2], 'row_version' => $this->version()])
            ->assertSessionHas('success');
        $char = $this->fresh();
        $this->assertSame([8, 7, 0], [(int) $char['stat_str'], (int) $char['stat_vit'], (int) $char['unspent_points']]);

        DB::connection('admin')->table('characters')->where('id', $char['id'])->update(['unspent_points' => 2]);
        $this->actingAs($this->account)
            ->post('/character/points', ['points' => ['dex' => 1], 'row_version' => (int) $char['row_version'] - 1])
            ->assertSessionHas('error');
        $this->assertSame(5, (int) $this->fresh()['stat_dex']);

        $this->actingAs($this->account)
            ->post('/character/points', ['points' => ['dex' => 9], 'row_version' => $this->version()])
            ->assertSessionHas('error');
        $this->assertSame(2, (int) $this->fresh()['unspent_points']);
    }

    public function test_cannot_equip_or_sell_someone_elses_item(): void
    {
        [, $mira] = $this->makePlayer('mira');
        $miraItem = (int) DB::table('item_instances')->where('character_id', $mira['id'])->value('id');
        $this->actingAs($this->account)
            ->post('/pack/equip', ['item_id' => $miraItem, 'slot' => 'weapon', 'row_version' => $this->version()])
            ->assertSessionHas('error');
        $this->actingAs($this->account)
            ->post('/market/sell-item', ['item_id' => $miraItem, 'row_version' => $this->version()])
            ->assertSessionHas('error');
        $this->assertSame('owned', DB::table('item_instances')->where('id', $miraItem)->value('status'));
        $this->assertSame((int) $mira['id'], (int) DB::table('item_instances')->where('id', $miraItem)->value('character_id'));
    }

    public function test_orders_respect_the_level_gate(): void
    {
        $crypt = (int) DB::table('places')->where('code', 'sunken_crypt')->value('id');
        $greenwood = (int) DB::table('places')->where('code', 'greenwood')->value('id');
        $this->actingAs($this->account)->post('/map/order', ['place_id' => $crypt])->assertSessionHas('error');
        $this->assertFalse(DB::table('trip_orders')->where('character_id', $this->character['id'])->exists());

        $this->actingAs($this->account)->post('/map/order', ['place_id' => $greenwood])->assertSessionHas('success');
        $this->assertSame($greenwood, (int) DB::table('trip_orders')->where('character_id', $this->character['id'])->where('status', 'pending')->value('place_id'));

        $this->actingAs($this->account)->delete('/map/order')->assertSessionHas('success');
        $this->assertFalse(DB::table('trip_orders')->where('character_id', $this->character['id'])->where('status', 'pending')->exists());

        DB::table('characters')->where('id', $this->character['id'])->update(['level' => 12]);
        $this->actingAs($this->account)->post('/map/order', ['place_id' => $greenwood])->assertSessionHas('success');
    }

    public function test_a_skill_rank_spends_dust_and_the_horcrux_eats_spare_gear(): void
    {
        DB::table('characters')->where('id', $this->character['id'])->update([
            'gold' => 200,
            'materials' => json_encode(['spark_dust' => 2]),
        ]);
        $this->actingAs($this->account)
            ->post('/character/skills/keen_edge', ['row_version' => $this->version()])
            ->assertSessionHas('success');
        $fresh = $this->fresh();
        $this->assertSame(1, (int) (json_decode($fresh['skills'], true)['keen_edge'] ?? 0));
        $this->assertSame(160, (int) $fresh['gold']);
        $this->assertSame([], json_decode($fresh['materials'] ?? '[]', true) ?: []);

        $template = (int) DB::table('item_templates')->where('code', 'cloth_tunic')->value('id');
        $itemId = DB::table('item_instances')->insertGetId([
            'template_id' => $template,
            'character_id' => $this->character['id'],
            'status' => 'owned',
            'rarity' => 'common',
            'item_level' => 1,
            'display_name' => 'Spare Tunic',
            'sell_value' => 5,
            'source' => 'drop',
        ]);
        $this->actingAs($this->account)
            ->post('/character/horcrux', ['item_id' => $itemId, 'row_version' => $this->version()])
            ->assertSessionHas('success');
        $this->assertSame('destroyed', DB::table('item_instances')->where('id', $itemId)->value('status'));
        $this->assertGreaterThan(0, (int) $this->fresh()['horcrux_progress']);
    }

    public function test_market_belt_and_selling(): void
    {
        $gold = (int) $this->character['gold'];
        $potion = $this->listing('minor_healing_potion');
        $price = (int) DB::table('shop_listings')->where('id', $potion)->value('price');
        $this->actingAs($this->account)
            ->post('/market/buy', ['listing_id' => $potion, 'quantity' => 2, 'row_version' => $this->version()])
            ->assertSessionHas('success');
        $this->assertSame($gold - 2 * $price, (int) $this->fresh()['gold']);
        $stack = fn () => DB::table('character_consumables')->where('character_id', $this->character['id'])->first();
        $consumableId = (int) $stack()->consumable_id;
        $owned = (int) $stack()->quantity;

        $this->actingAs($this->account)
            ->post('/pack/belt', ['belt' => [$consumableId => $owned], 'row_version' => $this->version()])
            ->assertSessionHas('success');
        $this->assertSame($owned, (int) $stack()->belt_qty);

        $this->actingAs($this->account)
            ->post('/pack/belt', ['belt' => [$consumableId => 99], 'row_version' => $this->version()])
            ->assertSessionHas('error');

        $locked = $this->listing('iron_longsword');
        $this->actingAs($this->account)
            ->post('/market/buy', ['listing_id' => $locked, 'row_version' => $this->version()])
            ->assertSessionHas('error');

        $before = (int) $this->fresh()['gold'];
        $this->actingAs($this->account)
            ->post('/market/sell-consumable', ['consumable_id' => $consumableId, 'quantity' => 1, 'row_version' => $this->version()])
            ->assertSessionHas('success');
        $this->assertGreaterThan($before, (int) $this->fresh()['gold']);
        $this->assertSame($owned - 1, (int) $stack()->quantity);
        $this->assertSame($owned - 1, (int) $stack()->belt_qty, 'the belt never holds more than you own');
    }

    public function test_opening_a_chest_rolls_gear_once(): void
    {
        $admin = DB::connection('admin');
        $placeId = (int) $admin->table('places')->where('code', 'greenwood')->value('id');
        $runId = $admin->table('simulation_runs')->insertGetId(['status' => 'completed', 'seed' => 7, 'finished_at' => now()]);
        $tripId = $admin->table('trips')->insertGetId([
            'run_id' => $runId,
            'character_id' => $this->character['id'],
            'place_id' => $placeId,
            'outcome' => 'cleared',
            'max_hp' => 20,
            'hp_end' => 12,
            'level_before' => 1,
            'level_after' => 1,
            'rng_seed' => '7:1',
            'snapshot' => '{}',
        ]);
        $boxId = $admin->table('loot_boxes')->insertGetId([
            'character_id' => $this->character['id'],
            'trip_id' => $tripId,
            'place_id' => $placeId,
            'seed' => 99,
            'luck_factor' => 0.25,
            'source_level' => 2,
            'rolls' => 2,
        ]);

        $previous = config('database.default');
        config(['database.default' => 'admin']);
        try {
            $this->actingAs($this->account)
                ->post("/pack/chests/{$boxId}/open", ['row_version' => $this->version()])
                ->assertSessionHas('success')
                ->assertSessionHas('opened_chest');
        $this->assertSame('opened', $admin->table('loot_boxes')->where('id', $boxId)->value('status'));
        $found = $admin->table('item_instances')->where('created_trip_id', $tripId)->where('source', 'chest')->count();
        $this->assertLessThanOrEqual(2, $found);
        $this->assertSame($found, $admin->table('trip_loot')->where('trip_id', $tripId)->whereNull('encounter_seq')->count());

        $this->actingAs($this->account)
            ->post("/pack/chests/{$boxId}/open", ['row_version' => $this->version()])
            ->assertSessionHas('error');

        [, $mira] = $this->makePlayer('mira');
        $foreign = $admin->table('loot_boxes')->insertGetId([
            'character_id' => $mira['id'],
            'trip_id' => $admin->table('trips')->insertGetId([
                'run_id' => $admin->table('simulation_runs')->insertGetId(['status' => 'completed', 'seed' => 8, 'finished_at' => now()]),
                'character_id' => $mira['id'],
                'place_id' => $placeId,
                'outcome' => 'cleared',
                'max_hp' => 20,
                'hp_end' => 12,
                'level_before' => 1,
                'level_after' => 1,
                'rng_seed' => '8:2',
                'snapshot' => '{}',
            ]),
            'place_id' => $placeId,
            'seed' => 3,
            'luck_factor' => 0.1,
            'source_level' => 2,
            'rolls' => 2,
        ]);
        $this->actingAs($this->account)
            ->post("/pack/chests/{$foreign}/open", ['row_version' => $this->version()])
            ->assertSessionHas('error');
        $this->assertSame('sealed', $admin->table('loot_boxes')->where('id', $foreign)->value('status'));
        } finally {
            config(['database.default' => $previous]);
        }
    }

    public function test_the_forge_consumes_two_pieces_and_records_the_gold(): void
    {
        DB::table('characters')->where('id', $this->character['id'])->update(['gold' => 500, 'stat_luk' => 0]);
        $left = $this->spare('weapon', 'common', 1);
        $right = $this->spare('weapon', 'rare', 3);
        $helm = $this->spare('head', 'common', 1);
        $quote = Forge::quote(
            ['id' => $left, 'slot' => 'weapon', 'rarity' => 'common', 'item_level' => 1, 'equipped' => false],
            ['id' => $right, 'slot' => 'weapon', 'rarity' => 'rare', 'item_level' => 3, 'equipped' => false],
            500,
            0,
            Balance::get(),
        );

        $this->actingAs($this->account)
            ->post('/pack/forge', ['item_a' => $left, 'item_b' => $helm, 'row_version' => $this->version()])
            ->assertSessionHas('error', 'Both pieces have to be the same slot.');
        $this->assertSame('owned', DB::table('item_instances')->where('id', $left)->value('status'));

        $worn = (int) DB::table('character_equipment')->where('character_id', $this->character['id'])->where('slot', 'weapon')->value('item_instance_id');
        $this->actingAs($this->account)
            ->post('/pack/forge', ['item_a' => $worn, 'item_b' => $right, 'row_version' => $this->version()])
            ->assertSessionHas('error');
        $this->assertSame('owned', DB::table('item_instances')->where('id', $worn)->value('status'));

        [, $mira] = $this->makePlayer('mira');
        $foreign = (int) DB::table('item_instances')->where('character_id', $mira['id'])->value('id');
        $this->actingAs($this->account)
            ->post('/pack/forge', ['item_a' => $foreign, 'item_b' => $right, 'row_version' => $this->version()])
            ->assertSessionHas('error');
        $this->assertSame((int) $mira['id'], (int) DB::table('item_instances')->where('id', $foreign)->value('character_id'));

        $result = app(ForgeService::class)->combine((int) $this->account->id, $this->version(), $left, $right, 7);
        $char = $this->fresh();
        $this->assertSame(500 - $quote['gold'], (int) $char['gold']);
        $this->assertSame('destroyed', DB::table('item_instances')->where('id', $left)->value('status'));
        $this->assertSame('destroyed', DB::table('item_instances')->where('id', $right)->value('status'));
        $this->assertNull(DB::table('item_instances')->where('id', $left)->value('character_id'));
        $ledger = DB::table('shop_transactions')->where('character_id', $char['id'])->where('action', 'craft')->first();
        $this->assertNotNull($ledger);
        $this->assertSame(-1 * $quote['gold'], (int) $ledger->gold_delta);
        $this->assertSame((int) $char['gold'], (int) $ledger->gold_after);

        if ($result['outcome'] === 'fail') {
            $this->assertNull($ledger->item_instance_id);
            $this->assertSame('The forge cracks. Both pieces are lost.', $result['message']);
            $this->assertNull($result['item']);
        } else {
            $this->assertSame((int) $ledger->item_instance_id, $result['item']['id']);
            $made = DB::table('item_instances')->where('id', $ledger->item_instance_id)->first();
            $this->assertSame('craft', $made->source);
            $this->assertSame('owned', $made->status);
            $this->assertSame((int) $char['id'], (int) $made->character_id);
            $this->assertStringContainsString($made->display_name, $result['message']);
        }

        $againA = $this->spare('weapon');
        $againB = $this->spare('weapon');
        DB::connection('admin')->table('simulation_runs')->insert(['status' => 'running', 'seed' => 1]);
        $this->actingAs($this->account)
            ->post('/pack/forge', ['item_a' => $againA, 'item_b' => $againB, 'row_version' => $this->version()])
            ->assertSessionHas('error', 'A simulation run is in progress, try again in a moment.');
        $this->assertSame('owned', DB::table('item_instances')->where('id', $againA)->value('status'));
    }

    private function spare(string $slot, string $rarity = 'common', int $ilvl = 1): int
    {
        $template = DB::table('item_templates')->where('slot', $slot)->orderBy('req_level')->orderBy('id')->first();

        return (int) DB::table('item_instances')->insertGetId([
            'template_id' => $template->id,
            'character_id' => $this->character['id'],
            'status' => 'owned',
            'rarity' => $rarity,
            'item_level' => $ilvl,
            'display_name' => $template->name,
            'power' => 1,
            'sell_value' => 5,
            'source' => 'drop',
        ]);
    }

    public function test_writes_are_refused_while_a_run_is_running(): void
    {
        DB::connection('admin')->table('simulation_runs')->insert(['status' => 'running', 'seed' => 1]);
        $this->actingAs($this->account)
            ->post('/character/points', ['points' => ['str' => 1], 'row_version' => $this->version()])
            ->assertSessionHas('error', 'A simulation run is in progress, try again in a moment.');
        $this->assertSame(5, (int) $this->fresh()['unspent_points']);
        $this->actingAs($this->account)->get('/character')->assertOk();
    }
}
