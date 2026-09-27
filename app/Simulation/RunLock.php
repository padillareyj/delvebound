<?php

namespace App\Simulation;

use Illuminate\Database\Connection;

/** Process lock and the simulation_runs lifecycle (DESIGN.md §10.3). */
final class RunLock
{
    public const LOCK_NAME = 'delvebound_simulation';

    public function __construct(private Connection $db) {}

    public function acquire(): void
    {
        $locked = (int) $this->db->selectOne('SELECT GET_LOCK(?, 0) AS locked', [self::LOCK_NAME])->locked;
        if ($locked !== 1) {
            $holder = $this->db->selectOne("SELECT id, host, pid FROM simulation_runs WHERE status = 'running' ORDER BY id DESC LIMIT 1");
            if ($holder) {
                throw new RunBlocked("Another simulation holds the lock (run {$holder->id} on ".($holder->host ?: '?').' pid '.($holder->pid ?: '?').').');
            }
            $arena = $this->db->selectOne("SELECT id, host, pid FROM arena_tournaments WHERE status = 'running' ORDER BY id DESC LIMIT 1");
            $who = $arena ? " (arena {$arena->id} on ".($arena->host ?: '?').' pid '.($arena->pid ?: '?').')' : '';
            throw new RunBlocked("Another simulation holds the lock{$who}.");
        }
    }

    public function release(): void
    {
        $this->db->select('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
    }

    public function refuseIfBlocked(?int $resumeId, bool $dryRun): void
    {
        $running = $this->db->selectOne("SELECT id, host, pid FROM simulation_runs WHERE status = 'running' LIMIT 1");
        if ($running) {
            throw new RunBlocked("Run {$running->id} is still marked running on {$running->host} pid {$running->pid}. If that process is dead, use --force-unlock.");
        }
        if ($dryRun) {
            return;
        }
        $latest = $this->latest();
        if ($latest && $latest['status'] === 'failed' && $resumeId !== (int) $latest['id']) {
            throw new RunBlocked("Run {$latest['id']} failed and is unfinished. Use --resume={$latest['id']} or --abandon={$latest['id']}.");
        }
    }

    public function latest(): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM simulation_runs ORDER BY id DESC LIMIT 1');

        return $row ? (array) $row : null;
    }

    public function forceUnlock(int $staleMinutes): int
    {
        $row = $this->db->selectOne("SELECT * FROM simulation_runs WHERE status = 'running' ORDER BY id DESC LIMIT 1");
        if (! $row) {
            throw new RunBlocked('No running simulation to unlock.');
        }
        $age = (int) $this->db->selectOne('SELECT TIMESTAMPDIFF(MINUTE, ?, UTC_TIMESTAMP()) AS age', [$row->heartbeat_at])->age;
        if ($age < $staleMinutes) {
            throw new RunBlocked("Run {$row->id} heartbeat is only {$age} minute(s) old. Refusing to unlock a live run.");
        }
        $this->db->update(
            "UPDATE simulation_runs SET status = 'failed', finished_at = UTC_TIMESTAMP(),
                    error_text = CONCAT(IFNULL(error_text, ''), '\nforce-unlocked')
              WHERE id = ? AND status = 'running'",
            [$row->id],
        );

        return (int) $row->id;
    }

    public function abandon(int $runId): void
    {
        $changed = $this->db->update(
            "UPDATE simulation_runs SET status = 'abandoned', finished_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'failed'",
            [$runId],
        );
        if ($changed !== 1) {
            throw new RunBlocked("Run {$runId} is not a failed run, so it cannot be abandoned.");
        }
    }

    public function begin(int $seed, string $host, int $pid, array $args, ?string $codeVersion, int $total): array
    {
        $this->db->insert(
            "INSERT INTO simulation_runs (status, seed, host, pid, args, code_version, characters_total, heartbeat_at)
             VALUES ('running', ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())",
            [$seed, mb_substr($host, 0, 64), $pid, json_encode(array_values($args)), $codeVersion, $total],
        );

        return (array) $this->db->selectOne('SELECT * FROM simulation_runs WHERE id = ?', [(int) $this->db->getPdo()->lastInsertId()]);
    }

    public function resume(int $runId): array
    {
        $row = $this->db->selectOne('SELECT * FROM simulation_runs WHERE id = ?', [$runId]);
        if (! $row || $row->status !== 'failed') {
            throw new RunBlocked("Run {$runId} cannot be resumed (it must be failed).");
        }
        $this->db->update(
            "UPDATE simulation_runs
                SET status = 'running', resume_count = resume_count + 1, finished_at = NULL,
                    heartbeat_at = UTC_TIMESTAMP(), error_text = NULL
              WHERE id = ? AND status = 'failed'",
            [$runId],
        );

        return (array) $this->db->selectOne('SELECT * FROM simulation_runs WHERE id = ?', [$runId]);
    }

    public function finish(int $runId, string $status, ?string $error = null): void
    {
        $this->db->update(
            'UPDATE simulation_runs SET status = ?, finished_at = UTC_TIMESTAMP(), heartbeat_at = UTC_TIMESTAMP(), error_text = ? WHERE id = ?',
            [$status, $error === null ? null : mb_substr($error, 0, 2000), $runId],
        );
    }

    public function pruneLogs(int $days): int
    {
        return $this->db->update(
            'UPDATE trip_encounters e JOIN trips t ON t.id = e.trip_id
                SET e.log = JSON_ARRAY()
              WHERE t.created_at < UTC_TIMESTAMP() - INTERVAL ? DAY AND JSON_LENGTH(e.log) > 0',
            [$days],
        );
    }
}
