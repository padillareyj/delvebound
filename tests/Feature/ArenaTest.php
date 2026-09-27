<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArenaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetGame();
    }

    public function test_arena_page_requires_login_and_signup_is_only_yours(): void
    {
        [$rey, $reyChar] = $this->makePlayer('rey');
        [$mira, $miraChar] = $this->makePlayer('mira');

        $this->get('/arena')->assertRedirect('/login');
        $this->actingAs($rey)->get('/arena')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Arena')->where('signedUp', false)->has('roster', 6));

        $this->actingAs($rey)->post('/arena/enter', ['row_version' => $reyChar['row_version']])->assertRedirect();
        $this->assertSame(1, (int) DB::table('characters')->where('id', $reyChar['id'])->value('arena_entered'));
        $version = (int) DB::table('characters')->where('id', $reyChar['id'])->value('row_version');
        $this->actingAs($rey)->post('/arena/enter', ['row_version' => $version]);
        $this->assertSame(1, (int) DB::table('characters')->where('id', $reyChar['id'])->value('arena_entered'));

        $this->actingAs($mira)->delete('/arena/enter', ['row_version' => $miraChar['row_version']]);
        $this->assertSame(1, (int) DB::table('characters')->where('id', $reyChar['id'])->value('arena_entered'));

        $version = (int) DB::table('characters')->where('id', $reyChar['id'])->value('row_version');
        $this->actingAs($rey)->delete('/arena/enter', ['row_version' => $version])->assertRedirect();
        $this->assertSame(0, (int) DB::table('characters')->where('id', $reyChar['id'])->value('arena_entered'));
    }

    public function test_dry_run_is_deterministic_and_writes_nothing(): void
    {
        [$rey, $reyChar] = $this->makePlayer('rey');
        DB::table('characters')->where('id', $reyChar['id'])->update(['arena_entered' => 1]);
        $before = $this->counts();

        $this->assertSame(0, Artisan::call('delve:arena', ['--dry-run' => true, '--seed' => 42]));
        $first = Artisan::output();
        $this->assertSame(0, Artisan::call('delve:arena', ['--dry-run' => true, '--seed' => 42]));
        $second = Artisan::output();

        $this->assertSame($first, $second);
        $this->assertStringContainsString('Champion:', $first);
        $this->assertSame($before, $this->counts());
        $this->assertSame(1, (int) DB::table('characters')->where('id', $reyChar['id'])->value('arena_entered'));
    }

    public function test_a_real_arena_knocks_someone_out_and_pays_the_champion(): void
    {
        [$rey, $reyChar] = $this->makePlayer('rey');
        [$mira] = $this->makePlayer('mira');
        DB::table('characters')->where('id', $reyChar['id'])->update(['arena_entered' => 1]);
        $goldBefore = (int) $reyChar['gold'];

        $this->artisan('delve:arena', ['--seed' => 42])->assertExitCode(0);

        $tournament = DB::table('arena_tournaments')->first();
        $this->assertSame('completed', $tournament->status);
        $this->assertNotNull($tournament->champion_id);
        $this->assertSame(7, (int) $tournament->entrant_count);

        $knockouts = DB::table('arena_matches')->where('tournament_id', $tournament->id)->where('reason', 'knockout')->get();
        $this->assertNotEmpty($knockouts);
        foreach ($knockouts as $match) {
            $this->assertSame(0, min((int) $match->a_hp_end, (int) $match->b_hp_end));
        }

        $this->assertSame('consumed', DB::table('arena_signups')->where('character_id', $reyChar['id'])->value('status'));
        $this->assertSame(0, (int) DB::table('characters')->where('id', $reyChar['id'])->value('arena_entered'));
        $this->assertSame(0, DB::table('mail')->where('character_id', DB::table('characters')->where('account_id', $mira->id)->value('id'))->where('mail_type', 'arena_report')->count());
        $letter = DB::table('mail')->where('character_id', $reyChar['id'])->where('mail_type', 'arena_report')->first();
        $this->assertNotNull($letter);
        $this->assertSame((int) $tournament->id, (int) $letter->arena_tournament_id);

        $champion = DB::table('characters')->where('id', $tournament->champion_id)->first();
        $this->assertGreaterThan(0, (int) $champion->gold);
        if ((int) $champion->id === (int) $reyChar['id']) {
            $this->assertGreaterThan($goldBefore, (int) $champion->gold);
        }
        $this->assertSame(0, (int) DB::table('characters')->where('id', $reyChar['id'])->value('injury_severity'));
    }

    private function counts(): array
    {
        return [
            'tournaments' => DB::table('arena_tournaments')->count(),
            'matches' => DB::table('arena_matches')->count(),
            'mail' => DB::table('mail')->count(),
            'signups' => DB::table('arena_signups')->count(),
        ];
    }
}
