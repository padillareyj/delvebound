<?php

namespace App\Simulation;

use App\Game\Arena;
use App\Game\Progression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;

/** Arena SQL on the sim connection. The bracket itself lives in App\Game\Arena. */
final class ArenaRepo
{
    private SimRepo $characters;

    public function __construct(private Connection $db)
    {
        $this->characters = new SimRepo($db);
    }

    public function running(): ?array
    {
        $row = $this->db->selectOne("SELECT * FROM arena_tournaments WHERE status = 'running' ORDER BY id DESC LIMIT 1");

        return $row ? (array) $row : null;
    }

    public function simulationRunning(): ?array
    {
        $row = $this->db->selectOne("SELECT id, host, pid FROM simulation_runs WHERE status = 'running' LIMIT 1");

        return $row ? (array) $row : null;
    }

    public function open(int $seed, string $host, int $pid): int
    {
        $this->db->insert(
            "INSERT INTO arena_tournaments (status, seed, host, pid, heartbeat_at) VALUES ('running', ?, ?, ?, UTC_TIMESTAMP())",
            [$seed, mb_substr($host, 0, 64), $pid],
        );

        return (int) $this->db->getPdo()->lastInsertId();
    }

    public function fail(int $tournamentId, string $error): void
    {
        $this->db->update(
            "UPDATE arena_tournaments SET status = 'failed', finished_at = UTC_TIMESTAMP(), error_text = ? WHERE id = ? AND status = 'running'",
            [mb_substr($error, 0, 2000), $tournamentId],
        );
    }

    public function forceUnlock(int $staleMinutes): int
    {
        $row = $this->running();
        if (! $row) {
            throw new RunBlocked('No running arena to unlock.');
        }
        $age = (int) $this->db->selectOne('SELECT TIMESTAMPDIFF(MINUTE, ?, UTC_TIMESTAMP()) AS age', [$row['heartbeat_at']])->age;
        if ($age < $staleMinutes) {
            throw new RunBlocked("Arena {$row['id']} started only {$age} minute(s) ago. Refusing to unlock a live arena.");
        }
        $this->fail((int) $row['id'], 'force-unlocked');

        return (int) $row['id'];
    }

    /** Active bots always enter. Humans enter only when arena_entered is set. @return list<int> */
    public function waitingIds(): array
    {
        return array_map(
            fn ($row) => (int) $row->id,
            $this->db->select(
                'SELECT id FROM characters WHERE is_active = 1 AND (is_bot = 1 OR arena_entered = 1) ORDER BY id',
            ),
        );
    }

    /** @param  list<int>  $ids
     * @return array<int, array> */
    public function fighters(array $ids, array $balance): array
    {
        $fighters = [];
        foreach ($ids as $id) {
            $char = $this->characters->loadCharacter($id);
            $char['equipment'] = array_values(array_filter($char['equipment']));
            $fighter = Arena::fighter($char, $balance);
            $fighters[$fighter['id']] = $fighter;
        }

        return $fighters;
    }

    /** Write matches, prizes, mail, and consumed signups. The caller holds the transaction. */
    public function apply(int $tournamentId, array $bracket, array $fighters, array $balance, string $displayTz): void
    {
        foreach ($bracket['matches'] as $match) {
            $this->db->insert(
                'INSERT INTO arena_matches (tournament_id, round_no, slot_no, character_a_id, character_b_id, winner_id, rounds, a_hp_end, b_hp_end, reason, log)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $tournamentId, $match['round'], $match['slot'], $match['character_a_id'], $match['character_b_id'],
                    $match['winner_id'], $match['rounds'], $match['a_hp_end'], $match['b_hp_end'], $match['reason'],
                    json_encode($match['log'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ],
            );
        }

        $when = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $ids = array_keys($fighters);
        sort($ids);
        foreach ($ids as $id) {
            $fighter = $fighters[$id];
            $this->db->selectOne('SELECT id FROM characters WHERE id = ? FOR UPDATE', [$id]);
            $row = (array) $this->db->selectOne('SELECT level, xp, gold, unspent_points FROM characters WHERE id = ?', [$id]);
            $wins = Arena::knockoutWins($id, $bracket['matches']);
            $champion = (int) $bracket['champion_id'] === $id;
            $purse = Arena::purse((int) $fighter['level'], $wins, $champion, $balance);
            $progress = Progression::applyXp((int) $row['level'], (int) $row['xp'], $purse['xp'], $balance);
            $gold = (int) $row['gold'] + $purse['gold'] + $progress['gold'];
            $this->db->update(
                'UPDATE characters
                    SET level = ?, xp = ?, total_xp = total_xp + ?, gold = ?, unspent_points = unspent_points + ?,
                        arena_entered = 0, row_version = row_version + 1
                  WHERE id = ?',
                [$progress['level'], $progress['xp'], $purse['xp'], $gold, $progress['points'], $id],
            );
            $this->db->insert(
                "INSERT INTO arena_signups (character_id, status, consumed_tournament_id) VALUES (?, 'consumed', ?)",
                [$id, $tournamentId],
            );
            $this->consumeUses($id, $fighter['uses']);
            [$subject, $body, $payload] = Arena::report($fighter, $bracket, $progress, $purse, $tournamentId, $when, $displayTz);
            $this->db->insert(
                "INSERT INTO mail (character_id, arena_tournament_id, mail_type, subject, body_text, payload)
                 VALUES (?, ?, 'arena_report', ?, ?, ?)",
                [$id, $tournamentId, $subject, $body, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            );
        }

        $this->db->update(
            "UPDATE arena_tournaments
                SET status = 'completed', finished_at = UTC_TIMESTAMP(), heartbeat_at = UTC_TIMESTAMP(),
                    champion_id = ?, entrant_count = ?
              WHERE id = ?",
            [$bracket['champion_id'], count($fighters), $tournamentId],
        );
    }

    private function consumeUses(int $characterId, array $uses): void
    {
        $qtyById = [];
        foreach ($uses as $use) {
            if (empty($use['id'])) {
                continue;
            }
            $qtyById[(int) $use['id']] = ($qtyById[(int) $use['id']] ?? 0) + (int) $use['qty'];
        }
        foreach ($qtyById as $consumableId => $used) {
            $row = $this->db->selectOne(
                'SELECT quantity, belt_qty FROM character_consumables WHERE character_id = ? AND consumable_id = ?',
                [$characterId, $consumableId],
            );
            if (! $row) {
                continue;
            }
            $qty = max(0, (int) $row->quantity - $used);
            $belt = min(max(0, (int) $row->belt_qty - $used), $qty);
            if ($qty === 0) {
                $this->db->delete(
                    'DELETE FROM character_consumables WHERE character_id = ? AND consumable_id = ?',
                    [$characterId, $consumableId],
                );
            } else {
                $this->db->update(
                    'UPDATE character_consumables SET quantity = ?, belt_qty = ? WHERE character_id = ? AND consumable_id = ?',
                    [$qty, $belt, $characterId, $consumableId],
                );
            }
        }
    }
}
