<?php

namespace App\Simulation;

use App\Game\Bots\BotPlanner;
use App\Game\MailReport;
use App\Game\Rng;
use App\Game\Trip;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The run pipeline (DESIGN.md §1.3, §10.4): lock → bots → snapshot → resolve → apply.
 * Output goes through $say(string $line, string $level) so the console command decides what to print.
 */
final class Runner
{
    private RunLock $lock;

    private SimRepo $repo;

    public function __construct(private Connection $db, private array $balance, private Closure $say) {}

    /**
     * @param  array{dry_run: bool, seed: int, only: list<int>, resume: ?int, abandon: ?int, force_unlock: bool,
     *               skip_bots: bool, bots_only: bool, commit_bot_orders: bool, prune_logs: bool, verbose: bool,
     *               argv: list<string>, stale_minutes: int, retention_days: int, display_tz: string}  $opts
     * @return int exit code: 0 ok, 1 error, 2 blocked
     */
    public function execute(array $opts): int
    {
        $this->lock = new RunLock($this->db);
        $this->repo = new SimRepo($this->db);
        try {
            $this->lock->acquire();
        } catch (RunBlocked $e) {
            $this->say($e->getMessage(), 'error');

            return 2;
        }
        $dry = $opts['dry_run'] || ($opts['bots_only'] && ! $opts['commit_bot_orders']);
        $outer = false;
        $run = null;
        try {
            if ($opts['prune_logs']) {
                $removed = $this->lock->pruneLogs($opts['retention_days']);
                $this->log('info', "pruned {$removed} encounter logs");
                $this->say("Pruned the battle logs of {$removed} encounters older than {$opts['retention_days']} days.");

                return 0;
            }
            if ($opts['force_unlock']) {
                $id = $this->lock->forceUnlock($opts['stale_minutes']);
                $this->log('warning', "force-unlocked run {$id}");
                $this->say("Run {$id} marked failed. Use --resume={$id} or --abandon={$id}.", 'warn');
                if (! $opts['resume'] && ! $opts['abandon']) {
                    return 0;
                }
            }
            if ($opts['abandon']) {
                $this->lock->abandon($opts['abandon']);
                $this->log('warning', "abandoned run {$opts['abandon']}");
                $this->say("Run {$opts['abandon']} abandoned.");

                return 0;
            }
            if ($opts['only'] && ! $opts['dry_run'] && ! $opts['resume']) {
                $this->say('--only-character is allowed only with --dry-run or --resume.', 'error');

                return 1;
            }
            $this->lock->refuseIfBlocked($opts['resume'], $dry);
            if ($dry) {
                $this->db->beginTransaction();
                $outer = true;
            }
            $ids = $this->repo->activeCharacterIds();
            if ($opts['resume']) {
                $run = $this->lock->resume($opts['resume']);
            } else {
                $run = $this->lock->begin($opts['seed'], (string) gethostname(), (int) getmypid(), $opts['argv'], $this->codeVersion(), count($ids));
            }
            $runId = (int) $run['id'];
            $this->log('info', "run {$runId} seed {$run['seed']} dry=".($dry ? 'yes' : 'no'));
            $this->say(($dry ? 'Preview of run' : 'Run')." #{$runId} (seed {$run['seed']})", 'title');

            $world = $this->repo->loadWorld();
            foreach ($world['places'] as $place) {
                if ($place['place_type'] !== 'rest' && $place['spawns'] === []) {
                    $this->log('warning', "place {$place['code']} has no monsters");
                    $this->say("Warning: {$place['name']} has no monsters.", 'warn');
                }
            }

            if (! $opts['skip_bots']) {
                $this->botPhase($run, $world, $opts['only'], $opts['bots_only'] || $opts['verbose']);
            }
            if ($opts['bots_only']) {
                if (! $dry) {
                    $this->lock->finish($runId, 'completed');
                }
                $this->say($dry ? 'Bot decisions previewed and rolled back.' : "Run #{$runId}: bot orders committed.");

                return 0;
            }

            $plans = $this->repo->snapshotOrders($run, $world);
            $counts = [];
            foreach ($plans as $characterId => $plan) {
                if ($opts['only'] && ! in_array($characterId, $opts['only'], true)) {
                    continue;
                }
                if ($this->repo->tripExists($runId, $characterId)) {
                    continue;
                }
                $ctx = $this->repo->loadTripContext($characterId, $plan, $world, $this->balance, $run);
                $result = Trip::resolve($ctx, Rng::trip((int) $run['seed'], $characterId));
                $mail = MailReport::render($result, $runId, $run['started_at'], $ctx['character']['trips_total'] + 1, $opts['display_tz']);
                $this->db->transaction(fn () => $this->repo->applyTrip($run, $ctx, $result, $mail));
                $counts[$result['outcome']] = ($counts[$result['outcome']] ?? 0) + 1;
                $name = $ctx['character']['name'];
                $this->log('info', sprintf(
                    'run=%d char=%d(%s) place=%s outcome=%s xp=%d gold=%d lvl=%d→%d',
                    $runId, $characterId, $name, $result['place']['code'], $result['outcome'],
                    $result['xp_gained'], $result['gold_gained'], $result['level_before'], $result['level_after'],
                ));
                $this->say(sprintf(
                    '  %-14s %-18s %-15s +%d XP  +%d g%s',
                    $name, $result['place']['name'], strtoupper($result['outcome']), $result['xp_gained'], $result['gold_gained'],
                    $result['level_after'] !== $result['level_before'] ? "  LEVEL {$result['level_after']}!" : '',
                ));
                if ($dry || $opts['verbose']) {
                    $this->say($mail[0], 'detail');
                    $this->say($mail[1], 'detail');
                }
            }
            if (! $dry) {
                $this->lock->finish($runId, 'completed');
            }
            ksort($counts);
            $summary = implode(', ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($counts), $counts)) ?: 'no characters';
            $this->say("Run #{$runId} ".($dry ? 'preview (rolled back)' : 'completed').": {$summary}", 'title');

            return 0;
        } catch (RunBlocked $e) {
            $this->say($e->getMessage(), 'error');

            return 2;
        } catch (Throwable $e) {
            Log::channel('sim')->error('run failed', ['exception' => $e]);
            $this->say('Run failed: '.$e->getMessage(), 'error');
            if (! $outer && $run !== null) {
                try {
                    while ($this->db->transactionLevel() > 0) {
                        $this->db->rollBack();
                    }
                    $this->lock->finish((int) $run['id'], 'failed', $e->getMessage());
                    $this->say("Run #{$run['id']} marked failed. Fix the problem, then use --resume={$run['id']} (or --abandon={$run['id']}).", 'error');
                } catch (Throwable $inner) {
                    Log::channel('sim')->error('could not mark the run failed', ['exception' => $inner]);
                }
            }

            return 1;
        } finally {
            if ($outer) {
                while ($this->db->transactionLevel() > 0) {
                    $this->db->rollBack();
                }
            }
            $this->lock->release();
        }
    }

    /** One transaction per bot (DESIGN.md §8.2). */
    private function botPhase(array $run, array $world, array $only, bool $print): void
    {
        $runId = (int) $run['id'];
        foreach ($this->repo->activeCharacterIds() as $characterId) {
            if ($only && ! in_array($characterId, $only, true)) {
                continue;
            }
            $made = $this->db->transaction(function () use ($run, $runId, $world, $characterId) {
                if ($this->repo->botDecisionExists($runId, $characterId)) {
                    return null;
                }
                $state = $this->repo->loadCharacter($characterId);
                if (! $state['is_bot']) {
                    return null;
                }
                $this->repo->openSealedBoxes($characterId, $world, $this->balance);
                $state = $this->repo->loadCharacter($characterId);
                $decision = BotPlanner::plan($state, $world['places'], $world['shop'], Rng::bot((int) $run['seed'], $characterId), $this->balance);
                $this->repo->applyBot($run, $state, $decision);

                return [$state['name'], $decision];
            });
            if ($made === null) {
                continue;
            }
            [$name, $decision] = $made;
            $this->log('info', "bot {$characterId}({$name}) -> {$decision['place']}");
            if ($print) {
                $bought = implode(', ', array_column($decision['bought'], 'code')) ?: 'nothing';
                $points = implode(' ', array_map(fn ($s, $n) => "+{$n} {$s}", array_keys($decision['points']), $decision['points'])) ?: 'no points';
                $this->say("  bot {$name}: {$points}; bought {$bought}; heading to {$decision['place']}", 'detail');
            }
        }
    }

    private function codeVersion(): ?string
    {
        $head = base_path('.git/HEAD');
        if (! is_file($head)) {
            return null;
        }
        $text = trim((string) file_get_contents($head));
        if (str_starts_with($text, 'ref:')) {
            $ref = base_path('.git/'.trim(substr($text, 4)));
            if (! is_file($ref)) {
                return null;
            }
            $text = trim((string) file_get_contents($ref));
        }

        return substr($text, 0, 12);
    }

    private function say(string $line, string $level = 'info'): void
    {
        ($this->say)($line, $level);
    }

    private function log(string $level, string $message): void
    {
        Log::channel('sim')->log($level, $message);
    }
}
