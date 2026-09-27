<?php

namespace Tests\Feature;

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
            '/' => 'Dashboard', '/character' => 'Character', '/pack' => 'Pack', '/market' => 'Market', '/map' => 'Map',
            '/mail' => 'mail/Index', '/leaderboard' => 'Leaderboard', '/bots' => 'Bots', '/arena' => 'Arena', '/account' => 'Account',
            '/runs' => 'Runs', '/admin' => 'admin/Index', '/admin/gear/create' => 'admin/GearForm',
        ];
        foreach ($pages as $url => $component) {
            $this->actingAs($account)->get($url)->assertOk()
                ->assertInertia(fn ($page) => $page->component($component)->has('hero')->has('run'));
        }
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
