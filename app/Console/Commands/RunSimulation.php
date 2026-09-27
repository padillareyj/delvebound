<?php

namespace App\Console\Commands;

use App\Game\Balance;
use App\Simulation\Runner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Runs are manual: `php artisan delve:run` (DESIGN.md §10). Nothing on the website can start one. */
class RunSimulation extends Command
{
    protected $signature = 'delve:run
        {--dry-run : Resolve and print everything, then roll back}
        {--seed= : Fix the run seed}
        {--only-character=* : Only these character ids (with --dry-run or --resume)}
        {--resume= : Continue a failed run with its original seed}
        {--abandon= : Mark a failed run abandoned so new runs are allowed}
        {--force-unlock : Mark a stale running run as failed}
        {--skip-bots : Skip the bot phase}
        {--bots-only : Run only the bot phase (rolled back unless --commit-bot-orders)}
        {--commit-bot-orders : With --bots-only, keep the bot decisions}
        {--prune-logs : Trim old battle logs, then exit}';

    protected $description = 'Resolve one Delvebound simulation run';

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
        $runner = new Runner(DB::connection('sim'), Balance::get(), $say);

        return $runner->execute([
            'dry_run' => (bool) $this->option('dry-run'),
            'seed' => $seed === null ? random_int(0, PHP_INT_MAX) : (int) $seed,
            'only' => array_map('intval', (array) $this->option('only-character')),
            'resume' => $this->option('resume') !== null ? (int) $this->option('resume') : null,
            'abandon' => $this->option('abandon') !== null ? (int) $this->option('abandon') : null,
            'force_unlock' => (bool) $this->option('force-unlock'),
            'skip_bots' => (bool) $this->option('skip-bots'),
            'bots_only' => (bool) $this->option('bots-only'),
            'commit_bot_orders' => (bool) $this->option('commit-bot-orders'),
            'prune_logs' => (bool) $this->option('prune-logs'),
            'verbose' => $this->output->isVerbose(),
            'argv' => array_slice($_SERVER['argv'] ?? [], 2),
            'stale_minutes' => (int) config('delvebound.stale_run_minutes'),
            'retention_days' => (int) config('delvebound.log_retention_days'),
            'display_tz' => (string) config('delvebound.display_timezone'),
        ]);
    }
}
