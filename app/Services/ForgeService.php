<?php

namespace App\Services;

use App\Exceptions\GameException;
use App\Game\Balance;
use App\Game\Forge;
use App\Game\Rng;
use App\Simulation\SimRepo;
use Illuminate\Support\Facades\DB;

/**
 * A forge attempt from the website (DESIGN.md §6.9). One transaction: lock the character, check
 * row_version, consume both pieces, spend the gold, and write the result. The seed is drawn here,
 * not in app/Game, so the roll stays out of the pure rules.
 */
class ForgeService
{
    public function __construct(private CharacterService $characters) {}

    /** @return array{outcome: string, message: string, gold: int, item: array<string, mixed>|null} */
    public function combine(int $accountId, int $version, int $itemA, int $itemB, ?int $seed = null): array
    {
        return DB::transaction(function () use ($accountId, $version, $itemA, $itemB, $seed) {
            $char = $this->characters->lock($accountId);
            $this->characters->checkVersion($char, $version);
            if ($itemA === $itemB) {
                throw new GameException('Choose two different pieces.');
            }
            $cid = (int) $char['id'];
            $left = $this->lockPiece(min($itemA, $itemB), $cid);
            $right = $this->lockPiece(max($itemA, $itemB), $cid);
            $balance = Balance::get();
            $quote = Forge::quote($left, $right, (int) $char['gold'], (int) $char['stat_luk'], $balance);
            if (! $quote['ok']) {
                throw new GameException($quote['reason'] ?? 'The forge refuses those pieces.');
            }

            $drawn = $seed ?? random_int(1, 2147483646);
            $result = Forge::combine(
                $left,
                $right,
                $this->templates(),
                $this->affixPool(),
                $this->legendaryNames(),
                (int) $char['stat_luk'],
                $balance,
                Rng::forge($drawn, $cid, (int) $left['id'], (int) $right['id']),
            );
            $goldAfter = (int) $char['gold'] - (int) $result['gold'];
            DB::table('item_instances')->whereIn('id', [(int) $left['id'], (int) $right['id']])->update([
                'status' => 'destroyed',
                'character_id' => null,
            ]);
            $itemId = null;
            if ($result['item'] !== null) {
                $itemId = $this->insertItem($result['item'], $cid);
            }
            DB::table('characters')->where('id', $cid)->update([
                'gold' => $goldAfter,
                'row_version' => DB::raw('row_version + 1'),
            ]);
            DB::table('shop_transactions')->insert([
                'character_id' => $cid,
                'actor' => 'web',
                'action' => 'craft',
                'item_instance_id' => $itemId,
                'quantity' => 1,
                'gold_delta' => -1 * (int) $result['gold'],
                'gold_after' => $goldAfter,
            ]);

            return [
                'outcome' => $result['outcome'],
                'message' => Forge::message($result),
                'gold' => (int) $result['gold'],
                'item' => $itemId === null ? null : [
                    'id' => $itemId,
                    'name' => $result['item']['display_name'],
                    'rarity' => $result['item']['rarity'],
                    'item_level' => (int) $result['item']['item_level'],
                    'slot' => DB::table('item_templates')->where('id', $result['item']['template_id'])->value('slot'),
                ],
            ];
        });
    }

    /** @return array<string, mixed> */
    private function lockPiece(int $itemId, int $characterId): array
    {
        $row = DB::table('item_instances')->where('id', $itemId)->lockForUpdate()->first();
        if (! $row || (int) $row->character_id !== $characterId || $row->status !== 'owned') {
            throw new GameException('That item is not in your inventory.');
        }
        $slot = DB::table('item_templates')->where('id', $row->template_id)->value('slot');

        return [
            'id' => (int) $row->id,
            'name' => $row->display_name,
            'slot' => $slot,
            'rarity' => $row->rarity,
            'item_level' => (int) $row->item_level,
            'equipped' => DB::table('character_equipment')->where('item_instance_id', $itemId)->exists(),
        ];
    }

    private function insertItem(array $item, int $characterId): int
    {
        $itemId = (int) DB::table('item_instances')->insertGetId([
            'template_id' => $item['template_id'],
            'character_id' => $characterId,
            'status' => 'owned',
            'rarity' => $item['rarity'],
            'item_level' => $item['item_level'],
            'display_name' => $item['display_name'],
            'power' => $item['power'],
            'armor' => $item['armor'],
            'mres' => $item['mres'],
            'sell_value' => $item['sell_value'],
            'source' => 'craft',
        ]);
        foreach ($item['affixes'] ?? [] as $affix) {
            if (empty($affix['affix_id'])) {
                continue;
            }
            DB::table('item_instance_affixes')->insert([
                'item_instance_id' => $itemId,
                'affix_id' => $affix['affix_id'],
                'stat_key' => $affix['stat_key'],
                'value' => $affix['value'],
            ]);
        }

        return $itemId;
    }

    /** @return list<array> */
    private function templates(): array
    {
        $templates = [];
        foreach (DB::table('item_templates')->orderBy('id')->get() as $row) {
            $templates[] = SimRepo::template((array) $row);
        }

        return $templates;
    }

    /** @return list<array> */
    private function affixPool(): array
    {
        $pool = [];
        foreach (DB::table('affixes')->orderBy('id')->get() as $row) {
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
        foreach (DB::table('legendary_names')->orderBy('id')->get() as $row) {
            $pool[] = SimRepo::legendaryNameRow((array) $row);
        }

        return $pool;
    }
}
