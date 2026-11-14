<?php

namespace App\Services;

use App\Exceptions\GameException;
use App\Game\Balance;
use App\Game\Daily;
use App\Game\Systems;
use Illuminate\Support\Facades\DB;

/** Daily quest claims and MU2 system upgrades (DESIGN.md §17). One transaction each. */
class DailyService
{
    public function __construct(private CharacterService $characters) {}

    private function bag(array $char): array
    {
        $raw = is_string($char['materials'] ?? null)
            ? (json_decode($char['materials'], true) ?: [])
            : (array) ($char['materials'] ?? []);
        $bag = [];
        foreach ($raw as $code => $qty) {
            if (is_string($code) && (int) $qty > 0) {
                $bag[$code] = (int) $qty;
            }
        }

        return $bag;
    }

    private function save(int $characterId, array $daily, array $systems, array $bag): void
    {
        ksort($bag);
        DB::table('characters')->where('id', $characterId)->update([
            'daily' => Daily::encode($daily),
            'systems' => Systems::encode($systems),
            'materials' => json_encode((object) $bag, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'row_version' => DB::raw('row_version + 1'),
        ]);
    }

    private function load(int $accountId, int $version): array
    {
        $char = $this->characters->lock($accountId);
        $this->characters->checkVersion($char, $version);
        $balance = Balance::get();
        $day = Daily::dayKey(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $balance['daily']['timezone']);

        return [
            'char' => $char,
            'balance' => $balance,
            'daily' => Daily::state($char['daily'] ?? null, $day, $balance),
            'systems' => Systems::state($char['systems'] ?? null, $balance),
            'bag' => $this->bag($char),
        ];
    }

    /** Visiting the board counts as the login quest. */
    public function touchLogin(int $accountId): void
    {
        DB::transaction(function () use ($accountId) {
            $char = $this->characters->lock($accountId);
            $balance = Balance::get();
            $day = Daily::dayKey(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $balance['daily']['timezone']);
            $daily = Daily::state($char['daily'] ?? null, $day, $balance);
            $before = (int) ($daily['counters']['login'] ?? 0);
            Daily::markLogin($daily);
            if ((int) ($daily['counters']['login'] ?? 0) === $before && ($char['daily'] ?? null) !== null) {
                return;
            }
            DB::table('characters')->where('id', $char['id'])->update([
                'daily' => Daily::encode($daily),
                'row_version' => DB::raw('row_version + 1'),
            ]);
        });
    }

    public function claimQuest(int $accountId, int $version, string $code): array
    {
        return DB::transaction(function () use ($accountId, $version, $code) {
            $ctx = $this->load($accountId, $version);
            if ($code === 'login') {
                Daily::markLogin($ctx['daily']);
            }
            [$ok, $why, $gained] = Daily::claimQuest($ctx['daily'], $ctx['bag'], $code, $ctx['balance']);
            if (! $ok) {
                throw new GameException($why);
            }
            $this->save((int) $ctx['char']['id'], $ctx['daily'], $ctx['systems'], $ctx['bag']);

            return $gained;
        });
    }

    public function claimChest(int $accountId, int $version, int $at): array
    {
        return DB::transaction(function () use ($accountId, $version, $at) {
            $ctx = $this->load($accountId, $version);
            [$ok, $why, $gained] = Daily::claimChest($ctx['daily'], $ctx['bag'], $at, $ctx['balance']);
            if (! $ok) {
                throw new GameException($why);
            }
            $this->save((int) $ctx['char']['id'], $ctx['daily'], $ctx['systems'], $ctx['bag']);

            return $gained;
        });
    }

    public function raiseJewel(int $accountId, int $version, string $element): int
    {
        return DB::transaction(function () use ($accountId, $version, $element) {
            $ctx = $this->load($accountId, $version);
            [$ok, $why] = Systems::canRaiseJewel($ctx['systems'], $ctx['bag'], $element, $ctx['balance']);
            if (! $ok) {
                throw new GameException($why);
            }
            Systems::raiseJewel($ctx['systems'], $ctx['bag'], $element, $ctx['balance']);
            $this->save((int) $ctx['char']['id'], $ctx['daily'], $ctx['systems'], $ctx['bag']);

            return $ctx['systems']['jewels'][$element];
        });
    }

    public function raiseExcellency(int $accountId, int $version): int
    {
        return DB::transaction(function () use ($accountId, $version) {
            $ctx = $this->load($accountId, $version);
            [$ok, $why] = Systems::canRaiseExcellency($ctx['systems'], $ctx['bag'], $ctx['balance']);
            if (! $ok) {
                throw new GameException($why);
            }
            Systems::raiseExcellency($ctx['systems'], $ctx['bag'], $ctx['balance']);
            $this->save((int) $ctx['char']['id'], $ctx['daily'], $ctx['systems'], $ctx['bag']);

            return $ctx['systems']['excellency'];
        });
    }

    public function raiseWing(int $accountId, int $version): int
    {
        return DB::transaction(function () use ($accountId, $version) {
            $ctx = $this->load($accountId, $version);
            [$ok, $why] = Systems::canRaiseWing($ctx['systems'], $ctx['bag'], $ctx['balance']);
            if (! $ok) {
                throw new GameException($why);
            }
            Systems::raiseWing($ctx['systems'], $ctx['bag'], $ctx['balance']);
            $this->save((int) $ctx['char']['id'], $ctx['daily'], $ctx['systems'], $ctx['bag']);

            return $ctx['systems']['wing_stars'];
        });
    }

    public function raiseSigil(int $accountId, int $version, string $element): int
    {
        return DB::transaction(function () use ($accountId, $version, $element) {
            $ctx = $this->load($accountId, $version);
            [$ok, $why] = Systems::canRaiseSigil($ctx['systems'], $ctx['bag'], $element, $ctx['balance']);
            if (! $ok) {
                throw new GameException($why);
            }
            Systems::raiseSigil($ctx['systems'], $ctx['bag'], $element, $ctx['balance']);
            $this->save((int) $ctx['char']['id'], $ctx['daily'], $ctx['systems'], $ctx['bag']);

            return $ctx['systems']['sigils'][$element];
        });
    }

    public function raiseNexus(int $accountId, int $version): int
    {
        return DB::transaction(function () use ($accountId, $version) {
            $ctx = $this->load($accountId, $version);
            [$ok, $why] = Systems::canRaiseNexus($ctx['systems'], $ctx['bag'], $ctx['balance']);
            if (! $ok) {
                throw new GameException($why);
            }
            Systems::raiseNexus($ctx['systems'], $ctx['bag'], $ctx['balance']);
            $this->save((int) $ctx['char']['id'], $ctx['daily'], $ctx['systems'], $ctx['bag']);

            return $ctx['systems']['sigil_nexus'];
        });
    }
}
