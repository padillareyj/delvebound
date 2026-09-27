<?php

namespace App\Console\Commands;

use App\Exceptions\GameException;
use App\Services\AccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Create an account + character without the website (dev and recovery). */
class CreateAccount extends Command
{
    protected $signature = 'delve:create-account
        {--username= : Login name}
        {--password= : Password (asked for if omitted)}
        {--character= : Character name}
        {--weapon=rusty_sword : Starting weapon code}
        {--email= : Optional email}';

    protected $description = 'Create an account and its character with the starter kit';

    public function handle(AccountService $accounts): int
    {
        $data = [
            'username' => $this->option('username') ?? $this->ask('Username'),
            'password' => $this->option('password') ?? $this->secret('Password (min 10 characters)'),
            'character_name' => $this->option('character') ?? $this->ask('Character name'),
            'weapon' => $this->option('weapon'),
            'email' => $this->option('email'),
        ];
        $validator = Validator::make($data, [
            'username' => ['required', 'regex:'.AccountService::USERNAME_RULE],
            'password' => ['required', Password::min(10)],
            'character_name' => ['required', 'regex:'.AccountService::CHARACTER_NAME_RULE],
            'email' => ['nullable', 'email', 'max:190'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return 1;
        }
        try {
            $account = $accounts->register($data['username'], $data['email'], $data['password'], $data['character_name'], $data['weapon']);
        } catch (GameException $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $this->info("Created account #{$account->id} ({$account->username})".($account->is_admin ? ' as admin' : '').'.');

        return 0;
    }
}
