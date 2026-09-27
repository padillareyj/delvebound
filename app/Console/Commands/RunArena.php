<?php

namespace App\Console\Commands;

use App\Game\Balance;
use App\Simulation\ArenaRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** The arena is manual: `php artisan delve:arena` (DESIGN.md §15). Nothing on the website can start one. */
class RunArena extends Command
{
    protected $signature = 'delve:arena
        {--dry-run : Resolve and print the bracket, then roll back}
        {--seed= : Fix the bracket seed}
        {--force-unlock : Mark a stale running arena as failed}';

    protected $description = 'Draw a random knockout bracket and fight it to a champion';

    public function handle(): int
    {
        $seed = $this->option('seed');
        if ($seed !== null && ! ctype_digit((string) $seed)) {
            $this->error('--seed must be a non-negative integer.');

            return 1;
        }
        $say = function (string $line, string $level): void {
            match ($level) {
                'title' => $this->line("<options=bold>{$line}</>"),
                'warn' => $this->warn($line),
                'error' => $this->error($line),
                default => $this->line($line),
            };
        };
        $runner = new ArenaRunner(DB::connection('sim'), Balance::get(), $say);

        return $runner->execute([
            'dry_run' => (bool) $this->option('dry-run'),
            'seed' => $seed === null ? random_int(0, PHP_INT_MAX) : (int) $seed,
            'force_unlock' => (bool) $this->option('force-unlock'),
            'stale_minutes' => (int) config('delvebound.stale_run_minutes'),
            'display_tz' => (string) config('delvebound.display_timezone'),
        ]);
    }
}
