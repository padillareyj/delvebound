<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Daily quest board and system upgrades (DESIGN.md §17). */
class DailyQuestsTest extends TestCase
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

    private function write(string $url, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->account)->from('/quests')->post($url, $data + ['row_version' => (int) $this->fresh()['row_version']]);
    }

    public function test_quests_page_marks_login_and_lists_board(): void
    {
        $this->actingAs($this->account)->get('/quests')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Quests')
            ->has('quests')
            ->has('chests')
            ->has('systems.jewels', 5)
            ->has('systems.sigils', 5)
            ->where('systems.bag.jewel_dust', 0)
            ->where('systems.bag.sigil_ink', 0));

        $daily = json_decode($this->fresh()['daily'], true);
        $this->assertSame(1, $daily['counters']['login'] ?? null);
    }

    public function test_claim_login_quest_grants_jewel_dust(): void
    {
        $this->actingAs($this->account)->get('/quests');
        $this->write('/quests/claim', ['code' => 'login'])->assertSessionHas('success');

        $char = $this->fresh();
        $bag = json_decode($char['materials'], true);
        $this->assertSame(1, (int) ($bag['jewel_dust'] ?? 0));
        $daily = json_decode($char['daily'], true);
        $this->assertContains('login', $daily['claimed']);

        $this->write('/quests/claim', ['code' => 'login'])->assertSessionHas('error');
    }

    public function test_jewel_upgrade_spends_dust_and_raises_detail_stats(): void
    {
        DB::table('characters')->where('id', $this->character['id'])->update([
            'materials' => json_encode(['jewel_dust' => 5]),
        ]);
        $before = $this->actingAs($this->account)->get('/character?tab=detail')->viewData('page')['props'];
        $this->assertSame(0, $before['derived']['advanced']['elements']['fire']['atk']);

        $this->write('/quests/jewel', ['element' => 'fire'])->assertSessionHas('success');

        $systems = json_decode($this->fresh()['systems'], true);
        $this->assertSame(1, $systems['jewels']['fire']);
        $bag = json_decode($this->fresh()['materials'], true);
        $this->assertSame(4, (int) $bag['jewel_dust']);

        $after = $this->actingAs($this->account)->get('/character?tab=detail')->viewData('page')['props'];
        $this->assertSame(25, $after['derived']['advanced']['elements']['fire']['atk']);
        $this->assertGreaterThan($before['power'], $after['power']);
    }

    public function test_sigil_upgrade_spends_ink_and_unlocks_nexus_gate(): void
    {
        DB::table('characters')->where('id', $this->character['id'])->update([
            'materials' => json_encode(['sigil_ink' => 12]),
            'systems' => json_encode([
                'jewels' => (object) [],
                'excellency' => 0,
                'wing_stars' => 0,
                'sigils' => ['fire' => 1, 'water' => 1, 'thunder' => 1, 'earth' => 1, 'wind' => 0],
                'sigil_nexus' => 0,
            ]),
        ]);

        $this->write('/quests/sigil', ['element' => 'wind'])->assertSessionHas('success');
        $systems = json_decode($this->fresh()['systems'], true);
        $this->assertSame(1, $systems['sigils']['wind']);
        $this->assertSame(11, (int) json_decode($this->fresh()['materials'], true)['sigil_ink']);

        $this->write('/quests/nexus', [])->assertSessionHas('success');
        $systems = json_decode($this->fresh()['systems'], true);
        $this->assertSame(1, $systems['sigil_nexus']);
        $this->assertSame(9, (int) json_decode($this->fresh()['materials'], true)['sigil_ink']);

        $detail = $this->actingAs($this->account)->get('/character?tab=detail')->viewData('page')['props'];
        $this->assertSame(3.0, $detail['derived']['advanced']['elements']['fire']['crit_bonus_pct']);
        $this->assertGreaterThan(0, $detail['derived']['advanced']['global']['final_dmg_bonus_pct']);
    }
}
