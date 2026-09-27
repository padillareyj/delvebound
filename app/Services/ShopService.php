<?php

namespace App\Services;

use App\Exceptions\GameException;
use App\Game\Balance;
use App\Game\ShopMath;
use App\Game\Stats;
use Illuminate\Support\Facades\DB;

/** Market buy/sell (DESIGN.md §7.7). Same rules the bots use; one ledger row per action. */
class ShopService
{
    public function __construct(private CharacterService $characters) {}

    public static function ledger(int $characterId, string $action, ?int $listingId, ?int $itemId, ?int $consumableId, int $qty, int $delta, int $goldAfter): void
    {
        DB::table('shop_transactions')->insert([
            'character_id' => $characterId,
            'actor' => 'web',
            'action' => $action,
            'listing_id' => $listingId,
            'item_instance_id' => $itemId,
            'consumable_id' => $consumableId,
            'quantity' => $qty,
            'gold_delta' => $delta,
            'gold_after' => max(0, $goldAfter),
        ]);
    }

    /** Insert a common, affix-free copy of a template (shop purchase or starter kit). */
    public static function insertPlainItem(int $characterId, object|array $templateRow, string $source): int
    {
        $template = (array) $templateRow;
        $template['implicit'] = Stats::implicit($template);
        $item = ShopMath::shopGearInstance($template);

        return (int) DB::table('item_instances')->insertGetId([
            'template_id' => $template['id'],
            'character_id' => $characterId,
            'status' => 'owned',
            'rarity' => $item['rarity'],
            'item_level' => $item['item_level'],
            'display_name' => $item['display_name'],
            'power' => $item['power'],
            'armor' => $item['armor'],
            'mres' => $item['mres'],
            'sell_value' => $item['sell_value'],
            'source' => $source,
        ]);
    }

    public function buy(int $accountId, int $version, int $listingId, int $quantity = 1): string
    {
        $quantity = max(1, min(20, $quantity));

        return DB::transaction(function () use ($accountId, $version, $listingId, $quantity) {
            $balance = Balance::get();
            $char = $this->characters->lock($accountId);
            $this->characters->checkVersion($char, $version);
            $cid = (int) $char['id'];
            $listing = DB::table('shop_listings')->where('id', $listingId)->where('is_active', 1)->first();
            if (! $listing) {
                throw new GameException('That is not for sale.');
            }
            if ((int) $char['level'] < (int) $listing->min_level) {
                throw new GameException("You need level {$listing->min_level} to buy that.");
            }
            $price = (int) $listing->price;
            $gold = (int) $char['gold'];

            if ($listing->kind === 'gear') {
                if ($gold < $price) {
                    throw new GameException('You cannot afford that.');
                }
                if (DB::table('v_inventory')->where('character_id', $cid)->count() >= (int) $balance['loot']['inventory_capacity']) {
                    throw new GameException('Your pack is full.');
                }
                $template = DB::table('item_templates')->where('id', $listing->item_template_id)->first();
                $itemId = self::insertPlainItem($cid, $template, 'shop');
                DB::table('characters')->where('id', $cid)->update(['gold' => $gold - $price, 'row_version' => DB::raw('row_version + 1')]);
                self::ledger($cid, 'buy', (int) $listing->id, $itemId, null, 1, -$price, $gold - $price);

                return $template->name;
            }

            $owned = (int) (DB::table('character_consumables')->where('character_id', $cid)->where('consumable_id', $listing->consumable_id)->value('quantity') ?? 0);
            $maxStack = (int) $balance['shop']['max_stack'];
            if ($owned + $quantity > $maxStack) {
                throw new GameException("You can carry at most {$maxStack} of those.");
            }
            $total = $price * $quantity;
            if ($gold < $total) {
                throw new GameException('You cannot afford that.');
            }
            if ($owned === 0) {
                DB::table('character_consumables')->insert(['character_id' => $cid, 'consumable_id' => $listing->consumable_id, 'quantity' => $quantity, 'belt_qty' => 0]);
            } else {
                DB::table('character_consumables')->where('character_id', $cid)->where('consumable_id', $listing->consumable_id)->increment('quantity', $quantity);
            }
            DB::table('characters')->where('id', $cid)->update(['gold' => $gold - $total, 'row_version' => DB::raw('row_version + 1')]);
            self::ledger($cid, 'buy', (int) $listing->id, null, (int) $listing->consumable_id, $quantity, -$total, $gold - $total);

            return (string) DB::table('consumable_templates')->where('id', $listing->consumable_id)->value('name');
        });
    }

    /** @return array{0: string, 1: int} [item name, gold received] */
    public function sellItem(int $accountId, int $version, int $itemId): array
    {
        return DB::transaction(function () use ($accountId, $version, $itemId) {
            $char = $this->characters->lock($accountId);
            $this->characters->checkVersion($char, $version);
            $cid = (int) $char['id'];
            $item = DB::table('item_instances')->where('id', $itemId)->where('character_id', $cid)->where('status', 'owned')->first();
            if (! $item) {
                throw new GameException('That item is not in your pack.');
            }
            if (DB::table('character_equipment')->where('item_instance_id', $itemId)->exists()) {
                throw new GameException('Unequip it before selling.');
            }
            $payout = (int) $item->sell_value;
            DB::table('item_instances')->where('id', $itemId)->update(['status' => 'sold', 'character_id' => null]);
            DB::table('characters')->where('id', $cid)->update(['gold' => DB::raw('gold + '.$payout), 'row_version' => DB::raw('row_version + 1')]);
            self::ledger($cid, 'sell', null, $itemId, null, 1, $payout, (int) $char['gold'] + $payout);

            return [$item->display_name, $payout];
        });
    }

    /** @return array{0: string, 1: int} [consumable name, gold received] */
    public function sellConsumable(int $accountId, int $version, int $consumableId, int $qty): array
    {
        if ($qty < 1) {
            throw new GameException('Sell at least one.');
        }

        return DB::transaction(function () use ($accountId, $version, $consumableId, $qty) {
            $char = $this->characters->lock($accountId);
            $this->characters->checkVersion($char, $version);
            $cid = (int) $char['id'];
            $row = DB::table('character_consumables as cc')->join('consumable_templates as ct', 'ct.id', '=', 'cc.consumable_id')
                ->where('cc.character_id', $cid)->where('cc.consumable_id', $consumableId)
                ->first(['cc.quantity', 'cc.belt_qty', 'ct.sell_price', 'ct.name']);
            if (! $row || (int) $row->quantity < $qty) {
                throw new GameException('You do not have that many.');
            }
            $left = (int) $row->quantity - $qty;
            $stack = DB::table('character_consumables')->where('character_id', $cid)->where('consumable_id', $consumableId);
            if ($left === 0) {
                $stack->delete();
            } else {
                $stack->update(['quantity' => $left, 'belt_qty' => min((int) $row->belt_qty, $left)]);
            }
            $payout = $qty * (int) $row->sell_price;
            DB::table('characters')->where('id', $cid)->update(['gold' => DB::raw('gold + '.$payout), 'row_version' => DB::raw('row_version + 1')]);
            self::ledger($cid, 'sell', null, null, $consumableId, $qty, $payout, (int) $char['gold'] + $payout);

            return [$row->name, $payout];
        });
    }
}
