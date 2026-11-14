<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Card bag insert / remove / sell (DESIGN.md §6.10). */
class CardsTest extends TestCase
{
    private Account $account;

    private array $character;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetGame();
        [$this->account, $this->character] = $this->makePlayer('rey');
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function fresh(): array
    {
        return (array) DB::table('characters')->where('id', $this->character['id'])->first();
    }

    private function write(string $url, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->account)->from('/character')->post($url, $data + ['row_version' => (int) $this->fresh()['row_version']]);
    }

    public function test_cards_tab_lists_bag_and_insert_remove_sell(): void
    {
        $cid = (int) $this->character['id'];
        $templateId = (int) DB::table('item_templates')->where('slot', 'amulet')->orderBy('id')->value('id');
        $itemId = (int) DB::table('item_instances')->insertGetId([
            'template_id' => $templateId,
            'character_id' => $cid,
            'status' => 'owned',
            'rarity' => 'rare',
            'item_level' => 5,
            'display_name' => 'Test Amulet',
            'power' => 0,
            'armor' => 0,
            'mres' => 2,
            'sell_value' => 10,
            'card_slots' => 1,
            'source' => 'admin',
        ]);
        $cardId = (int) DB::connection('admin')->table('card_instances')->insertGetId([
            'character_id' => $cid,
            'status' => 'owned',
            'rarity' => 'uncommon',
            'display_name' => 'Wolf Card',
            'monster_code' => 'wolf',
            'item_level' => 4,
            'bonuses' => json_encode(['attack' => 3, 'hp' => 12]),
            'sell_value' => 8,
            'source' => 'drop',
        ]);

        $this->write('/character/cards/insert', [
            'card_id' => $cardId,
            'item_id' => $itemId,
            'slot_index' => 0,
        ])->assertSessionHas('success');

        $this->assertSame('socketed', DB::connection('admin')->table('card_instances')->where('id', $cardId)->value('status'));
        $this->assertSame(1, DB::connection('admin')->table('item_instance_cards')->where('item_instance_id', $itemId)->count());

        $page = $this->actingAs($this->account)->get('/character?tab=cards')->assertOk()->viewData('page')['props'];
        $this->assertSame(40, $page['cardBagCap']);
        $this->assertSame([], $page['cardsBag']);
        $slotted = collect($page['inventory'])->firstWhere('id', $itemId)
            ?? collect($page['equipment'])->firstWhere('id', $itemId);
        $this->assertNotNull($slotted);
        $this->assertSame(1, (int) $slotted['card_slots']);
        $this->assertSame($cardId, (int) $slotted['cards'][0]['id']);

        DB::table('character_equipment')->insert([
            'character_id' => $cid,
            'slot' => 'amulet',
            'item_instance_id' => $itemId,
        ]);
        $detail = $this->actingAs($this->account)->get('/character?tab=detail')->viewData('page')['props'];
        $this->assertGreaterThan(0, $detail['derived']['attack'] - $detail['plainDerived']['attack']);
        $cardBonus = (array) (collect($detail['bonusSources'])->firstWhere('source', 'Cards')['bonus'] ?? []);
        $this->assertSame(3, (int) ($cardBonus['attack'] ?? 0));

        $this->write('/character/cards/remove', [
            'item_id' => $itemId,
            'slot_index' => 0,
        ])->assertSessionHas('success');
        $this->assertSame('owned', DB::connection('admin')->table('card_instances')->where('id', $cardId)->value('status'));

        $goldBefore = (int) $this->fresh()['gold'];
        $this->write('/character/cards/sell', ['card_id' => $cardId])->assertSessionHas('success');
        $this->assertSame('sold', DB::connection('admin')->table('card_instances')->where('id', $cardId)->value('status'));
        $this->assertSame($goldBefore + 8, (int) $this->fresh()['gold']);
    }

    public function test_insert_rejects_tampering(): void
    {
        [$other] = $this->makePlayer('mira');
        $otherChar = (array) DB::table('characters')->where('account_id', $other->id)->first();
        $cid = (int) $this->character['id'];
        $templateId = (int) DB::table('item_templates')->where('slot', 'head')->orderBy('id')->value('id');
        $itemId = (int) DB::table('item_instances')->insertGetId([
            'template_id' => $templateId,
            'character_id' => $cid,
            'status' => 'owned',
            'rarity' => 'common',
            'item_level' => 1,
            'display_name' => 'Cap',
            'power' => 0,
            'armor' => 1,
            'mres' => 0,
            'sell_value' => 1,
            'card_slots' => 0,
            'source' => 'admin',
        ]);
        $cardId = (int) DB::connection('admin')->table('card_instances')->insertGetId([
            'character_id' => (int) $otherChar['id'],
            'status' => 'owned',
            'rarity' => 'common',
            'display_name' => 'Stolen Card',
            'item_level' => 1,
            'bonuses' => json_encode(['speed' => 1]),
            'sell_value' => 1,
            'source' => 'drop',
        ]);

        $this->write('/character/cards/insert', [
            'card_id' => $cardId,
            'item_id' => $itemId,
            'slot_index' => 0,
        ])->assertSessionHas('error');
    }
}
