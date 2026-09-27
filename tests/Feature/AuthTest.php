<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetGame();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('auth/Login'));
    }

    public function test_sign_up_creates_a_character_with_the_starter_kit(): void
    {
        $this->post('/register', [
            'username' => 'rey',
            'password' => 'correct horse battery',
            'password_confirmation' => 'correct horse battery',
            'character_name' => 'Rey',
            'weapon' => 'worn_dagger',
        ])->assertRedirect('/');

        $this->assertAuthenticated();
        $account = DB::table('accounts')->where('username', 'rey')->first();
        $this->assertSame(1, (int) $account->is_admin, 'the first account becomes the admin');
        $character = DB::table('characters')->where('account_id', $account->id)->first();
        $this->assertSame('Rey', $character->name);
        $this->assertSame(5, (int) $character->unspent_points);
        $weapon = DB::table('character_equipment as ce')->join('item_instances as ii', 'ii.id', '=', 'ce.item_instance_id')
            ->join('item_templates as it', 'it.id', '=', 'ii.template_id')
            ->where('ce.character_id', $character->id)->where('ce.slot', 'weapon')->value('it.code');
        $this->assertSame('worn_dagger', $weapon);

        [$second] = $this->makePlayer('mira');
        $this->assertFalse((bool) $second->is_admin);
    }

    public function test_sign_up_rejects_duplicates_and_bad_input(): void
    {
        $this->makePlayer('rey');
        $this->post('/register', [
            'username' => 'rey', 'password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery',
            'character_name' => 'Other', 'weapon' => 'rusty_sword',
        ])->assertSessionHas('error');
        $this->post('/register', [
            'username' => 'x', 'password' => 'short', 'password_confirmation' => 'nope',
            'character_name' => '<script>', 'weapon' => 'rusty_sword',
        ])->assertSessionHasErrors(['username', 'password', 'character_name']);
        $this->assertSame(1, DB::table('accounts')->count());
    }

    public function test_login_and_lockout_after_repeated_failures(): void
    {
        $this->makePlayer('rey');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'rey', 'password' => 'wrong password'])->assertSessionHasErrors('username');
        }
        $this->assertNotNull(DB::table('accounts')->where('username', 'rey')->value('locked_until'));

        $this->post('/login', ['username' => 'rey', 'password' => 'correct horse battery'])->assertSessionHasErrors('username');
        $this->assertGuest();

        DB::connection('admin')->table('accounts')->update(['locked_until' => null]);
        $this->post('/login', ['username' => 'rey', 'password' => 'correct horse battery'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_existing_bcrypt_hashes_still_log_in(): void
    {
        [$account] = $this->makePlayer('rey');
        DB::connection('admin')->table('accounts')->where('id', $account->id)
            ->update(['password_hash' => password_hash('legacy password 1', PASSWORD_BCRYPT)]);
        $this->post('/login', ['username' => 'rey', 'password' => 'legacy password 1'])->assertRedirect('/');
        $this->assertAuthenticated();
    }
}
