<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MakeAdmin extends Command
{
    protected $signature = 'delve:make-admin {--username= : Account to promote} {--revoke : Remove admin instead}';

    protected $description = 'Grant (or revoke) admin rights for an account';

    public function handle(): int
    {
        $username = $this->option('username') ?? $this->ask('Username');
        $changed = DB::connection('admin')->table('accounts')->where('username', $username)
            ->update(['is_admin' => $this->option('revoke') ? 0 : 1]);
        $exists = DB::connection('admin')->table('accounts')->where('username', $username)->exists();
        if (! $exists) {
            $this->error("No account named {$username}.");

            return 1;
        }
        $this->info($this->option('revoke') ? "{$username} is no longer an admin." : "{$username} is an admin".($changed ? '.' : ' (already was).'));

        return 0;
    }
}
