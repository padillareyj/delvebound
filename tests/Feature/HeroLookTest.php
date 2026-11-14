<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HeroLookTest extends TestCase
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

    private function look(array $overrides = []): array
    {
        return array_merge([
            'gender' => 'female', 'race' => 'elf', 'skin' => '#F6EEE6', 'hair' => 'long', 'hair_color' => '#d8b26a',
            'beard' => 1, 'outfit' => 'ranger', 'height' => 106, 'build' => 95,
            'row_version' => (int) $this->fresh()['row_version'],
        ], $overrides);
    }

    public function test_saving_a_look_stores_it_without_touching_stats(): void
    {
        $before = $this->fresh();

        $this->actingAs($this->account)->post('/character/look', $this->look())
            ->assertRedirect()->assertSessionHasNoErrors();

        $after = $this->fresh();
        $look = json_decode($after['look'], true);
        $this->assertSame('elf', $look['race']);
        $this->assertSame('female', $look['gender']);
        $this->assertSame('#f6eee6', $look['skin']);
        $this->assertFalse($look['beard'], 'Female heroes never keep a beard.');
        $this->assertSame(106, $look['height']);
        $this->assertSame((int) $before['row_version'] + 1, (int) $after['row_version']);
        foreach (['level', 'xp', 'gold', 'stat_str', 'stat_dex', 'stat_int', 'stat_vit', 'stat_luk', 'unspent_points'] as $column) {
            $this->assertSame($before[$column], $after[$column], "{$column} must not change");
        }

        $this->actingAs($this->account)->get('/character')->assertOk()
            ->assertInertia(fn ($page) => $page->where('character.look.race', 'elf')->where('character.look.hair', 'long'));
    }

    public function test_invalid_looks_are_rejected(): void
    {
        $cases = [
            ['race' => 'dragon'],
            ['gender' => 'other'],
            ['skin' => 'red'],
            ['skin' => '#12345'],
            ['hair' => 'mohawk'],
            ['hair_color' => 'javascript:alert(1)'],
            ['outfit' => 'plate'],
            ['height' => 150],
            ['build' => 50],
        ];
        foreach ($cases as $bad) {
            $field = array_key_first($bad);
            $this->actingAs($this->account)->post('/character/look', $this->look($bad))->assertSessionHasErrors($field);
        }
        $this->assertNull($this->fresh()['look']);
    }

    public function test_a_stale_row_version_is_refused(): void
    {
        $this->actingAs($this->account)->post('/character/look', $this->look(['row_version' => 9999]));
        $this->assertNull($this->fresh()['look']);
    }

    public function test_guests_cannot_save_a_look(): void
    {
        $this->post('/character/look', $this->look())->assertRedirect('/login');
        $this->assertNull($this->fresh()['look']);
    }
}
