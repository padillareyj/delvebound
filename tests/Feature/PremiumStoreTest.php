<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The premium store pages and writes (DESIGN.md §16). */
class PremiumStoreTest extends TestCase
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

    private function write(string $url, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->account)->from('/premium')->post($url, $data + ['row_version' => (int) $this->fresh()['row_version']]);
    }

    public function test_logged_out_visitors_are_sent_to_login(): void
    {
        $this->get('/premium')->assertRedirect('/login');
        $this->post('/premium/buy', ['code' => 'title_wanderer'])->assertRedirect('/login');
        $this->assertNull($this->fresh()['premium_cash']);
    }

    public function test_everyone_starts_with_one_hundred_dollars(): void
    {
        $this->actingAs($this->account)->get('/premium')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Premium')
            ->where('cash', 10000)
            ->where('cashLabel', '$100.00')
            ->where('hero.cash', 10000)
            ->has('items', DB::table('premium_items')->where('is_active', 1)->count()));

        $bot = DB::table('characters')->where('is_bot', 1)->first();
        $this->assertNull($bot->premium_cash);
        $this->actingAs($this->account)->get('/bots')->assertInertia(fn ($page) => $page
            ->where('bots.0.cash', 10000));
    }

    public function test_buying_a_title_spends_cash_wears_it_and_raises_stats(): void
    {
        $before = $this->actingAs($this->account)->get('/character')->viewData('page')['props'];

        $this->write('/premium/buy', ['code' => 'title_kingslayer'])->assertSessionHas('success');

        $char = $this->fresh();
        $this->assertSame(10000 - 1499, (int) $char['premium_cash']);
        $premium = json_decode($char['premium'], true);
        $this->assertSame(['title_kingslayer'], $premium['owned']);
        $this->assertSame(['title' => 'title_kingslayer'], $premium['equipped']);

        $row = DB::table('shop_transactions')->where('character_id', $char['id'])->where('action', 'premium')->first();
        $this->assertSame('web', $row->actor);
        $this->assertSame(-1499, (int) $row->cash_delta);
        $this->assertSame(10000 - 1499, (int) $row->cash_after);

        $after = $this->actingAs($this->account)->get('/character')->viewData('page')['props'];
        $this->assertGreaterThan($before['derived']['attack'], $after['derived']['attack']);
        $this->assertGreaterThan($before['power'], $after['power']);
        $this->assertSame('title_kingslayer', $after['appearance']['title']['code']);

        $this->write('/premium/buy', ['code' => 'title_kingslayer'])->assertSessionHas('error');
        $this->assertSame(10000 - 1499, (int) $this->fresh()['premium_cash']);
    }

    public function test_cannot_overspend_wear_unowned_or_buy_unknown(): void
    {
        DB::table('characters')->where('id', $this->character['id'])->update(['premium_cash' => 500]);

        $this->write('/premium/buy', ['code' => 'wings_void'])->assertSessionHas('error');
        $this->write('/premium/wear', ['code' => 'wings_void'])->assertSessionHas('error');
        $this->write('/premium/buy', ['code' => 'not_a_thing'])->assertSessionHas('error');
        $this->write('/premium/buy', ['code' => "x'; DROP TABLE characters; --"])->assertSessionHasErrors('code');
        $this->write('/premium/take-off', ['category' => 'weapon'])->assertSessionHas('error');

        $char = $this->fresh();
        $this->assertSame(500, (int) $char['premium_cash']);
        $this->assertNull($char['premium']);
        $this->assertSame(0, DB::table('shop_transactions')->where('action', 'premium')->count());
    }

    public function test_stale_row_version_is_rejected(): void
    {
        $version = (int) $this->fresh()['row_version'];
        DB::table('characters')->where('id', $this->character['id'])->increment('row_version');

        $this->actingAs($this->account)->from('/premium')
            ->post('/premium/buy', ['code' => 'title_wanderer', 'row_version' => $version])
            ->assertSessionHas('error');
        $this->assertNull($this->fresh()['premium_cash']);
    }

    public function test_wear_and_take_off_switch_pieces(): void
    {
        $this->write('/premium/buy', ['code' => 'title_wanderer']);
        $this->write('/premium/buy', ['code' => 'title_delver']);
        $this->assertSame('title_delver', json_decode($this->fresh()['premium'], true)['equipped']['title']);

        $this->write('/premium/wear', ['code' => 'title_wanderer'])->assertSessionHas('success');
        $this->assertSame('title_wanderer', json_decode($this->fresh()['premium'], true)['equipped']['title']);

        $this->write('/premium/take-off', ['category' => 'title'])->assertSessionHas('success');
        $premium = json_decode($this->fresh()['premium'], true);
        $this->assertSame([], (array) $premium['equipped']);
        $this->assertSame(['title_delver', 'title_wanderer'], $premium['owned']);
    }

    public function test_gold_bundle_adds_gold_and_repeats(): void
    {
        $gold = (int) $this->fresh()['gold'];
        $grant = (int) DB::table('premium_items')->where('code', 'bundle_pouch')->value('grant_gold');
        $price = (int) DB::table('premium_items')->where('code', 'bundle_pouch')->value('price_cents');

        $this->write('/premium/buy', ['code' => 'bundle_pouch'])->assertSessionHas('success');
        $this->write('/premium/buy', ['code' => 'bundle_pouch'])->assertSessionHas('success');

        $char = $this->fresh();
        $this->assertSame($gold + 2 * $grant, (int) $char['gold']);
        $this->assertSame(10000 - 2 * $price, (int) $char['premium_cash']);
        $this->assertSame(2 * $grant, (int) DB::table('shop_transactions')->where('action', 'premium')->sum('gold_delta'));
    }

    public function test_writes_are_refused_during_a_run(): void
    {
        DB::connection('admin')->table('simulation_runs')->insert(['status' => 'running', 'seed' => 1, 'started_at' => now('UTC')]);
        $this->write('/premium/buy', ['code' => 'title_wanderer']);
        $this->assertNull($this->fresh()['premium_cash']);
    }

    public function test_bots_shop_the_premium_store_during_a_run_and_humans_do_not(): void
    {
        $this->artisan('delve:run', ['--seed' => 42])->assertExitCode(0);

        $bots = DB::table('characters')->where('is_bot', 1)->get();
        $this->assertTrue($bots->contains(fn ($b) => $b->premium_cash !== null && (int) $b->premium_cash < 10000));
        $this->assertTrue($bots->every(fn ($b) => $b->premium_cash === null || (int) $b->premium_cash >= 0));
        $buys = DB::table('shop_transactions')->where('action', 'premium')->where('actor', 'bot')->get();
        $this->assertNotEmpty($buys);
        $this->assertLessThanOrEqual($bots->count(), $buys->count());
        $this->assertNull($this->fresh()['premium_cash']);
    }
}
