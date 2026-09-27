<?php

namespace App\Services;

use App\Exceptions\GameException;
use App\Game\Balance;
use App\Models\Account;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Sign-up with the starter kit, login throttling, and password changes (DESIGN.md §11.2). */
class AccountService
{
    public const USERNAME_RULE = '/^[A-Za-z0-9_]{3,32}$/';

    public const CHARACTER_NAME_RULE = "/^[A-Za-z0-9][A-Za-z0-9 '\\-]{1,31}$/";

    /** @return array<string, string> starting weapon code => template name, keyed by scaling stat */
    public function starterWeapons(): array
    {
        $codes = Balance::get()['character']['starter_weapons'];
        $names = DB::table('item_templates')->whereIn('code', array_values($codes))->pluck('name', 'code');
        $out = [];
        foreach ($codes as $stat => $code) {
            $out[] = ['code' => $code, 'name' => $names[$code] ?? $code, 'stat' => $stat];
        }

        return $out;
    }

    /** One transaction: account + character + starter kit. The first account ever becomes admin. */
    public function register(string $username, ?string $email, string $password, string $characterName, string $weaponCode): Account
    {
        $balance = Balance::get();
        $c = $balance['character'];
        if (! in_array($weaponCode, $c['starter_weapons'], true)) {
            throw new GameException('Pick one of the starting weapons.');
        }
        if (DB::table('characters')->where('name', $characterName)->exists()) {
            throw new GameException('That character name is taken.');
        }

        try {
            $accountId = DB::transaction(function () use ($username, $email, $password, $characterName, $weaponCode, $c) {
                $first = DB::table('accounts')->lockForUpdate()->count() === 0;
                $accountId = (int) DB::table('accounts')->insertGetId([
                    'username' => $username,
                    'email' => $email ?: null,
                    'password_hash' => Hash::make($password),
                    'is_admin' => $first ? 1 : 0,
                ]);
                $base = (int) $c['base_stat'];
                $cid = (int) DB::table('characters')->insertGetId([
                    'account_id' => $accountId,
                    'is_bot' => 0,
                    'name' => $characterName,
                    'gold' => (int) $c['starting_gold'],
                    'stat_str' => $base, 'stat_dex' => $base, 'stat_int' => $base, 'stat_vit' => $base, 'stat_luk' => $base,
                    'unspent_points' => (int) $c['starting_unspent'],
                    'retreat_threshold_pct' => (int) $c['default_retreat_pct'],
                    'potion_threshold_pct' => (int) $c['default_potion_pct'],
                ]);
                foreach ([$weaponCode, ...$c['starter_armor']] as $code) {
                    $template = DB::table('item_templates')->where('code', $code)->first();
                    if (! $template) {
                        throw new GameException("Starter item {$code} is missing from the content.");
                    }
                    $itemId = ShopService::insertPlainItem($cid, $template, 'starter');
                    DB::table('character_equipment')->insert(['character_id' => $cid, 'slot' => $template->slot, 'item_instance_id' => $itemId]);
                }
                $potionId = DB::table('consumable_templates')->where('code', $c['starting_potion_code'])->value('id');
                if ($potionId) {
                    DB::table('character_consumables')->insert([
                        'character_id' => $cid,
                        'consumable_id' => $potionId,
                        'quantity' => (int) $c['starting_potion_qty'],
                        'belt_qty' => (int) $c['starting_potion_belt'],
                    ]);
                }

                return $accountId;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                $message = $e->getMessage();
                throw new GameException(match (true) {
                    str_contains($message, 'uq_accounts_username') => 'That username is taken.',
                    str_contains($message, 'uq_accounts_email') => 'That email is already registered.',
                    str_contains($message, 'uq_characters_name') => 'That character name is taken.',
                    default => 'That account already exists.',
                });
            }
            throw $e;
        }

        return Account::findOrFail($accountId);
    }

    /** Check a login with the lockout rule. Returns the account, or the error message to show. */
    public function attempt(string $username, string $password): Account|string
    {
        $generic = 'Invalid username or password.';
        $account = Account::where('username', $username)->first();
        if (! $account) {
            return $generic;
        }
        if ($account->locked_until && $account->locked_until->isFuture()) {
            $minutes = (int) ceil(now()->diffInSeconds($account->locked_until) / 60);

            return "Too many failed attempts. Try again in {$minutes} minute(s).";
        }
        if (! Hash::check($password, $account->password_hash)) {
            $fails = (int) $account->failed_logins + 1;
            $update = ['failed_logins' => min(255, $fails)];
            if ($fails >= (int) config('delvebound.login_max_failures')) {
                $update = ['failed_logins' => 0, 'locked_until' => now()->utc()->addMinutes((int) config('delvebound.login_lock_minutes'))];
            }
            DB::table('accounts')->where('id', $account->id)->update($update);

            return $generic;
        }
        $update = ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()->utc()];
        if (Hash::needsRehash($account->password_hash)) {
            $update['password_hash'] = Hash::make($password);
        }
        DB::table('accounts')->where('id', $account->id)->update($update);

        return $account;
    }

    public function changePassword(Account $account, string $current, string $next): void
    {
        $hash = DB::table('accounts')->where('id', $account->id)->value('password_hash');
        if (! $hash || ! Hash::check($current, $hash)) {
            throw new GameException('The current password is wrong.');
        }
        DB::table('accounts')->where('id', $account->id)->update(['password_hash' => Hash::make($next)]);
    }
}
