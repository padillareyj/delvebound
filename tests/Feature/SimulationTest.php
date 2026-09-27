<?php

namespace Tests\Feature;

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
        $this->assertSame($characters, DB::table('trips')->where('run_id', $run->id)->count());
        $this->assertSame($characters, DB::table('mail')->where('run_id', $run->id)->count());
        $this->assertSame(6, DB::table('bot_decisions')->where('run_id', $run->id)->count());
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
