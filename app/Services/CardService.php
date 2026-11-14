<?php

namespace App\Services;

use App\Exceptions\GameException;
use App\Game\Cards;
use Illuminate\Support\Facades\DB;

/**
 * Insert / remove / sell monster cards (DESIGN.md §6.10).
 * Uses the admin connection (same pattern as LootBoxService) until grants_web.sql is applied as root.
 */
class CardService
{
    public function __construct(private CharacterService $characters) {}

    private function db()
    {
        return DB::connection('admin');
    }

    private function lockCharacter(int $accountId): array
    {
        $row = $this->db()->table('characters')->where('account_id', $accountId)->lockForUpdate()->first();
        if (! $row) {
            throw new GameException('No character for this account.');
        }

        return (array) $row;
    }

    private function destroyCardsOnItem(int $itemId): void
    {
        $ids = $this->db()->table('item_instance_cards')->where('item_instance_id', $itemId)->pluck('card_instance_id')->all();
        if ($ids === []) {
            return;
        }
        $this->db()->table('item_instance_cards')->where('item_instance_id', $itemId)->delete();
        $this->db()->table('card_instances')->whereIn('id', $ids)->update(['status' => 'destroyed', 'character_id' => null]);
    }

    public function destroyOnItem(int $itemId): void
    {
        $this->destroyCardsOnItem($itemId);
    }

    public function insert(int $accountId, int $version, int $cardId, int $itemId, int $slotIndex): string
    {
        return $this->db()->transaction(function () use ($accountId, $version, $cardId, $itemId, $slotIndex) {
            $char = $this->lockCharacter($accountId);
            $this->characters->checkVersion($char, $version);
            $cid = (int) $char['id'];

            $cardRow = $this->db()->table('card_instances')->where('id', $cardId)->lockForUpdate()->first();
            if (! $cardRow || (int) $cardRow->character_id !== $cid || $cardRow->status !== 'owned') {
                throw new GameException('That card is not in your bag.');
            }
            $itemRow = $this->db()->table('item_instances')->where('id', $itemId)->lockForUpdate()->first();
            if (! $itemRow || (int) $itemRow->character_id !== $cid || $itemRow->status !== 'owned') {
                throw new GameException('That piece is not yours.');
            }

            $card = [
                'id' => (int) $cardRow->id,
                'status' => $cardRow->status,
                'display_name' => $cardRow->display_name,
                'bonuses' => json_decode($cardRow->bonuses, true) ?: [],
            ];
            $item = [
                'id' => (int) $itemRow->id,
                'card_slots' => (int) $itemRow->card_slots,
                'cards' => array_fill(0, (int) $itemRow->card_slots, null),
            ];
            foreach ($this->db()->table('item_instance_cards')->where('item_instance_id', $itemId)->get() as $sock) {
                $item['cards'][(int) $sock->slot_index] = ['id' => (int) $sock->card_instance_id];
            }

            [$ok, $why] = Cards::canInsert($item, $card, $slotIndex);
            if (! $ok) {
                throw new GameException($why);
            }

            $this->db()->table('item_instance_cards')->insert([
                'item_instance_id' => $itemId,
                'slot_index' => $slotIndex,
                'card_instance_id' => $cardId,
            ]);
            $this->db()->table('card_instances')->where('id', $cardId)->update(['status' => 'socketed']);
            $this->db()->table('characters')->where('id', $cid)->update(['row_version' => DB::raw('row_version + 1')]);

            return $cardRow->display_name;
        });
    }

    public function remove(int $accountId, int $version, int $itemId, int $slotIndex): string
    {
        return $this->db()->transaction(function () use ($accountId, $version, $itemId, $slotIndex) {
            $char = $this->lockCharacter($accountId);
            $this->characters->checkVersion($char, $version);
            $cid = (int) $char['id'];

            $itemRow = $this->db()->table('item_instances')->where('id', $itemId)->lockForUpdate()->first();
            if (! $itemRow || (int) $itemRow->character_id !== $cid || $itemRow->status !== 'owned') {
                throw new GameException('That piece is not yours.');
            }
            $sock = $this->db()->table('item_instance_cards')->where('item_instance_id', $itemId)->where('slot_index', $slotIndex)->first();
            if (! $sock) {
                throw new GameException('That socket is empty.');
            }
            $cardRow = $this->db()->table('card_instances')->where('id', $sock->card_instance_id)->lockForUpdate()->first();
            if (! $cardRow || (int) $cardRow->character_id !== $cid) {
                throw new GameException('That card is missing.');
            }

            $this->db()->table('item_instance_cards')->where('item_instance_id', $itemId)->where('slot_index', $slotIndex)->delete();
            $this->db()->table('card_instances')->where('id', $cardRow->id)->update(['status' => 'owned']);
            $this->db()->table('characters')->where('id', $cid)->update(['row_version' => DB::raw('row_version + 1')]);

            return $cardRow->display_name;
        });
    }

    /** @return array{0: string, 1: int} */
    public function sell(int $accountId, int $version, int $cardId): array
    {
        return $this->db()->transaction(function () use ($accountId, $version, $cardId) {
            $char = $this->lockCharacter($accountId);
            $this->characters->checkVersion($char, $version);
            $cid = (int) $char['id'];
            $card = $this->db()->table('card_instances')->where('id', $cardId)->lockForUpdate()->first();
            if (! $card || (int) $card->character_id !== $cid || $card->status !== 'owned') {
                throw new GameException('That card is not in your bag.');
            }
            $payout = (int) $card->sell_value;
            $this->db()->table('card_instances')->where('id', $cardId)->update(['status' => 'sold', 'character_id' => null]);
            $this->db()->table('characters')->where('id', $cid)->update([
                'gold' => DB::raw('gold + '.$payout),
                'row_version' => DB::raw('row_version + 1'),
            ]);
            $this->db()->table('shop_transactions')->insert([
                'character_id' => $cid,
                'actor' => 'web',
                'action' => 'sell',
                'listing_id' => null,
                'item_instance_id' => null,
                'consumable_id' => null,
                'quantity' => 1,
                'gold_delta' => $payout,
                'gold_after' => (int) $char['gold'] + $payout,
            ]);

            return [$card->display_name, $payout];
        });
    }
}
