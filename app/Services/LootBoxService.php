<?php

namespace App\Services;

use App\Exceptions\GameException;
use App\Game\Balance;
use App\Game\Loot;
use App\Game\Rng;
use App\Simulation\SimRepo;
use Illuminate\Support\Facades\DB;

/**
 * Opening a sealed clear-chest (DESIGN.md §6.1). Contents are rolled here, from the seed stored on the box.
 * Uses the admin connection: marking a chest opened needs UPDATE plus inserts the website user does not have.
 */
class LootBoxService
{
    public function __construct(private CharacterService $characters) {}

    private function db()
    {
        return DB::connection('admin');
    }

    /**
     * @return array{place: string, items: list<array>, sold: list<array>}
     */
    public function open(int $accountId, int $version, int $boxId): array
    {
        return $this->db()->transaction(function () use ($accountId, $version, $boxId) {
            $balance = Balance::get();
            $row = $this->db()->table('characters')->where('account_id', $accountId)->lockForUpdate()->first();
            if (! $row) {
                throw new GameException('Character not found.');
            }
            $char = (array) $row;
            $this->characters->checkVersion($char, $version);
            $cid = (int) $char['id'];
            $box = $this->db()->table('loot_boxes')->where('id', $boxId)->lockForUpdate()->first();
            if (! $box || (int) $box->character_id !== $cid) {
                throw new GameException('That chest is not yours.');
            }
            if ($box->status !== 'sealed') {
                throw new GameException('That chest is already open.');
            }
            $place = $this->db()->table('places')->where('id', $box->place_id)->first();
            $rolled = Loot::rollChest(
                $this->chestTable($place->chest_loot_table_id ?? null),
                (int) $box->rolls,
                (float) $box->luck_factor,
                (int) $box->source_level,
                $this->affixPool(),
                Rng::chest((int) $box->seed),
                $balance,
                $this->legendaryNames(),
            );
            $commons = [];
            $count = 0;
            $rows = $this->db()->table('item_instances as ii')
                ->leftJoin('character_equipment as ce', 'ce.item_instance_id', '=', 'ii.id')
                ->where('ii.character_id', $cid)
                ->where('ii.status', 'owned')
                ->whereNull('ce.item_instance_id')
                ->get(['ii.id', 'ii.rarity', 'ii.sell_value', 'ii.display_name']);
            foreach ($rows as $row) {
                $count++;
                if ($row->rarity === 'common') {
                    $commons[] = ['id' => (int) $row->id, 'sell_value' => (int) $row->sell_value, 'name' => $row->display_name];
                }
            }
            $plan = Loot::planCapacity($rolled, $commons, (int) $balance['loot']['inventory_capacity'], $count);
            $gold = (int) $char['gold'];
            $shown = [];
            $sold = [];
            foreach ($plan['sold_owned'] as $victim) {
                $payout = (int) $victim['sell_value'];
                $gold += $payout;
                $this->db()->table('item_instances')->where('id', $victim['id'])->update(['status' => 'sold', 'character_id' => null]);
                $this->ledger($cid, (int) $victim['id'], $payout, $gold);
                $sold[] = ['name' => $victim['name'], 'sell_value' => $payout, 'kept' => false];
            }
            foreach ($plan['kept'] as $item) {
                $itemId = $this->insertItem($item, $cid, (int) $box->trip_id, 'owned', true);
                $shown[] = $this->brief($item, $itemId);
            }
            foreach ($plan['sold_new'] as $item) {
                $itemId = $this->insertItem($item, null, (int) $box->trip_id, 'sold', false);
                $payout = (int) $item['sell_value'];
                $gold += $payout;
                $this->ledger($cid, $itemId, $payout, $gold);
                $sold[] = ['name' => $item['display_name'], 'sell_value' => $payout, 'kept' => false];
                $shown[] = $this->brief($item, $itemId);
            }
            $this->db()->table('loot_boxes')->where('id', $box->id)->update(['status' => 'opened', 'opened_at' => now('UTC')]);
            $this->db()->table('characters')->where('id', $cid)->update(['gold' => max(0, $gold), 'row_version' => DB::raw('row_version + 1')]);

            return [
                'place' => (string) ($place->name ?? 'the delve'),
                'items' => $shown,
                'sold' => $sold,
            ];
        });
    }

    private function ledger(int $characterId, int $itemId, int $delta, int $goldAfter): void
    {
        $this->db()->table('shop_transactions')->insert([
            'character_id' => $characterId,
            'actor' => 'web',
            'action' => 'sell',
            'item_instance_id' => $itemId,
            'quantity' => 1,
            'gold_delta' => $delta,
            'gold_after' => max(0, $goldAfter),
        ]);
    }

    private function brief(array $item, int $itemId): array
    {
        return [
            'id' => $itemId,
            'name' => $item['display_name'],
            'rarity' => $item['rarity'],
            'item_level' => $item['item_level'],
            'slot' => $item['slot'],
        ];
    }

    private function insertItem(array $item, ?int $characterId, int $tripId, string $status, bool $kept): int
    {
        $itemId = (int) $this->db()->table('item_instances')->insertGetId([
            'template_id' => $item['template_id'],
            'character_id' => $characterId,
            'status' => $status,
            'rarity' => $item['rarity'],
            'item_level' => $item['item_level'],
            'display_name' => $item['display_name'],
            'power' => $item['power'],
            'armor' => $item['armor'],
            'mres' => $item['mres'],
            'sell_value' => $item['sell_value'],
            'source' => 'chest',
            'created_trip_id' => $tripId,
        ]);
        foreach ($item['affixes'] ?? [] as $affix) {
            if (empty($affix['affix_id'])) {
                continue;
            }
            $this->db()->table('item_instance_affixes')->insert([
                'item_instance_id' => $itemId,
                'affix_id' => $affix['affix_id'],
                'stat_key' => $affix['stat_key'],
                'value' => $affix['value'],
            ]);
        }
        $this->db()->table('trip_loot')->insert([
            'trip_id' => $tripId,
            'item_instance_id' => $itemId,
            'encounter_seq' => null,
            'kept' => $kept ? 1 : 0,
        ]);

        return $itemId;
    }

    private function chestTable(mixed $tableId): ?array
    {
        if (! $tableId) {
            return null;
        }
        $entries = [];
        foreach ($this->db()->table('loot_table_entries')->where('loot_table_id', $tableId)->orderBy('id')->get() as $row) {
            $template = null;
            if ($row->item_template_id) {
                $templateRow = $this->db()->table('item_templates')->where('id', $row->item_template_id)->first();
                $template = $templateRow ? SimRepo::template((array) $templateRow) : null;
            }
            $entries[] = [
                'entry_type' => $row->entry_type,
                'weight' => (int) $row->weight,
                'template' => $template,
            ];
        }

        return ['id' => (int) $tableId, 'entries' => $entries];
    }

    /** @return list<array> */
    private function affixPool(): array
    {
        $pool = [];
        foreach ($this->db()->table('affixes')->orderBy('id')->get() as $row) {
            $pool[] = [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'kind' => $row->kind,
                'stat_key' => $row->stat_key,
                'slot_groups' => $row->slot_groups,
                'min_ilvl' => (int) $row->min_ilvl,
                'weight' => (int) $row->weight,
                'value_min_base' => (float) $row->value_min_base,
                'value_max_base' => (float) $row->value_max_base,
                'value_per_ilvl' => (float) $row->value_per_ilvl,
            ];
        }

        return $pool;
    }

    /** @return list<array> */
    private function legendaryNames(): array
    {
        $pool = [];
        foreach ($this->db()->table('legendary_names')->orderBy('id')->get() as $row) {
            $pool[] = SimRepo::legendaryNameRow((array) $row);
        }

        return $pool;
    }
}
