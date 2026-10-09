<?php

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Progression;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SimulationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetGame();
    }

    private function counts(): array
    {
        return array_map(fn ($t) => DB::table($t)->count(), [
            'simulation_runs' => 'simulation_runs', 'trips' => 'trips', 'mail' => 'mail', 'bot_decisions' => 'bot_decisions', 'item_instances' => 'item_instances',
        ]);
    }

    /** Everything a run produced, minus ids and timestamps. */
    private function results(): array
    {
        return DB::table('trips as t')->join('characters as c', 'c.id', '=', 't.character_id')->orderBy('c.name')
            ->get(['c.name', 't.outcome', 't.xp_gained', 't.gold_gained', 't.hp_end', 't.encounters_won'])
            ->map(fn ($r) => (array) $r)
            ->merge(DB::table('trip_encounters as e')->join('trips as t', 't.id', '=', 'e.trip_id')
                ->join('characters as c', 'c.id', '=', 't.character_id')->orderBy('c.name')->orderBy('e.seq')
                ->get(['c.name', 'e.seq', 'e.log'])->map(fn ($r) => (array) $r))
            ->all();
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->makePlayer('rey');
        $before = $this->counts();
        $this->artisan('delve:run', ['--dry-run' => true, '--seed' => 42])->assertExitCode(0);
        $after = $this->counts();
        unset($before['simulation_runs'], $after['simulation_runs']);
        $this->assertSame($before, $after);
        $this->assertSame(0, DB::table('simulation_runs')->count());
    }

    public function test_a_run_gives_every_character_one_trip_and_one_letter(): void
    {
        [, $rey] = $this->makePlayer('rey');
        $this->artisan('delve:run', ['--seed' => 42])->assertExitCode(0);
        $characters = DB::table('characters')->count();
        $run = DB::table('simulation_runs')->first();
        $this->assertSame('completed', $run->status);
        $bots = DB::table('characters')->where('is_bot', 1)->count();
        $births = DB::table('mail')->where('run_id', $run->id)->where('mail_type', 'system')->count();
        $this->assertContains($bots, [6, 7]);
        $this->assertSame($bots === 7 ? 1 : 0, $births);
        $this->assertSame($characters, DB::table('trips')->where('run_id', $run->id)->count());
        $this->assertSame($characters + $births, DB::table('mail')->where('run_id', $run->id)->count());
        $this->assertSame($bots, DB::table('bot_decisions')->where('run_id', $run->id)->count());
        if ($births === 1) {
            $born = DB::table('characters')->where('is_bot', 1)->orderByDesc('id')->first();
            $letter = DB::table('mail')->where('character_id', $rey['id'])->where('mail_type', 'system')->first();
            $this->assertSame('A hero has been born', $letter->subject);
            $this->assertStringContainsString($born->name, $letter->body_text);
            $payload = json_decode($letter->payload, true);
            $this->assertSame('birth', $payload['kind']);
            $this->assertGreaterThanOrEqual(1, $payload['level']);
        }
        $this->assertSame(1, DB::table('trips')->where('character_id', $rey['id'])->count());
        $cleared = DB::table('trips')->where('outcome', 'cleared')->count();
        $this->assertSame($cleared, DB::table('loot_boxes')->where('status', 'sealed')->count());
    }

    public function test_same_seed_and_state_give_identical_results(): void
    {
        $this->makePlayer('rey');
        $this->artisan('delve:run', ['--seed' => 42])->assertExitCode(0);
        $first = $this->results();

        $this->resetGame();
        $this->makePlayer('rey');
        $this->artisan('delve:run', ['--seed' => 42])->assertExitCode(0);
        $this->assertSame($first, $this->results());
        $this->assertNotEmpty($first);
    }

    public function test_the_simulation_rejects_orders_above_the_gate(): void
    {
        [, $rey] = $this->makePlayer('rey');
        $crypt = (int) DB::table('places')->where('code', 'sunken_crypt')->value('id');
        DB::connection('admin')->table('trip_orders')->insert([
            'character_id' => $rey['id'], 'place_id' => $crypt, 'submitted_by' => 'web', 'submitted_at' => now()->utc()->subMinute(),
        ]);
        $this->artisan('delve:run', ['--seed' => 7, '--skip-bots' => true])->assertExitCode(0);
        $this->assertSame('order_rejected', DB::table('trips')->where('character_id', $rey['id'])->value('outcome'));
        $this->assertNotSame('pending', DB::table('trip_orders')->where('character_id', $rey['id'])->value('status'));
    }

    public function test_auto_mode_plans_a_human_like_a_bot(): void
    {
        [, $rey] = $this->makePlayer('rey');
        $profile = (int) DB::table('bot_profiles')->where('code', 'ranger')->value('id');
        $balance = Balance::get();
        DB::connection('admin')->table('characters')->where('id', $rey['id'])->update([
            'auto_mode' => 1,
            'auto_profile_id' => $profile,
            'auto_place' => 'best',
            'xp' => Progression::xpToNext(1, $balance) - 1,
        ]);

        $this->artisan('delve:run', ['--seed' => 7])->assertExitCode(0);

        $runId = (int) DB::table('simulation_runs')->value('id');
        $this->assertSame(DB::table('characters')->where('is_bot', 1)->count() + 1, DB::table('bot_decisions')->where('run_id', $runId)->count());
        $this->assertNotNull(DB::table('bot_decisions')->where('run_id', $runId)->where('character_id', $rey['id'])->first());
        $this->assertSame('bot', DB::table('trip_orders')->where('character_id', $rey['id'])->where('consumed_run_id', $runId)->value('submitted_by'));
        $char = DB::table('characters')->where('id', $rey['id'])->first();
        $this->assertGreaterThan(1, (int) $char->level);
        $this->assertSame(0, (int) $char->unspent_points);
        $spent = (int) $char->stat_str + (int) $char->stat_dex + (int) $char->stat_int + (int) $char->stat_vit + (int) $char->stat_luk;
        $this->assertSame(Progression::pointPool((int) $char->level, Balance::get()), $spent - 25);
    }

    public function test_reaching_the_frontier_opens_the_next_region(): void
    {
        [, $rey] = $this->makePlayer('rey');
        DB::table('characters')->where('id', $rey['id'])->update(['level' => 29]);
        $regions = DB::table('regions')->count();
        $this->artisan('delve:run', ['--seed' => 7])->assertExitCode(0);
        $this->assertSame($regions, DB::table('regions')->count());

        DB::table('characters')->where('id', $rey['id'])->update(['level' => 30]);
        $this->artisan('delve:run', ['--dry-run' => true, '--seed' => 7])
            ->expectsOutputToContain('Preview of a new map')
            ->assertExitCode(0);
        $this->assertSame($regions, DB::table('regions')->count());

        $this->artisan('delve:run', ['--seed' => 7])
            ->expectsOutputToContain('A new map opens')
            ->assertExitCode(0);
        $this->assertSame($regions + 1, DB::table('regions')->count());
        $region = DB::table('regions')->orderByDesc('sort_order')->first();
        $places = DB::table('places')->where('region_id', $region->id)->orderBy('min_level')->get();
        $this->assertCount(5, $places);
        $this->assertSame('dungeon', $places->last()->place_type);
        $this->assertSame(5, DB::table('place_materials as pm')
            ->join('material_templates as m', 'm.id', '=', 'pm.material_id')
            ->join('places as p', 'p.id', '=', 'pm.place_id')
            ->where('p.region_id', $region->id)
            ->where('m.kind', 'essence')
            ->count());
        $this->assertGreaterThan(30, (int) DB::table('places')->max('rec_max_level'));
        $this->artisan('delve:check-content')->assertExitCode(0);
    }

    public function test_a_second_run_is_blocked_while_one_is_running(): void
    {
        $this->makePlayer('rey');
        DB::connection('admin')->table('simulation_runs')->insert(['status' => 'running', 'seed' => 1]);
        $this->artisan('delve:run', ['--seed' => 42])->assertExitCode(2);
        $this->assertSame(0, DB::table('trips')->count());
    }

    public function test_the_run_lock_blocks_a_concurrent_process(): void
    {
        $this->makePlayer('rey');
        $holder = DB::connection('admin');
        $this->assertSame(1, (int) $holder->selectOne("SELECT GET_LOCK('delvebound_simulation', 0) AS got")->got);
        try {
            $this->artisan('delve:run', ['--seed' => 42])->assertExitCode(2);
        } finally {
            $holder->selectOne("SELECT RELEASE_LOCK('delvebound_simulation')");
        }
        $this->assertSame(0, DB::table('trips')->count());
    }
}
