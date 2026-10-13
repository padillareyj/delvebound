<?php

namespace App\Services;

use App\Exceptions\GameException;
use App\Game\Balance;
use App\Game\Premium;
use Illuminate\Support\Facades\DB;

/**
 * Premium store writes (DESIGN.md §16). One transaction each: lock the character, check row_version,
 * apply the rule from App\Game\Premium, write characters.premium_cash / premium (and gold for a bundle),
 * and log the purchase in shop_transactions. Money is play money; nothing leaves the server.
 */
class PremiumService
{
    public function __construct(private CharacterService $characters, private GameQueries $queries) {}

    private function item(string $code): array
    {
        $item = $this->queries->premiumCatalog()[$code] ?? null;
        if ($item === null) {
            throw new GameException('That item is not in the premium store.');
        }

        return $item;
    }

    private function save(int $characterId, array $state, ?int $gold = null): void
    {
        $sets = [
            'premium_cash' => (int) $state['cash'],
            'premium' => Premium::encode($state),
            'row_version' => DB::raw('row_version + 1'),
        ];
        if ($gold !== null) {
            $sets['gold'] = $gold;
        }
        DB::table('characters')->where('id', $characterId)->update($sets);
    }

    /** @return array{0: string, 1: int} [item name, gold granted] */
    public function buy(int $accountId, int $version, string $code): array
    {
        return DB::transaction(function () use ($accountId, $version, $code) {
            $char = $this->characters->lock($accountId);
            $this->characters->checkVersion($char, $version);
            $item = $this->item($code);
            $state = $this->queries->premiumState($char);
            [$ok, $why] = Premium::canBuy($item, $state);
            if (! $ok) {
                throw new GameException($why);
            }
            $gold = Premium::buy($state, $item, $this->queries->premiumCatalog());
            $cid = (int) $char['id'];
            $goldAfter = (int) $char['gold'] + $gold;
            $this->save($cid, $state, $gold > 0 ? $goldAfter : null);
            DB::table('shop_transactions')->insert([
                'character_id' => $cid,
                'actor' => 'web',
                'action' => 'premium',
                'premium_item_id' => $item['id'],
                'quantity' => 1,
                'gold_delta' => $gold,
                'gold_after' => $goldAfter,
                'cash_delta' => -$item['price_cents'],
                'cash_after' => (int) $state['cash'],
            ]);

            return [$item['name'], $gold];
        });
    }

    public function wear(int $accountId, int $version, string $code): string
    {
        return DB::transaction(function () use ($accountId, $version, $code) {
            $char = $this->characters->lock($accountId);
            $this->characters->checkVersion($char, $version);
            $item = $this->item($code);
            $state = $this->queries->premiumState($char);
            [$ok, $why] = Premium::canWear($item, $state);
            if (! $ok) {
                throw new GameException($why);
            }
            Premium::wear($state, $code, $this->queries->premiumCatalog());
            $this->save((int) $char['id'], $state);

            return $item['name'];
        });
    }

    public function setActive(int $accountId, int $version, string $code, bool $on): string
    {
        return DB::transaction(function () use ($accountId, $version, $code, $on) {
            $char = $this->characters->lock($accountId);
            $this->characters->checkVersion($char, $version);
            $item = $this->item($code);
            $state = $this->queries->premiumState($char);
            if (! in_array($code, $state['owned'], true)) {
                throw new GameException("You do not own {$item['name']}.");
            }
            if (in_array($code, $state['active'], true) === $on) {
                throw new GameException($item['name'].($on ? ' is already active.' : ' is not active.'));
            }
            Premium::setActive($state, $code, $on, $this->queries->premiumCatalog());
            $this->save((int) $char['id'], $state);

            return $item['name'];
        });
    }

    /** @return int pieces newly activated */
    public function activateAll(int $accountId, int $version): int
    {
        return DB::transaction(function () use ($accountId, $version) {
            $char = $this->characters->lock($accountId);
            $this->characters->checkVersion($char, $version);
            $state = $this->queries->premiumState($char);
            $added = Premium::activateAll($state, $this->queries->premiumCatalog());
            if ($added === 0) {
                throw new GameException('Every piece you own is already active.');
            }
            $this->save((int) $char['id'], $state);

            return $added;
        });
    }

    public function takeOff(int $accountId, int $version, string $category): void
    {
        if (! in_array($category, Balance::get()['premium']['categories'], true)) {
            throw new GameException('That is not a premium slot.');
        }
        DB::transaction(function () use ($accountId, $version, $category) {
            $char = $this->characters->lock($accountId);
            $this->characters->checkVersion($char, $version);
            $state = $this->queries->premiumState($char);
            if (! isset($state['equipped'][$category])) {
                throw new GameException('Nothing is worn there.');
            }
            Premium::takeOff($state, $category, $this->queries->premiumCatalog());
            $this->save((int) $char['id'], $state);
        });
    }
}
