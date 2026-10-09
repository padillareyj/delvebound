<?php

namespace App\Simulation;

use App\Game\World\RegionGenerator;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Opens the next region when any active character has reached the end of the current one.
 * Runs inside delve:run. A dry-run rolls the rows back with the rest of the preview.
 */
final class WorldExpander
{
    public function __construct(private Connection $db, private array $balance) {}

    /**
     * @return list<array{code: string, name: string, from: int, to: int}>
     */
    public function open(): array
    {
        $level = $this->db->table('characters')->where('is_active', 1)->max('level');
        if ($level === null) {
            return [];
        }
        $opened = [];
        $limit = (int) $this->balance['world']['max_regions_per_run'];
        while (count($opened) < $limit) {
            $frontier = $this->db->table('places')
                ->where('place_type', '<>', 'rest')
                ->where('is_active', 1)
                ->orderByDesc('rec_max_level')
                ->orderByDesc('min_level')
                ->orderByDesc('id')
                ->first(['code', 'rec_max_level']);
            if ($frontier === null || (int) $level < (int) $frontier->rec_max_level) {
                break;
            }
            $ordinal = (int) $this->db->table('regions')->max('sort_order') + 1;
            $plan = RegionGenerator::build(
                (int) $frontier->rec_max_level,
                $ordinal,
                (string) $frontier->code,
                $this->themeCodes(),
                $this->takenCodes(),
                $this->takenNames(),
                $this->balance,
            );
            $this->db->transaction(function () use ($plan) {
                $this->persist($plan);
            });
            $opened[] = [
                'code' => $plan['code'],
                'name' => $plan['name'],
                'from' => $plan['from'],
                'to' => $plan['to'],
            ];
        }

        return $opened;
    }

    /** @param  array<string, mixed>  $plan */
    private function persist(array $plan): void
    {
        $dust = $this->db->table('material_templates')->where('code', 'spark_dust')->value('id');
        $shard = $this->db->table('material_templates')->where('code', 'rune_shard')->value('id');
        if ($dust === null || $shard === null) {
            throw new RuntimeException('Spark Dust and Rune Shard must exist before a region can be generated.');
        }
        $this->db->table('regions')->insert([
            'code' => $plan['code'],
            'name' => $plan['name'],
            'blurb' => $plan['blurb'],
            'sort_order' => $plan['sort_order'],
        ]);
        $regionId = (int) $this->db->table('regions')->where('code', $plan['code'])->value('id');

        $lootIds = [];
        foreach ($plan['loot_tables'] as $table) {
            $lootIds[$table['code']] = (int) $this->db->table('loot_tables')->insertGetId([
                'code' => $table['code'],
                'name' => $table['name'],
                'description' => $table['description'],
            ]);
        }
        $itemIds = [];
        foreach ($plan['items'] as $item) {
            $itemIds[$item['code']] = (int) $this->db->table('item_templates')->insertGetId([
                'code' => $item['code'],
                'name' => $item['name'],
                'slot' => $item['slot'],
                'item_type' => $item['item_type'],
                'scaling_stat' => $item['scaling_stat'],
                'two_handed' => $item['two_handed'] ? 1 : 0,
                'base_power' => $item['base_power'],
                'base_armor' => $item['base_armor'],
                'base_mres' => $item['base_mres'],
                'implicit_mods' => $item['implicit_mods'] === null ? null : json_encode($item['implicit_mods'], JSON_THROW_ON_ERROR),
                'req_level' => $item['req_level'],
                'base_value' => $item['base_value'],
                'description' => $item['description'],
            ]);
        }
        foreach ($plan['loot_entries'] as $entry) {
            $this->db->table('loot_table_entries')->insert([
                'loot_table_id' => $lootIds[$entry['table']],
                'entry_type' => $entry['type'],
                'item_template_id' => $entry['item'] === null ? null : $itemIds[$entry['item']],
                'weight' => $entry['weight'],
            ]);
        }
        $monsterIds = [];
        foreach ($plan['monsters'] as $monster) {
            $monsterIds[$monster['code']] = (int) $this->db->table('monsters')->insertGetId([
                'code' => $monster['code'],
                'name' => $monster['name'],
                'level' => $monster['level'],
                'is_boss' => $monster['is_boss'] ? 1 : 0,
                'hp' => $monster['hp'],
                'attack' => $monster['attack'],
                'defense' => $monster['defense'],
                'mres' => $monster['mres'],
                'speed' => $monster['speed'],
                'crit_pct' => $monster['crit_pct'],
                'dodge_pct' => $monster['dodge_pct'],
                'damage_type' => $monster['damage_type'],
                'xp' => $monster['xp'],
                'gold_min' => $monster['gold_min'],
                'gold_max' => $monster['gold_max'],
                'loot_table_id' => $lootIds[$monster['loot']],
                'drop_chance' => $monster['drop_chance'],
                'description' => $monster['description'],
            ]);
        }
        $placeIds = [];
        foreach ($plan['places'] as $place) {
            $placeIds[$place['code']] = (int) $this->db->table('places')->insertGetId([
                'region_id' => $regionId,
                'code' => $place['code'],
                'name' => $place['name'],
                'place_type' => $place['place_type'],
                'min_level' => $place['min_level'],
                'max_level' => null,
                'rec_min_level' => $place['rec_min_level'],
                'rec_max_level' => $place['rec_max_level'],
                'rec_level' => $place['rec_level'],
                'encounter_min' => $place['encounter_min'],
                'encounter_max' => $place['encounter_max'],
                'boss_monster_id' => $monsterIds[$place['boss']],
                'boss_chance' => $place['boss_chance'],
                'rarity_bonus' => $place['rarity_bonus'],
                'gold_mult' => $place['gold_mult'],
                'chest_loot_table_id' => $lootIds[$place['chest']],
                'map_x' => $place['map_x'],
                'map_y' => $place['map_y'],
                'description' => $place['description'],
                'is_active' => 1,
            ]);
        }
        foreach ($plan['spawns'] as $spawn) {
            $this->db->table('place_monsters')->insert([
                'place_id' => $placeIds[$spawn['place']],
                'monster_id' => $monsterIds[$spawn['monster']],
                'weight' => $spawn['weight'],
                'min_group' => $spawn['min_group'],
                'max_group' => $spawn['max_group'],
            ]);
        }
        foreach ($plan['essences'] as $essence) {
            $materialId = (int) $this->db->table('material_templates')->insertGetId([
                'code' => $essence['code'],
                'name' => $essence['name'],
                'kind' => 'essence',
                'glyph' => $essence['glyph'],
                'description' => $essence['description'],
                'sort_order' => $essence['sort_order'],
            ]);
            $placeId = $placeIds[$essence['place']];
            foreach ([(int) $dust, (int) $shard, $materialId] as $material) {
                $this->db->table('place_materials')->insert([
                    'place_id' => $placeId,
                    'material_id' => $material,
                ]);
            }
        }
    }

    /** @return list<string> */
    private function themeCodes(): array
    {
        $codes = [];
        foreach ($this->db->table('regions')->pluck('code') as $code) {
            if (preg_match('/^gen\d+_(.+)$/', (string) $code, $match)) {
                $codes[] = $match[1];
            }
        }

        return $codes;
    }

    /** @return array<string, true> */
    private function takenCodes(): array
    {
        $taken = [];
        foreach (['regions', 'places', 'monsters', 'loot_tables', 'item_templates', 'material_templates'] as $table) {
            foreach ($this->db->table($table)->pluck('code') as $code) {
                $taken[(string) $code] = true;
            }
        }

        return $taken;
    }

    /** @return array<string, true> */
    private function takenNames(): array
    {
        $taken = [];
        foreach (['regions', 'places', 'monsters', 'item_templates', 'material_templates'] as $table) {
            foreach ($this->db->table($table)->pluck('name') as $name) {
                $taken[strtolower((string) $name)] = true;
            }
        }

        return $taken;
    }
}
