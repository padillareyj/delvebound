<?php

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Growth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetGame();
    }

    public function test_every_page_renders_its_component(): void
    {
        [$account] = $this->makePlayer('rey');
        $pages = [
            '/' => 'Dashboard', '/character' => 'Character', '/market' => 'Market', '/premium' => 'Premium', '/map' => 'Map',
            '/mail' => 'mail/Index', '/leaderboard' => 'Leaderboard', '/bots' => 'Bots', '/arena' => 'Arena', '/account' => 'Account',
            '/runs' => 'Runs', '/admin' => 'admin/Index', '/admin/gear/create' => 'admin/GearForm',
        ];
        $this->actingAs($account)->get('/pack')->assertRedirect('/character');
        foreach ($pages as $url => $component) {
            $this->actingAs($account)->get($url)->assertOk()
                ->assertInertia(fn ($page) => $page->component($component)->has('hero')->has('run'));
        }
    }

    public function test_leaderboard_shows_power_for_players_and_bots(): void
    {
        [$account, $char] = $this->makePlayer('rey');
        DB::table('characters')->where('id', $char['id'])->update([
            'level' => 4,
            'skills' => json_encode(['keen_edge' => 2, 'iron_hide' => 1]),
            'horcrux_tier' => 1,
        ]);
        $balance = Balance::get();
        $expected = Growth::powerLevel(4, ['keen_edge' => 2, 'iron_hide' => 1], 1, $balance);

        $this->actingAs($account)->get('/leaderboard?sort=power')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Leaderboard')
            ->where('sort', 'power')
            ->where('rows', function ($rows) use ($char, $expected) {
                $mine = collect($rows)->firstWhere('id', $char['id']);
                $bots = collect($rows)->where('is_bot', true);

                return $mine['power'] === $expected
                    && $bots->isNotEmpty()
                    && $bots->every(fn ($row) => is_int($row['power']) && $row['power'] > 0)
                    && collect($rows)->pluck('power')->sortDesc()->values()->all() === collect($rows)->pluck('power')->all();
            }));
    }

    public function test_leaderboard_sheet_shows_stats_and_gear_without_inventory(): void
    {
        [$account, $char] = $this->makePlayer('rey');
        $bot = DB::table('characters')->where('is_bot', 1)->first();

        $this->get('/leaderboard/'.$char['id'])->assertRedirect('/login');

        $this->actingAs($account)->get('/leaderboard/'.$char['id'])->assertOk()->assertInertia(fn ($page) => $page
            ->component('Adventurer')
            ->where('character.id', $char['id'])
            ->where('character.is_bot', false)
            ->has('character.stats.str')
            ->has('derived.attack')
            ->has('equipment')
            ->has('skills')
            ->has('horcrux.tier')
            ->missing('inventory')
            ->missing('materials')
            ->missing('consumables'));

        $this->actingAs($account)->get('/leaderboard/'.$bot->id.'?sort=power')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Adventurer')
            ->where('character.is_bot', true)
            ->where('sort', 'power')
            ->has('equipment')
            ->missing('inventory'));

        $this->actingAs($account)->get('/leaderboard/999999')->assertNotFound();
    }

    public function test_runs_page_is_admin_only(): void
    {
        $this->makePlayer('admin');
        [$player] = $this->makePlayer('rey');
        $this->actingAs($player)->get('/runs')->assertForbidden();
        $this->actingAs($player)->get('/admin')->assertForbidden();
        $this->actingAs($player)->post('/admin/gear', ['name' => 'Nope', 'slot' => 'weapon', 'item_type' => 'sword'])->assertForbidden();
    }

    public function test_map_marks_locked_places_with_the_gate_message(): void
    {
        [$account] = $this->makePlayer('rey');
        $this->actingAs($account)->get('/map')->assertInertia(fn ($page) => $page
            ->where('regions', fn ($regions) => collect($regions)->pluck('code')->sort()->values()->all() === ['ashen_crown', 'salt_marches', 'wilds'])
            ->where('places', fn ($places) => collect($places)->firstWhere('code', 'sunken_crypt')['open'] === false
                && str_contains((string) collect($places)->firstWhere('code', 'sunken_crypt')['locked_reason'], '5')
                && collect($places)->firstWhere('code', 'greenwood')['open'] === true
                && collect($places)->firstWhere('code', 'brinefen')['region_code'] === 'salt_marches'
                && collect($places)->firstWhere('code', 'crown_of_embers')['region_code'] === 'ashen_crown'));
    }

    public function test_players_cannot_read_each_others_mail_or_trips(): void
    {
        [$rey, $reyChar] = $this->makePlayer('rey');
        [$mira] = $this->makePlayer('mira');
        $this->artisan('delve:run', ['--seed' => 5])->assertExitCode(0);
        $mailId = DB::table('mail')->where('character_id', $reyChar['id'])->value('id');
        $tripId = DB::table('trips')->where('character_id', $reyChar['id'])->value('id');

        $this->actingAs($rey)->get("/mail/{$mailId}")->assertOk()->assertInertia(fn ($page) => $page->component('mail/Show'));
        $this->actingAs($rey)->get("/trips/{$tripId}")->assertOk()->assertInertia(fn ($page) => $page->component('Trip')->has('trip.encounters'));
        $this->actingAs($mira)->get("/mail/{$mailId}")->assertNotFound();
        $this->actingAs($mira)->get("/trips/{$tripId}")->assertNotFound();
        $this->actingAs($mira)->delete("/mail/{$mailId}");
        $this->assertNull(DB::table('mail')->where('id', $mailId)->value('deleted_at'));
    }
}
