<?php

namespace App\Simulation;

use App\Game\Arena;
use Closure;
use Illuminate\Database\Connection;
use Throwable;

/** Lock, enroll, resolve, apply (DESIGN.md §15). Output goes through $say. */
final class ArenaRunner
{
    private RunLock $lock;

    private ArenaRepo $repo;

    public function __construct(private Connection $db, private array $balance, private Closure $say) {}

    /**
     * @param  array{dry_run: bool, seed: int, force_unlock: bool, stale_minutes: int, display_tz: string}  $opts
     */
    public function execute(array $opts): int
    {
        $this->lock = new RunLock($this->db);
        $this->repo = new ArenaRepo($this->db);
        try {
            $this->lock->acquire();
        } catch (RunBlocked $e) {
            $this->say($e->getMessage(), 'error');

            return 2;
        }

        try {
            if ($opts['force_unlock']) {
                try {
                    $id = $this->repo->forceUnlock($opts['stale_minutes']);
                } catch (RunBlocked $e) {
                    $this->say($e->getMessage(), 'error');

                    return 2;
                }
                $this->say("Arena {$id} marked failed. Signups are still waiting; run delve:arena again.", 'warn');

                return 0;
            }
            if ($sim = $this->repo->simulationRunning()) {
                $this->say("Expedition run {$sim['id']} is still marked running. Finish or unlock it before the arena.", 'error');

                return 2;
            }
            if ($running = $this->repo->running()) {
                $this->say("Arena {$running['id']} is still marked running on {$running['host']} pid {$running['pid']}. If that process is dead, use --force-unlock.", 'error');

                return 2;
            }

            return $opts['dry_run'] ? $this->preview($opts) : $this->commit($opts);
        } catch (RunBlocked $e) {
            $this->say($e->getMessage(), 'error');

            return 2;
        } catch (Throwable $e) {
            $this->say($e->getMessage(), 'error');

            return 1;
        } finally {
            $this->lock->release();
        }
    }

    private function preview(array $opts): int
    {
        $ids = $this->repo->waitingIds();
        if (count($ids) < 2) {
            $this->say('Need at least 2 entrants. Bots step in on their own; humans sign up on the Arena page.', 'warn');

            return 0;
        }
        $fighters = $this->repo->fighters($ids, $this->balance);
        $bracket = Arena::bracket($fighters, $opts['seed'], $this->balance);
        $this->say("Preview (seed {$opts['seed']})", 'title');
        $this->printBracket($bracket, $fighters);
        $this->say('Nothing was saved.');

        return 0;
    }

    private function commit(array $opts): int
    {
        $this->db->beginTransaction();
        try {
            $ids = $this->repo->waitingIds();
            if (count($ids) < 2) {
                $this->db->rollBack();
                $this->say('Need at least 2 entrants. Bots step in on their own; humans sign up on the Arena page.', 'warn');

                return 0;
            }
            $opened = [
                'id' => $this->repo->open($opts['seed'], (string) gethostname(), (int) getmypid()),
                'ids' => $ids,
            ];
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }
            throw $e;
        }

        try {
            $this->db->transaction(function () use ($opts, $opened) {
                $fighters = $this->repo->fighters($opened['ids'], $this->balance);
                $bracket = Arena::bracket($fighters, $opts['seed'], $this->balance);
                $this->repo->apply($opened['id'], $bracket, $fighters, $this->balance, $opts['display_tz']);
                $this->say("Arena #{$opened['id']} (seed {$opts['seed']})", 'title');
                $this->printBracket($bracket, $fighters);
            });
        } catch (Throwable $e) {
            $this->repo->fail($opened['id'], $e->getMessage());
            throw $e;
        }

        return 0;
    }

    private function printBracket(array $bracket, array $fighters): void
    {
        $this->say(count($fighters).' entrants.');
        $round = null;
        foreach ($bracket['matches'] as $match) {
            if ($match['round'] !== $round) {
                $round = $match['round'];
                $this->say("Round {$round}");
            }
            if ($match['reason'] === 'bye') {
                $this->say("  {$match['a_name']} advances (bye)");

                continue;
            }
            $loserHp = (int) $match['winner_id'] === (int) $match['character_a_id'] ? $match['b_hp_end'] : $match['a_hp_end'];
            $winner = (int) $match['winner_id'] === (int) $match['character_a_id'] ? $match['a_name'] : $match['b_name'];
            $how = $match['reason'] === 'time' ? 'time' : 'knockout';
            $this->say("  {$match['a_name']} vs {$match['b_name']} → {$winner} ({$how}, {$match['rounds']} rounds, loser HP {$loserHp})");
        }
        $champ = $fighters[$bracket['champion_id']]['name'];
        $this->say("Champion: {$champ}");
    }

    private function say(string $line, string $level = 'info'): void
    {
        ($this->say)($line, $level);
    }
}
