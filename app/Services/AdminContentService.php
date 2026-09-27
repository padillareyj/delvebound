<?php

namespace App\Services;

use App\Exceptions\GameException;
use App\Game\Balance;
use App\Game\ItemTemplates;
use App\Game\ShopMath;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Admin writes to content tables (item templates, loot weights, shop listings). Uses the admin DB connection. */
class AdminContentService
{
    private function db()
    {
        return DB::connection('admin');
    }

    public function options(): array
    {
        return [
            'slots' => ItemTemplates::SLOTS,
            'types_by_slot' => ItemTemplates::TYPES_BY_SLOT,
            'stat_keys' => ItemTemplates::STAT_KEYS,
            'loot_tables' => $this->db()->table('loot_tables')->orderBy('id')->get(['id', 'code', 'name'])
                ->map(fn ($t) => ['id' => (int) $t->id, 'code' => $t->code, 'name' => $t->name])->all(),
        ];
    }

    public function gearIndex(): array
    {
        $loot = [];
        foreach ($this->db()->table('loot_table_entries as e')
            ->join('loot_tables as lt', 'lt.id', '=', 'e.loot_table_id')
            ->where('e.entry_type', 'item')
            ->orderBy('lt.code')
            ->get(['e.item_template_id', 'lt.code', 'e.weight']) as $row) {
            $loot[(int) $row->item_template_id][] = ['code' => $row->code, 'weight' => (int) $row->weight];
        }

        $shop = $this->db()->table('shop_listings')->where('kind', 'gear')->where('is_active', 1)
            ->pluck('price', 'item_template_id');

        $instances = $this->db()->table('item_instances')->select('template_id', DB::raw('COUNT(*) AS n'))
            ->groupBy('template_id')->pluck('n', 'template_id');

        $gear = $this->db()->table('item_templates')->orderBy('req_level')->orderBy('slot')->orderBy('name')->get()
            ->map(function ($t) use ($loot, $shop, $instances) {
                $id = (int) $t->id;
                $implicit = is_string($t->implicit_mods) ? json_decode($t->implicit_mods, true) : $t->implicit_mods;

                return [
                    'id' => $id,
                    'code' => $t->code,
                    'name' => $t->name,
                    'slot' => $t->slot,
                    'item_type' => $t->item_type,
                    'scaling_stat' => $t->scaling_stat,
                    'two_handed' => (bool) $t->two_handed,
                    'base_power' => (int) $t->base_power,
                    'base_armor' => (int) $t->base_armor,
                    'base_mres' => (int) $t->base_mres,
                    'implicit' => $implicit ?: (object) [],
                    'req_level' => (int) $t->req_level,
                    'base_value' => (int) $t->base_value,
                    'shop_price' => isset($shop[$id]) ? (int) $shop[$id] : null,
                    'loot' => $loot[$id] ?? [],
                    'instances' => (int) ($instances[$id] ?? 0),
                    'starter' => $this->isStarter($t->code),
                ];
            })->all();

        return ['gear' => $gear];
    }

    public function gearForm(?int $id): array
    {
        $options = $this->options();
        if ($id === null) {
            return ['template' => null, ...$options];
        }

        $t = $this->db()->table('item_templates')->where('id', $id)->first();
        if (! $t) {
            throw new NotFoundHttpException('That gear template does not exist.');
        }
        $implicit = is_string($t->implicit_mods) ? json_decode($t->implicit_mods, true) : $t->implicit_mods;
        $mods = [];
        foreach ((array) ($implicit ?: []) as $key => $value) {
            $mods[] = ['key' => (string) $key, 'value' => $value];
        }

        $lootWeights = $this->db()->table('loot_table_entries')
            ->where('entry_type', 'item')->where('item_template_id', $id)
            ->pluck('weight', 'loot_table_id');

        $listing = $this->db()->table('shop_listings')->where('kind', 'gear')->where('item_template_id', $id)->first();

        return [
            'template' => [
                'id' => (int) $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'slot' => $t->slot,
                'item_type' => $t->item_type,
                'scaling_stat' => $t->scaling_stat,
                'two_handed' => (bool) $t->two_handed,
                'base_power' => (int) $t->base_power,
                'base_armor' => (int) $t->base_armor,
                'base_mres' => (int) $t->base_mres,
                'implicit' => $mods,
                'req_level' => (int) $t->req_level,
                'base_value' => (int) $t->base_value,
                'description' => $t->description,
                'icon' => $t->icon,
                'loot' => collect($options['loot_tables'])->map(fn ($lt) => [
                    'loot_table_id' => $lt['id'],
                    'weight' => (int) ($lootWeights[$lt['id']] ?? 0),
                ])->all(),
                'in_shop' => $listing && (int) $listing->is_active === 1,
                'shop_price' => $listing ? (int) $listing->price : ShopMath::gearPrice((int) $t->base_value, (int) $t->req_level),
                'instances' => (int) $this->db()->table('item_instances')->where('template_id', $id)->count(),
                'starter' => $this->isStarter($t->code),
                'can_delete' => ! $this->isStarter($t->code)
                    && ! $this->db()->table('item_instances')->where('template_id', $id)->exists()
                    && ! ($listing && $this->db()->table('shop_transactions')->where('listing_id', $listing->id)->exists()),
            ],
            ...$options,
        ];
    }

    public function saveGear(?int $id, array $input): int
    {
        [$ok, $reason, $row] = ItemTemplates::prepare($input);
        if (! $ok) {
            throw new GameException($reason);
        }

        $loot = is_array($input['loot'] ?? null) ? $input['loot'] : [];
        $inShop = ! empty($input['in_shop']);

        return (int) $this->db()->transaction(function () use ($id, $row, $loot, $inShop) {
            $existing = $id ? $this->db()->table('item_templates')->where('id', $id)->lockForUpdate()->first() : null;
            if ($id && ! $existing) {
                throw new GameException('That gear template does not exist.');
            }
            if ($existing && $this->isStarter($existing->code) && $row['code'] !== $existing->code) {
                throw new GameException('Starter kit templates keep their code (it is referenced from balance.json).');
            }
            $taken = $this->db()->table('item_templates')->where('code', $row['code']);
            if ($id) {
                $taken->where('id', '<>', $id);
            }
            if ($taken->exists()) {
                throw new GameException("Code {$row['code']} is already used.");
            }

            $stored = $row;
            if (is_array($stored['implicit_mods'])) {
                $stored['implicit_mods'] = json_encode($stored['implicit_mods'], JSON_THROW_ON_ERROR);
            }

            if ($id) {
                $this->db()->table('item_templates')->where('id', $id)->update($stored);
                $templateId = $id;
            } else {
                $templateId = (int) $this->db()->table('item_templates')->insertGetId($stored);
            }

            $this->syncLoot($templateId, $loot);
            $this->syncShop($templateId, $inShop, (int) $row['base_value'], (int) $row['req_level']);

            return $templateId;
        });
    }

    public function deleteGear(int $id): void
    {
        $this->db()->transaction(function () use ($id) {
            $row = $this->db()->table('item_templates')->where('id', $id)->lockForUpdate()->first();
            if (! $row) {
                throw new GameException('That gear template does not exist.');
            }
            if ($this->isStarter($row->code)) {
                throw new GameException('Starter kit templates cannot be deleted.');
            }
            if ($this->db()->table('item_instances')->where('template_id', $id)->exists()) {
                throw new GameException('This template already dropped as items. Retire it from loot and the shop instead of deleting.');
            }
            $listingIds = $this->db()->table('shop_listings')->where('item_template_id', $id)->pluck('id');
            if ($listingIds->isNotEmpty()
                && $this->db()->table('shop_transactions')->whereIn('listing_id', $listingIds)->exists()) {
                throw new GameException('This template has a shop listing kept for the gold ledger. Unlist it and leave the template in place.');
            }
            $this->db()->table('shop_listings')->where('item_template_id', $id)->delete();
            $this->db()->table('loot_table_entries')->where('item_template_id', $id)->delete();
            $this->db()->table('item_templates')->where('id', $id)->delete();
        });
    }

    private function syncLoot(int $templateId, array $loot): void
    {
        $validIds = $this->db()->table('loot_tables')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $seen = [];
        foreach ($loot as $entry) {
            $tableId = (int) ($entry['loot_table_id'] ?? 0);
            $weight = (int) ($entry['weight'] ?? 0);
            if ($tableId < 1 || ! in_array($tableId, $validIds, true)) {
                continue;
            }
            $seen[] = $tableId;
            $existing = $this->db()->table('loot_table_entries')
                ->where('loot_table_id', $tableId)->where('item_template_id', $templateId)->first();
            if ($weight < 1) {
                if ($existing) {
                    $this->db()->table('loot_table_entries')->where('id', $existing->id)->delete();
                }
                continue;
            }
            if ($existing) {
                $this->db()->table('loot_table_entries')->where('id', $existing->id)->update(['weight' => $weight]);
            } else {
                $this->db()->table('loot_table_entries')->insert([
                    'loot_table_id' => $tableId,
                    'entry_type' => 'item',
                    'item_template_id' => $templateId,
                    'weight' => $weight,
                ]);
            }
        }
        if ($seen !== []) {
            $this->db()->table('loot_table_entries')->where('item_template_id', $templateId)
                ->whereNotIn('loot_table_id', $seen)->delete();
        }
    }

    private function syncShop(int $templateId, bool $inShop, int $baseValue, int $reqLevel): void
    {
        $price = ShopMath::gearPrice($baseValue, $reqLevel);
        $listing = $this->db()->table('shop_listings')->where('kind', 'gear')->where('item_template_id', $templateId)->first();
        if (! $inShop) {
            if ($listing) {
                $used = $this->db()->table('shop_transactions')->where('listing_id', $listing->id)->exists();
                if ($used) {
                    $this->db()->table('shop_listings')->where('id', $listing->id)->update(['is_active' => 0]);
                } else {
                    $this->db()->table('shop_listings')->where('id', $listing->id)->delete();
                }
            }

            return;
        }
        if ($listing) {
            $this->db()->table('shop_listings')->where('id', $listing->id)->update([
                'price' => $price,
                'min_level' => $reqLevel,
                'is_active' => 1,
            ]);

            return;
        }
        $sort = (int) $this->db()->table('shop_listings')->where('kind', 'gear')->max('sort_order') + 10;
        $this->db()->table('shop_listings')->insert([
            'kind' => 'gear',
            'item_template_id' => $templateId,
            'consumable_id' => null,
            'price' => $price,
            'min_level' => $reqLevel,
            'stock' => null,
            'sort_order' => $sort,
            'is_active' => 1,
        ]);
    }

    private function isStarter(string $code): bool
    {
        $c = Balance::get()['character'];

        return in_array($code, [...array_values($c['starter_weapons']), ...$c['starter_armor']], true);
    }
}
