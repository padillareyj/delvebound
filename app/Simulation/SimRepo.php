<?php

namespace App\Simulation;

use App\Game\Balance;
use App\Game\Bots\Allocation;
use App\Game\Bots\Gear;
use App\Game\Loot;
use App\Game\Newcomer;
use App\Game\Premium;
use App\Game\Rng;
use App\Game\Rules;
use Illuminate\Database\Connection;

/** All simulation SQL (connection "sim"). The pure core in app/Game never touches the database. */
final class SimRepo
{
    public function __construct(private Connection $db) {}

    private function all(string $sql, array $args = []): array
    {
        return array_map(fn ($row) => (array) $row, $this->db->select($sql, $args));
    }

    private function one(string $sql, array $args = []): ?array
    {
        $row = $this->db->selectOne($sql, $args);

        return $row ? (array) $row : null;
    }

    private static function json(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? json_decode($value, true) : $value;
    }

    private static function dumps(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function insertId(): int
    {
        return (int) $this->db->getPdo()->lastInsertId();
    }

    /** @return list<int> */
    public function activeCharacterIds(): array
    {
        return array_map('intval', array_column($this->all('SELECT id FROM characters WHERE is_active = 1 ORDER BY id'), 'id'));
    }

    /**
     * Birth one bot for this run, and send each human a system letter.
     * Returns null when the town is already at newcomers.max_bots.
     *
     * @return array{name: string, level: int, archetype: string}|null
     */
    public function birthNewcomer(int $seed, int $runId, array $world, array $balance): ?array
    {
        $activeBots = (int) $this->one('SELECT COUNT(*) AS n FROM characters WHERE is_bot = 1 AND is_active = 1')['n'];
        $highest = (int) ($this->one('SELECT MAX(level) AS n FROM characters WHERE is_active = 1')['n'] ?? 1);
        $profiles = [];
        foreach ($this->all('SELECT id, archetype, preferred_scaling, retreat_threshold_pct FROM bot_profiles ORDER BY id') as $row) {
            $profiles[] = [
                'id' => (int) $row['id'],
                'archetype' => $row['archetype'],
                'preferred_scaling' => $row['preferred_scaling'],
                'retreat_threshold_pct' => (int) $row['retreat_threshold_pct'],
            ];
        }
        $taken = array_column($this->all('SELECT name FROM characters'), 'name');
        $plan = Newcomer::plan(
            $activeBots,
            $highest,
            $profiles,
            $taken,
            array_values($world['templates']),
            $world['affixes'],
            Rng::arrival($seed, $activeBots),
            $balance,
            $world['legendary_names'] ?? [],
        );
        if ($plan === null) {
            return null;
        }

        return $this->db->transaction(function () use ($plan, $runId) {
            $this->db->insert(
                'INSERT INTO characters (account_id, is_bot, bot_profile_id, name, level, xp, total_xp, gold,
                    stat_str, stat_dex, stat_int, stat_vit, stat_luk, unspent_points,
                    retreat_threshold_pct, potion_threshold_pct, auto_repeat, is_active)
                 VALUES (NULL, 1, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)',
                [
                    $plan['profile_id'], $plan['name'], $plan['level'], $plan['total_xp'], $plan['gold'],
                    $plan['base_stat'], $plan['base_stat'], $plan['base_stat'], $plan['base_stat'], $plan['base_stat'],
                    $plan['unspent'], $plan['retreat'], $plan['potion'],
                ],
            );
            $characterId = $this->insertId();
            foreach ($plan['items'] as $item) {
                $this->db->insert(
                    'INSERT INTO item_instances (template_id, character_id, status, rarity, item_level, display_name, power, armor, mres,
                                                  sell_value, source, created_run_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $item['template_id'], $characterId, 'owned', $item['rarity'], $item['item_level'], $item['display_name'],
                        $item['power'], $item['armor'], $item['mres'], $item['sell_value'], 'starter', $runId,
                    ],
                );
                $this->insertAffixes($this->insertId(), $item['affixes'] ?? []);
            }
            $potion = $this->one('SELECT id FROM consumable_templates WHERE code = ?', [$plan['potion_code']]);
            if ($potion) {
                $this->db->insert(
                    'INSERT INTO character_consumables (character_id, consumable_id, quantity, belt_qty) VALUES (?,?,?,?)',
                    [$characterId, (int) $potion['id'], $plan['potion_qty'], $plan['potion_belt']],
                );
            }
            $payload = self::dumps([
                'kind' => 'birth',
                'name' => $plan['name'],
                'level' => $plan['level'],
                'archetype' => $plan['archetype'],
            ]);
            foreach ($this->all('SELECT id FROM characters WHERE is_bot = 0 AND is_active = 1 ORDER BY id') as $human) {
                $this->db->insert(
                    "INSERT INTO mail (character_id, run_id, mail_type, subject, body_text, payload) VALUES (?, ?, 'system', ?, ?, ?)",
                    [(int) $human['id'], $runId, $plan['subject'], $plan['body'], $payload],
                );
            }
            $total = (int) $this->one('SELECT COUNT(*) AS n FROM characters WHERE is_active = 1')['n'];
            $this->db->update('UPDATE simulation_runs SET characters_total = ? WHERE id = ?', [$total, $runId]);

            return ['name' => $plan['name'], 'level' => $plan['level'], 'archetype' => $plan['archetype']];
        });
    }

    public function tripExists(int $runId, int $characterId): bool
    {
        return $this->one('SELECT id FROM trips WHERE run_id = ? AND character_id = ?', [$runId, $characterId]) !== null;
    }

    public function botDecisionExists(int $runId, int $characterId): bool
    {
        return $this->one('SELECT id FROM bot_decisions WHERE run_id = ? AND character_id = ?', [$runId, $characterId]) !== null;
    }

    // ---------------------------------------------------------------- world

    public function loadWorld(): array
    {
        $affixes = [];
        foreach ($this->all('SELECT * FROM affixes ORDER BY id') as $row) {
            $affixes[] = [
                'id' => (int) $row['id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'kind' => $row['kind'],
                'stat_key' => $row['stat_key'],
                'slot_groups' => $row['slot_groups'],
                'min_ilvl' => (int) $row['min_ilvl'],
                'weight' => (int) $row['weight'],
                'value_min_base' => (float) $row['value_min_base'],
                'value_max_base' => (float) $row['value_max_base'],
                'value_per_ilvl' => (float) $row['value_per_ilvl'],
            ];
        }
        $templates = [];
        foreach ($this->all('SELECT * FROM item_templates ORDER BY id') as $row) {
            $templates[(int) $row['id']] = self::template($row);
        }
        $tables = [];
        foreach ($this->all('SELECT * FROM loot_tables ORDER BY id') as $row) {
            $tables[(int) $row['id']] = ['id' => (int) $row['id'], 'code' => $row['code'], 'entries' => []];
        }
        foreach ($this->all('SELECT * FROM loot_table_entries ORDER BY id') as $row) {
            $tables[(int) $row['loot_table_id']]['entries'][] = [
                'entry_type' => $row['entry_type'],
                'weight' => (int) $row['weight'],
                'template' => $row['item_template_id'] ? $templates[(int) $row['item_template_id']] : null,
            ];
        }
        $monsters = [];
        foreach ($this->all('SELECT * FROM monsters ORDER BY id') as $row) {
            $monster = self::monster($row);
            if ($row['loot_table_id']) {
                $monster['loot_table'] = $tables[(int) $row['loot_table_id']];
            }
            $monsters[(int) $row['id']] = $monster;
        }
        $places = [];
        foreach ($this->all('SELECT * FROM places WHERE is_active = 1 ORDER BY id') as $row) {
            $place = self::place($row);
            if ($row['boss_monster_id']) {
                $place['boss'] = $monsters[(int) $row['boss_monster_id']];
            }
            if ($row['chest_loot_table_id']) {
                $place['chest'] = $tables[(int) $row['chest_loot_table_id']];
            }
            $places[$place['id']] = $place;
        }
        foreach ($this->all('SELECT * FROM place_monsters ORDER BY place_id, monster_id') as $row) {
            $pid = (int) $row['place_id'];
            if (! isset($places[$pid])) {
                continue;
            }
            $places[$pid]['spawns'][] = [
                'monster' => $monsters[(int) $row['monster_id']],
                'weight' => (int) $row['weight'],
                'min_group' => (int) $row['min_group'],
                'max_group' => (int) $row['max_group'],
            ];
        }
        $materialRows = [];
        foreach ($this->all('SELECT * FROM material_templates ORDER BY sort_order, id') as $row) {
            $materialRows[(int) $row['id']] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'kind' => $row['kind'],
                'glyph' => $row['glyph'],
                'description' => $row['description'],
            ];
        }
        foreach ($places as $pid => $place) {
            $places[$pid]['materials'] = [];
        }
        foreach ($this->all('SELECT * FROM place_materials ORDER BY place_id, material_id') as $row) {
            $pid = (int) $row['place_id'];
            $mid = (int) $row['material_id'];
            if (isset($places[$pid], $materialRows[$mid])) {
                $places[$pid]['materials'][] = $materialRows[$mid];
            }
        }
        $consumables = [];
        foreach ($this->all('SELECT * FROM consumable_templates ORDER BY id') as $row) {
            $consumables[(int) $row['id']] = self::consumableTemplate($row);
        }
        $shop = [];
        foreach ($this->all('SELECT * FROM shop_listings WHERE is_active = 1 ORDER BY sort_order, id') as $row) {
            $listing = [
                'id' => (int) $row['id'],
                'kind' => $row['kind'],
                'price' => (int) $row['price'],
                'min_level' => (int) $row['min_level'],
                'is_active' => true,
                'template' => null,
                'consumable' => null,
                'code' => null,
            ];
            if ($row['item_template_id']) {
                $listing['template'] = $templates[(int) $row['item_template_id']];
                $listing['code'] = $listing['template']['code'];
            }
            if ($row['consumable_id']) {
                $listing['consumable'] = $consumables[(int) $row['consumable_id']];
                $listing['code'] = $listing['consumable']['code'];
            }
            $shop[] = $listing;
        }
        $town = null;
        foreach ($places as $place) {
            if ($place['place_type'] === 'rest') {
                $town = $place;
                break;
            }
        }

        $legendaryNames = [];
        foreach ($this->all('SELECT * FROM legendary_names ORDER BY id') as $row) {
            $legendaryNames[] = self::legendaryNameRow($row);
        }

        return [
            'affixes' => $affixes,
            'legendary_names' => $legendaryNames,
            'templates' => $templates,
            'places' => array_values($places),
            'places_by_id' => $places,
            'town' => $town,
            'shop' => $shop,
            'consumables' => $consumables,
            'premium' => $this->premiumCatalog(),
        ];
    }

    private ?array $premiumCatalog = null;

    /** @return array<string, array> active and retired premium items keyed by code */
    public function premiumCatalog(): array
    {
        if ($this->premiumCatalog === null) {
            $this->premiumCatalog = [];
            foreach ($this->all('SELECT * FROM premium_items ORDER BY sort_order, id') as $row) {
                $this->premiumCatalog[$row['code']] = Premium::row($row);
            }
        }

        return $this->premiumCatalog;
    }

    public static function legendaryNameRow(array $row): array
    {
        return [
            'code' => $row['code'],
            'kind' => $row['kind'],
            'name' => $row['name'],
            'item_type' => $row['item_type'],
            'slot' => $row['slot'],
            'stat_key' => $row['stat_key'],
            'placement' => $row['placement'],
            'min_ilvl' => (int) $row['min_ilvl'],
            'weight' => (int) $row['weight'],
        ];
    }

    public static function template(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'slot' => $row['slot'],
            'template_slot' => $row['slot'],
            'item_type' => $row['item_type'],
            'scaling_stat' => $row['scaling_stat'],
            'two_handed' => (bool) $row['two_handed'],
            'base_power' => (int) $row['base_power'],
            'base_armor' => (int) $row['base_armor'],
            'base_mres' => (int) $row['base_mres'],
            'power' => (int) $row['base_power'],
            'armor' => (int) $row['base_armor'],
            'mres' => (int) $row['base_mres'],
            'implicit' => self::json($row['implicit_mods']) ?: [],
            'req_level' => (int) $row['req_level'],
            'base_value' => (int) $row['base_value'],
        ];
    }

    private static function monster(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'level' => (int) $row['level'],
            'is_boss' => (bool) $row['is_boss'],
            'hp' => (int) $row['hp'],
            'attack' => (int) $row['attack'],
            'defense' => (int) $row['defense'],
            'mres' => (int) $row['mres'],
            'speed' => (int) $row['speed'],
            'crit_pct' => (float) $row['crit_pct'],
            'dodge_pct' => (float) $row['dodge_pct'],
            'damage_type' => $row['damage_type'],
            'xp' => (int) $row['xp'],
            'gold_min' => (int) $row['gold_min'],
            'gold_max' => (int) $row['gold_max'],
            'drop_chance' => (float) $row['drop_chance'],
            'loot_table' => null,
        ];
    }

    private static function place(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'place_type' => $row['place_type'],
            'min_level' => (int) $row['min_level'],
            'max_level' => $row['max_level'] === null ? null : (int) $row['max_level'],
            'rec_min_level' => (int) $row['rec_min_level'],
            'rec_max_level' => (int) $row['rec_max_level'],
            'rec_level' => (int) $row['rec_level'],
            'encounter_min' => (int) $row['encounter_min'],
            'encounter_max' => (int) $row['encounter_max'],
            'boss_chance' => (float) $row['boss_chance'],
            'boss_monster_id' => $row['boss_monster_id'] === null ? null : (int) $row['boss_monster_id'],
            'rarity_bonus' => (float) $row['rarity_bonus'],
            'gold_mult' => (float) $row['gold_mult'],
            'map_x' => (int) $row['map_x'],
            'map_y' => (int) $row['map_y'],
            'is_active' => (bool) $row['is_active'],
            'description' => $row['description'],
            'boss' => null,
            'chest' => null,
            'spawns' => [],
        ];
    }

    private static function consumableTemplate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'effect_type' => $row['effect_type'],
            'effect_value' => (float) $row['effect_value'],
            'use_trigger' => $row['use_trigger'],
            'req_level' => (int) $row['req_level'],
            'sell_price' => (int) $row['sell_price'],
            'base_price' => (int) $row['base_price'],
        ];
    }

    // ------------------------------------------------------------- snapshot

    /** Decide every active character's plan for this run (DESIGN.md §1.3 step 2). */
    public function snapshotOrders(array $run, array $world): array
    {
        $chars = $this->all('SELECT id, name, level, is_bot, injury_severity, auto_repeat, last_place_id FROM characters WHERE is_active = 1 ORDER BY id');
        $orders = [];
        foreach ($this->all("SELECT * FROM trip_orders WHERE status = 'pending' AND (submitted_at <= ? OR submitted_by = 'bot')", [$run['started_at']]) as $row) {
            $orders[(int) $row['character_id']] = $row;
        }
        $town = $world['town'];
        $plans = [];
        foreach ($chars as $char) {
            $cid = (int) $char['id'];
            $level = (int) $char['level'];
            $rest = ['plan' => 'rest', 'place' => $town, 'order_id' => null, 'reject_reason' => null];
            if ((int) $char['injury_severity'] >= 2) {
                $plans[$cid] = ['plan' => 'blocked'] + $rest;

                continue;
            }
            $order = $orders[$cid] ?? null;
            if ($order) {
                $place = $world['places_by_id'][(int) $order['place_id']] ?? null;
                $plans[$cid] = $this->planFor($level, $place, (int) $order['id'], $world, $rest);

                continue;
            }
            if ($char['auto_repeat'] && $char['last_place_id']) {
                $place = $world['places_by_id'][(int) $char['last_place_id']] ?? null;
                if ($place) {
                    $plans[$cid] = $this->planFor($level, $place, null, $world, $rest);
                    if ($plans[$cid]['plan'] === 'combat') {
                        $plans[$cid]['submitted_by'] = 'auto_repeat';
                    }

                    continue;
                }
            }
            $plans[$cid] = $rest;
        }
        ksort($plans);

        return $plans;
    }

    private function planFor(int $level, ?array $place, ?int $orderId, array $world, array $rest): array
    {
        if ($place === null) {
            // The ordered place was deactivated after the order was placed.
            return ['plan' => 'rejected', 'place' => $world['town'], 'order_id' => $orderId, 'reject_reason' => 'inactive',
                'open_places' => Rules::openPlaceNames($level, $world['places'])];
        }
        if ($place['place_type'] === 'rest') {
            return ['order_id' => $orderId] + $rest;
        }
        [$ok, $reason] = Rules::canEnter($level, $place);
        if (! $ok) {
            return ['plan' => 'rejected', 'place' => $place, 'order_id' => $orderId, 'reject_reason' => $reason,
                'open_places' => Rules::openPlaceNames($level, $world['places'])];
        }

        return ['plan' => 'combat', 'place' => $place, 'order_id' => $orderId, 'reject_reason' => null];
    }

    // ------------------------------------------------------------ character

    public function loadCharacter(int $characterId): array
    {
        $row = $this->one('SELECT * FROM characters WHERE id = ?', [$characterId]);
        $bestRec = null;
        if ($row['best_place_id']) {
            $best = $this->one('SELECT rec_level FROM places WHERE id = ?', [$row['best_place_id']]);
            $bestRec = $best ? (int) $best['rec_level'] : null;
        }
        $items = $this->loadItems($characterId);
        $equipment = array_fill_keys(Rules::EQUIP_SLOTS, null);
        $equippedIds = [];
        foreach ($this->all('SELECT slot, item_instance_id FROM character_equipment WHERE character_id = ?', [$characterId]) as $eq) {
            $id = (int) $eq['item_instance_id'];
            if (isset($items[$id])) {
                $items[$id]['equip_slot'] = $eq['slot'];
                $equipment[$eq['slot']] = $items[$id];
                $equippedIds[] = $id;
            }
        }
        $consumables = $this->loadConsumables($characterId);
        $commons = [];
        $inventoryCount = 0;
        foreach ($items as $item) {
            if ($item['status'] !== 'owned' || in_array($item['id'], $equippedIds, true)) {
                continue;
            }
            $inventoryCount++;
            if ($item['rarity'] === 'common') {
                $commons[] = ['id' => $item['id'], 'sell_value' => $item['sell_value'], 'name' => $item['display_name']];
            }
        }
        $profileId = $row['bot_profile_id'];
        if (! $profileId && $row['auto_mode'] && $row['auto_profile_id']) {
            $profileId = $row['auto_profile_id'];
        }
        $profile = null;
        if ($profileId) {
            $profile = self::profile($this->one('SELECT * FROM bot_profiles WHERE id = ?', [$profileId]));
        }

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'is_bot' => (bool) $row['is_bot'],
            'level' => (int) $row['level'],
            'xp' => (int) $row['xp'],
            'total_xp' => (int) $row['total_xp'],
            'gold' => (int) $row['gold'],
            'stats' => [
                'str' => (int) $row['stat_str'],
                'dex' => (int) $row['stat_dex'],
                'int' => (int) $row['stat_int'],
                'vit' => (int) $row['stat_vit'],
                'luk' => (int) $row['stat_luk'],
            ],
            'unspent' => (int) $row['unspent_points'],
            'retreat_threshold_pct' => (int) $row['retreat_threshold_pct'],
            'potion_threshold_pct' => (int) $row['potion_threshold_pct'],
            'auto_repeat' => (bool) $row['auto_repeat'],
            'auto_mode' => (bool) $row['auto_mode'],
            'auto_place' => ($row['auto_place'] ?? 'profile') === 'best' ? 'best' : 'profile',
            'injury_severity' => (int) $row['injury_severity'],
            'injury_runs_left' => (int) $row['injury_runs_left'],
            'last_place_id' => $row['last_place_id'] === null ? null : (int) $row['last_place_id'],
            'best_place_rec' => $bestRec,
            'trips_total' => (int) $row['trips_total'],
            'equipment' => $equipment,
            'items' => array_values($items),
            'consumables' => $consumables,
            'belt' => array_values(array_filter($consumables, fn ($c) => $c['belt_qty'] > 0)),
            'commons' => $commons,
            'inventory_count' => $inventoryCount,
            'profile' => $profile,
            'reports' => $this->reports($characterId, $profile['memory_window'] ?? 8),
            'row_version' => (int) $row['row_version'],
            'premium' => Premium::state(
                $row['premium_cash'] === null ? null : (int) $row['premium_cash'],
                $row['premium'] ?? null,
                $this->premiumCatalog(),
                Balance::get(),
            ),
        ] + $this->loadGrowth($row);
    }

    public static function profile(array $row): array
    {
        $gear = self::json($row['gear_weights']) ?: [];

        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'weights' => [
                'str' => (float) $row['w_str'],
                'dex' => (float) $row['w_dex'],
                'int' => (float) $row['w_int'],
                'vit' => (float) $row['w_vit'],
                'luk' => (float) $row['w_luk'],
            ],
            'gear_weights' => array_map('floatval', $gear),
            'preferred_scaling' => $row['preferred_scaling'],
            'risk_offset' => (int) $row['risk_offset'],
            'retreat_threshold_pct' => (int) $row['retreat_threshold_pct'],
            'randomness' => (float) $row['randomness'],
            'rarity_preference' => (float) $row['rarity_preference'],
            'memory_window' => (int) $row['memory_window'],
            'fail_threshold' => (int) $row['fail_threshold'],
            'fail_avoid_runs' => (int) $row['fail_avoid_runs'],
            'stepup_after_wins' => (int) $row['stepup_after_wins'],
            'stepup_hp_pct' => (int) $row['stepup_hp_pct'],
            'rest_when_injured' => (bool) $row['rest_when_injured'],
            'sell_junk' => (bool) $row['sell_junk'],
            'gold_reserve_pct' => (int) $row['gold_reserve_pct'],
            'potion_target' => (int) $row['potion_target'],
            'buff_preference' => $row['buff_preference'],
            'shop_min_gain_pct' => (int) $row['shop_min_gain_pct'],
        ];
    }

    private function reports(int $characterId, int $window): array
    {
        $rows = $this->all(
            "SELECT payload FROM mail WHERE character_id = ? AND mail_type = 'trip_report' AND payload IS NOT NULL ORDER BY id DESC LIMIT ?",
            [$characterId, $window],
        );
        $reports = [];
        foreach ($rows as $row) {
            $payload = self::json($row['payload']) ?: [];
            if (($payload['place_id'] ?? null) === null && ! in_array($payload['outcome'] ?? null, ['rested', 'blocked_injured'], true)) {
                continue;
            }
            $reports[] = $payload;
        }

        return $reports;
    }

    /** @return array<int, array> owned items keyed by id, with affixes */
    private function loadItems(int $characterId): array
    {
        $rows = $this->all(
            "SELECT ii.*, it.code, it.name AS template_name, it.slot AS template_slot, it.item_type,
                    it.scaling_stat, it.two_handed, it.implicit_mods, it.req_level, it.base_value
               FROM item_instances ii
               JOIN item_templates it ON it.id = ii.template_id
              WHERE ii.character_id = ? AND ii.status = 'owned'
              ORDER BY ii.id",
            [$characterId],
        );
        if ($rows === []) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $grouped = [];
        foreach ($this->all(
            "SELECT a.*, af.code, af.name, af.kind FROM item_instance_affixes a JOIN affixes af ON af.id = a.affix_id
              WHERE a.item_instance_id IN ({$marks}) ORDER BY a.id",
            $ids,
        ) as $affix) {
            $grouped[(int) $affix['item_instance_id']][] = [
                'affix_id' => (int) $affix['affix_id'],
                'code' => $affix['code'],
                'name' => $affix['name'],
                'kind' => $affix['kind'],
                'stat_key' => $affix['stat_key'],
                'value' => (int) $affix['value'],
            ];
        }
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $items[$id] = [
                'id' => $id,
                'template_id' => (int) $row['template_id'],
                'code' => $row['code'],
                'name' => $row['template_name'],
                'slot' => $row['template_slot'],
                'template_slot' => $row['template_slot'],
                'item_type' => $row['item_type'],
                'scaling_stat' => $row['scaling_stat'],
                'two_handed' => (bool) $row['two_handed'],
                'power' => (int) $row['power'],
                'armor' => (int) $row['armor'],
                'mres' => (int) $row['mres'],
                'implicit' => self::json($row['implicit_mods']) ?: [],
                'affixes' => $grouped[$id] ?? [],
                'req_level' => (int) $row['req_level'],
                'base_value' => (int) $row['base_value'],
                'rarity' => $row['rarity'],
                'item_level' => (int) $row['item_level'],
                'display_name' => $row['display_name'],
                'sell_value' => (int) $row['sell_value'],
                'source' => $row['source'],
                'status' => $row['status'],
            ];
        }

        return $items;
    }

    private function loadConsumables(int $characterId): array
    {
        $rows = $this->all(
            'SELECT cc.*, ct.code, ct.name, ct.effect_type, ct.effect_value, ct.use_trigger, ct.req_level, ct.sell_price
               FROM character_consumables cc
               JOIN consumable_templates ct ON ct.id = cc.consumable_id
              WHERE cc.character_id = ?
              ORDER BY cc.consumable_id',
            [$characterId],
        );

        return array_map(fn ($row) => [
            'id' => (int) $row['consumable_id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'effect_type' => $row['effect_type'],
            'effect_value' => (float) $row['effect_value'],
            'use_trigger' => $row['use_trigger'],
            'req_level' => (int) $row['req_level'],
            'quantity' => (int) $row['quantity'],
            'belt_qty' => (int) $row['belt_qty'],
            'sell_price' => (int) $row['sell_price'],
        ], $rows);
    }

    public function loadTripContext(int $characterId, array $plan, array $world, array $balance, array $run): array
    {
        $char = $this->loadCharacter($characterId);
        $char['equipment'] = array_values(array_filter($char['equipment']));

        return [
            'character' => $char,
            'place' => $plan['place'],
            'town' => $world['town'],
            'plan' => $plan['plan'],
            'order_id' => $plan['order_id'] ?? null,
            'reject_reason' => $plan['reject_reason'] ?? null,
            'open_places' => $plan['open_places'] ?? [],
            'submitted_by' => $plan['submitted_by'] ?? null,
            'affixes' => $world['affixes'],
            'legendary_names' => $world['legendary_names'] ?? [],
            'inventory_count' => $char['inventory_count'],
            'commons' => $char['commons'],
            'balance' => $balance,
            'run_seed' => (int) $run['seed'],
            'run_id' => (int) $run['id'],
        ];
    }

    // ---------------------------------------------------------------- apply

    /** Write one character's trip. The caller wraps this in a transaction. Returns the trip id (0 if already applied). */
    public function applyTrip(array $run, array $ctx, array $result, array $rendered): int
    {
        [$subject, $body, $payload] = $rendered;
        $runId = (int) $run['id'];
        $cid = $ctx['character']['id'];
        $locked = $this->one('SELECT * FROM characters WHERE id = ? FOR UPDATE', [$cid]);
        if ($this->tripExists($runId, $cid)) {
            return 0;
        }
        $place = $result['place'];
        $this->db->insert(
            'INSERT INTO trips (run_id, character_id, place_id, order_id, outcome, encounters_planned, encounters_won,
                                boss_encountered, boss_killed, max_hp, hp_end, xp_gained, gold_gained, level_before,
                                level_after, rng_seed, snapshot, consumables_used)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $runId, $cid, $place['id'], $result['order_id'], $result['outcome'],
                $result['encounters_planned'], $result['encounters_won'],
                $result['boss_encountered'] ? 1 : 0, $result['boss_killed'] ? 1 : 0,
                max(0, (int) $result['max_hp']), max(0, (int) $result['hp_end']),
                (int) $result['xp_gained'], (int) $result['gold_gained'],
                (int) $result['level_before'], (int) $result['level_after'],
                $result['rng_seed'], self::dumps($result['snapshot']), self::dumps($result['uses']),
            ],
        );
        $tripId = $this->insertId();
        if (! empty($result['loot_box'])) {
            $box = $result['loot_box'];
            $this->db->insert(
                'INSERT INTO loot_boxes (character_id, trip_id, place_id, seed, luck_factor, source_level, rolls) VALUES (?,?,?,?,?,?,?)',
                [$cid, $tripId, $place['id'], (int) $box['seed'], $box['luck_factor'], (int) $box['source_level'], (int) $box['rolls']],
            );
        }
        foreach ($result['encounters'] as $enc) {
            $this->db->insert(
                'INSERT INTO trip_encounters (trip_id, seq, is_boss, monsters, result, rounds, damage_dealt, damage_taken,
                                              hp_before, hp_after, xp, gold, log)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $tripId, $enc['seq'], $enc['is_boss'] ? 1 : 0, self::dumps($enc['monsters']), $enc['result'],
                    $enc['rounds'], $enc['damage_dealt'], $enc['damage_taken'],
                    max(0, $enc['hp_before']), max(0, $enc['hp_after']), $enc['xp'], $enc['gold'], self::dumps($enc['log']),
                ],
            );
        }

        $autosellGold = 0;
        $runningGold = (int) $locked['gold'];
        foreach ($result['items'] as $item) {
            $this->insertItem($item, $cid, $runId, $tripId, 'owned', true);
        }
        foreach ($result['lost_items'] as $item) {
            $this->insertItem($item, null, $runId, $tripId, 'lost', false);
        }
        foreach ($result['autosell_new'] as $item) {
            $itemId = $this->insertItem($item, null, $runId, $tripId, 'sold', false);
            $payout = (int) $item['sell_value'];
            $autosellGold += $payout;
            $runningGold += $payout;
            $this->ledger($cid, 'sim_autosell', $runId, 'sell', null, $itemId, null, 1, $payout, $runningGold);
        }
        $bag = self::json($locked['materials']) ?: [];
        foreach ($result['materials'] ?? [] as $stack) {
            $bag[$stack['code']] = (int) ($bag[$stack['code']] ?? 0) + (int) $stack['qty'];
        }
        $this->logMaterials($tripId, $result['materials'] ?? [], true);
        $this->logMaterials($tripId, $result['lost_materials'] ?? [], false);

        foreach ($result['autosell_owned'] as $sold) {
            $payout = (int) $sold['sell_value'];
            $autosellGold += $payout;
            $runningGold += $payout;
            $this->db->update("UPDATE item_instances SET status = 'sold', character_id = NULL WHERE id = ?", [$sold['id']]);
            $this->ledger($cid, 'sim_autosell', $runId, 'sell', null, $sold['id'], null, 1, $payout, $runningGold);
        }

        $goldDelta = (int) $result['gold_gained'] + (int) $result['level_gold'] + $autosellGold;
        $stayedHome = in_array($result['outcome'], ['rested', 'blocked_injured', 'order_rejected'], true);
        $lastPlace = $stayedHome ? $ctx['town']['id'] : $place['id'];
        $bestId = $result['best_place'] ? $place['id'] : null;
        $this->db->update(
            'UPDATE characters SET
               level = ?, xp = ?, total_xp = total_xp + ?, gold = gold + ?,
               unspent_points = unspent_points + ?,
               injury_severity = ?, injury_runs_left = ?,
               last_place_id = ?,
               best_place_id = COALESCE(?, best_place_id),
               trips_total = trips_total + 1,
               trips_cleared = trips_cleared + ?,
               trips_retreated = trips_retreated + ?,
               defeats = defeats + ?,
               kills = kills + ?,
               boss_kills = boss_kills + ?,
               materials = ?,
               row_version = row_version + 1
             WHERE id = ?',
            [
                (int) $result['level_after'], (int) $result['xp_after'], (int) $result['xp_gained'], $goldDelta,
                (int) $result['points_gained'],
                (int) $result['injury']['severity'], (int) $result['injury']['runs_left'],
                $lastPlace, $bestId,
                $result['outcome'] === 'cleared' ? 1 : 0,
                $result['outcome'] === 'retreated' ? 1 : 0,
                $result['outcome'] === 'defeated' ? 1 : 0,
                (int) $result['kills'], (int) $result['boss_kills'],
                $bag === [] ? null : self::dumps($bag),
                $cid,
            ],
        );
        $this->consumeUses($cid, $result['uses']);
        $this->closeOrder($cid, $result, $runId, $ctx);
        $payload['trip_id'] = $tripId;
        $this->db->insert(
            "INSERT INTO mail (character_id, run_id, trip_id, mail_type, subject, body_text, payload) VALUES (?, ?, ?, 'trip_report', ?, ?, ?)",
            [$cid, $runId, $tripId, $subject, $body, self::dumps($payload)],
        );
        $this->db->update(
            'UPDATE simulation_runs SET characters_done = characters_done + 1, heartbeat_at = UTC_TIMESTAMP() WHERE id = ?',
            [$runId],
        );

        return $tripId;
    }

    private function loadGrowth(array $row): array
    {
        $owned = self::json($row['materials'] ?? null) ?: [];
        $materials = [];
        foreach ($this->all('SELECT code, name, kind, glyph, description FROM material_templates ORDER BY sort_order, id') as $template) {
            $materials[] = [
                'code' => $template['code'],
                'name' => $template['name'],
                'kind' => $template['kind'],
                'glyph' => $template['glyph'],
                'description' => $template['description'],
                'qty' => (int) ($owned[$template['code']] ?? 0),
            ];
        }
        $skills = self::json($row['skills'] ?? null) ?: [];

        return [
            'materials' => $materials,
            'skills' => $skills,
            'horcrux' => [
                'tier' => (int) ($row['horcrux_tier'] ?? 0),
                'progress' => (int) ($row['horcrux_progress'] ?? 0),
            ],
        ];
    }

    /** @param list<array{code: string, qty: int}> $stacks */
    private function logMaterials(int $tripId, array $stacks, bool $kept): void
    {
        foreach ($stacks as $stack) {
            $template = $this->one('SELECT id FROM material_templates WHERE code = ?', [$stack['code']]);
            if (! $template) {
                continue;
            }
            $this->db->insert(
                'INSERT INTO trip_materials (trip_id, material_id, qty, kept) VALUES (?,?,?,?)',
                [$tripId, (int) $template['id'], (int) $stack['qty'], $kept ? 1 : 0],
            );
        }
    }

    private function insertItem(array $item, ?int $characterId, int $runId, int $tripId, string $status, bool $kept): int
    {
        $this->db->insert(
            'INSERT INTO item_instances (template_id, character_id, status, rarity, item_level, display_name, power, armor, mres,
                                         sell_value, source, created_run_id, created_trip_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $item['template_id'], $characterId, $status, $item['rarity'], $item['item_level'], $item['display_name'],
                $item['power'], $item['armor'], $item['mres'], $item['sell_value'], $item['source'], $runId, $tripId,
            ],
        );
        $itemId = $this->insertId();
        $this->insertAffixes($itemId, $item['affixes'] ?? []);
        $this->db->insert(
            'INSERT INTO trip_loot (trip_id, item_instance_id, encounter_seq, kept) VALUES (?,?,?,?)',
            [$tripId, $itemId, $item['encounter_seq'] ?? null, $kept ? 1 : 0],
        );

        return $itemId;
    }

    private function insertAffixes(int $itemId, array $affixes): void
    {
        foreach ($affixes as $affix) {
            if (empty($affix['affix_id'])) {
                continue;
            }
            $this->db->insert(
                'INSERT INTO item_instance_affixes (item_instance_id, affix_id, stat_key, value) VALUES (?, ?, ?, ?)',
                [$itemId, $affix['affix_id'], $affix['stat_key'], $affix['value']],
            );
        }
    }

    private function ledger(int $characterId, string $actor, ?int $runId, string $action, ?int $listingId, ?int $itemId, ?int $consumableId, int $qty, int $delta, int $goldAfter): void
    {
        $this->db->insert(
            'INSERT INTO shop_transactions (character_id, actor, run_id, action, listing_id, item_instance_id, consumable_id, quantity, gold_delta, gold_after)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$characterId, $actor, $runId, $action, $listingId, $itemId, $consumableId, $qty, $delta, max(0, $goldAfter)],
        );
    }

    private function consumeUses(int $characterId, array $uses): void
    {
        foreach ($uses as $use) {
            if (empty($use['id'])) {
                continue;
            }
            $row = $this->one('SELECT quantity, belt_qty FROM character_consumables WHERE character_id = ? AND consumable_id = ?', [$characterId, $use['id']]);
            if (! $row) {
                continue;
            }
            $qty = max(0, (int) $row['quantity'] - (int) $use['qty']);
            $belt = min(max(0, (int) $row['belt_qty'] - (int) $use['qty']), $qty);
            if ($qty === 0) {
                $this->db->delete('DELETE FROM character_consumables WHERE character_id = ? AND consumable_id = ?', [$characterId, $use['id']]);
            } else {
                $this->db->update(
                    'UPDATE character_consumables SET quantity = ?, belt_qty = ? WHERE character_id = ? AND consumable_id = ?',
                    [$qty, $belt, $characterId, $use['id']],
                );
            }
        }
    }

    private function closeOrder(int $characterId, array $result, int $runId, array $ctx): void
    {
        $orderId = $result['order_id'] ?? null;
        if (! empty($result['cancel_order']) && $orderId) {
            $this->db->update("UPDATE trip_orders SET status = 'cancelled' WHERE id = ? AND status = 'pending'", [$orderId]);

            return;
        }
        if ($orderId && $result['outcome'] !== 'blocked_injured') {
            $this->db->update("UPDATE trip_orders SET status = 'consumed', consumed_run_id = ? WHERE id = ?", [$runId, $orderId]);

            return;
        }
        if (($ctx['submitted_by'] ?? null) === 'auto_repeat' && ! in_array($result['outcome'], ['order_rejected', 'blocked_injured'], true)) {
            $this->db->insert(
                "INSERT INTO trip_orders (character_id, place_id, submitted_by, status, consumed_run_id) VALUES (?, ?, 'auto_repeat', 'consumed', ?)",
                [$characterId, $result['place']['id'], $runId],
            );
        }
    }

    /** Write one bot decision. The caller wraps this in a transaction. */
    /**
     * Bots open every sealed chest before they shop or equip. Humans open theirs on the website.
     * The chest seed is independent of the bot decision seed.
     */
    public function openSealedBoxes(int $characterId, array $world, array $balance): void
    {
        $char = $this->one('SELECT id, is_bot, auto_mode, gold FROM characters WHERE id = ? FOR UPDATE', [$characterId]);
        if (! $char || (! (int) $char['is_bot'] && ! (int) $char['auto_mode'])) {
            return;
        }
        $boxes = $this->all(
            "SELECT * FROM loot_boxes WHERE character_id = ? AND status = 'sealed' ORDER BY id FOR UPDATE",
            [$characterId],
        );
        if ($boxes === []) {
            return;
        }
        $capacity = (int) $balance['loot']['inventory_capacity'];
        $commons = [];
        $count = 0;
        foreach ($this->all(
            "SELECT ii.id, ii.rarity, ii.sell_value, ii.display_name
               FROM item_instances ii
               LEFT JOIN character_equipment ce ON ce.item_instance_id = ii.id
              WHERE ii.character_id = ? AND ii.status = 'owned' AND ce.item_instance_id IS NULL",
            [$characterId],
        ) as $row) {
            $count++;
            if ($row['rarity'] === 'common') {
                $commons[] = ['id' => (int) $row['id'], 'sell_value' => (int) $row['sell_value'], 'name' => $row['display_name']];
            }
        }
        $gold = (int) $char['gold'];
        foreach ($boxes as $box) {
            $place = $world['places_by_id'][(int) $box['place_id']] ?? null;
            $rolled = Loot::rollChest(
                $place['chest'] ?? null,
                (int) $box['rolls'],
                (float) $box['luck_factor'],
                (int) $box['source_level'],
                $world['affixes'],
                Rng::chest((int) $box['seed']),
                $balance,
                $world['legendary_names'] ?? [],
            );
            $plan = Loot::planCapacity($rolled, $commons, $capacity, $count);
            $soldIds = [];
            foreach ($plan['sold_owned'] as $sold) {
                $soldIds[] = (int) $sold['id'];
                $payout = (int) $sold['sell_value'];
                $gold += $payout;
                $this->db->update("UPDATE item_instances SET status = 'sold', character_id = NULL WHERE id = ?", [$sold['id']]);
                $this->ledger($characterId, 'sim_autosell', null, 'sell', null, (int) $sold['id'], null, 1, $payout, $gold);
            }
            foreach ($plan['kept'] as $item) {
                $itemId = $this->insertChestItem($item, $characterId, (int) $box['trip_id'], 'owned', true);
                if ($item['rarity'] === 'common') {
                    $commons[] = ['id' => $itemId, 'sell_value' => (int) $item['sell_value'], 'name' => $item['display_name']];
                }
            }
            foreach ($plan['sold_new'] as $item) {
                $itemId = $this->insertChestItem($item, null, (int) $box['trip_id'], 'sold', false);
                $payout = (int) $item['sell_value'];
                $gold += $payout;
                $this->ledger($characterId, 'sim_autosell', null, 'sell', null, $itemId, null, 1, $payout, $gold);
            }
            if ($soldIds !== []) {
                $commons = array_values(array_filter($commons, fn ($row) => ! in_array($row['id'], $soldIds, true)));
            }
            $count = $count - count($plan['sold_owned']) + count($plan['kept']);
            $this->db->update(
                "UPDATE loot_boxes SET status = 'opened', opened_at = UTC_TIMESTAMP() WHERE id = ?",
                [$box['id']],
            );
        }
        $this->db->update(
            'UPDATE characters SET gold = ?, row_version = row_version + 1 WHERE id = ?',
            [max(0, $gold), $characterId],
        );
    }

    private function insertChestItem(array $item, ?int $characterId, int $tripId, string $status, bool $kept): int
    {
        $this->db->insert(
            'INSERT INTO item_instances (template_id, character_id, status, rarity, item_level, display_name, power, armor, mres,
                                         sell_value, source, created_trip_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $item['template_id'], $characterId, $status, $item['rarity'], $item['item_level'], $item['display_name'],
                $item['power'], $item['armor'], $item['mres'], $item['sell_value'], 'chest', $tripId,
            ],
        );
        $itemId = $this->insertId();
        $this->insertAffixes($itemId, $item['affixes'] ?? []);
        $this->db->insert(
            'INSERT INTO trip_loot (trip_id, item_instance_id, encounter_seq, kept) VALUES (?,?,?,?)',
            [$tripId, $itemId, null, $kept ? 1 : 0],
        );

        return $itemId;
    }

    public function applyBot(array $run, array $state, array $decision): void
    {
        $runId = (int) $run['id'];
        $cid = $state['id'];
        $this->one('SELECT id FROM characters WHERE id = ? FOR UPDATE', [$cid]);
        if ($this->botDecisionExists($runId, $cid)) {
            return;
        }
        $idMap = [];
        foreach ($decision['new_items'] as $item) {
            $this->db->insert(
                "INSERT INTO item_instances (template_id, character_id, status, rarity, item_level, display_name, power, armor, mres, sell_value, source, created_run_id)
                 VALUES (?,?,'owned',?,?,?,?,?,?,?,'shop',?)",
                [$item['template_id'], $cid, $item['rarity'], $item['item_level'], $item['display_name'],
                    $item['power'], $item['armor'], $item['mres'], $item['sell_value'], $runId],
            );
            $idMap[$item['id']] = $this->insertId();
            $this->insertAffixes($idMap[$item['id']], $item['affixes'] ?? []);
        }
        foreach ($decision['sold_items'] as $itemId) {
            if ($itemId === null || $itemId < 0) {
                continue;
            }
            $this->db->update("UPDATE item_instances SET status = 'sold', character_id = NULL WHERE id = ? AND character_id = ?", [$itemId, $cid]);
        }
        $this->db->delete('DELETE FROM character_equipment WHERE character_id = ?', [$cid]);
        foreach ($decision['equipped'] as $slot => $itemId) {
            if ($itemId === null) {
                continue;
            }
            $real = $idMap[$itemId] ?? $itemId;
            if ($real < 0) {
                continue;
            }
            $this->db->insert('INSERT INTO character_equipment (character_id, slot, item_instance_id) VALUES (?, ?, ?)', [$cid, $slot, $real]);
        }
        foreach ($decision['consumables'] as $row) {
            $existing = $this->one('SELECT quantity FROM character_consumables WHERE character_id = ? AND consumable_id = ?', [$cid, $row['id']]);
            $qty = (int) $row['quantity'];
            $belt = min((int) $row['belt_qty'], $qty);
            if ($qty <= 0) {
                if ($existing) {
                    $this->db->delete('DELETE FROM character_consumables WHERE character_id = ? AND consumable_id = ?', [$cid, $row['id']]);
                }

                continue;
            }
            if ($existing) {
                $this->db->update('UPDATE character_consumables SET quantity = ?, belt_qty = ? WHERE character_id = ? AND consumable_id = ?', [$qty, $belt, $cid, $row['id']]);
            } else {
                $this->db->insert('INSERT INTO character_consumables (character_id, consumable_id, quantity, belt_qty) VALUES (?,?,?,?)', [$cid, $row['id'], $qty, $belt]);
            }
        }
        $stats = $decision['stats'];
        $materialMap = [];
        foreach ($decision['materials'] ?? [] as $mat) {
            if ((int) ($mat['qty'] ?? 0) > 0) {
                $materialMap[$mat['code']] = (int) $mat['qty'];
            }
        }
        $horcrux = $decision['horcrux'] ?? ['tier' => 0, 'progress' => 0];
        $this->db->update(
            'UPDATE characters SET stat_str = ?, stat_dex = ?, stat_int = ?, stat_vit = ?, stat_luk = ?,
                    unspent_points = ?, gold = ?, injury_severity = ?, injury_runs_left = ?,
                    retreat_threshold_pct = ?, potion_threshold_pct = ?,
                    materials = ?, skills = ?, horcrux_tier = ?, horcrux_progress = ?,
                    row_version = row_version + 1
              WHERE id = ?',
            [
                $stats['str'], $stats['dex'], $stats['int'], $stats['vit'], $stats['luk'],
                $decision['unspent'], $decision['gold'], $decision['injury_severity'], $decision['injury_runs_left'],
                $decision['retreat_threshold_pct'], min(100, (int) $decision['potion_threshold_pct']),
                $materialMap === [] ? null : self::dumps($materialMap),
                ($decision['skills'] ?? []) === [] ? null : self::dumps($decision['skills']),
                (int) $horcrux['tier'], (int) $horcrux['progress'],
                $cid,
            ],
        );
        foreach ($decision['ledger'] as $entry) {
            $itemId = $entry['item_id'] ?? null;
            if (($entry['item_temp_id'] ?? null) !== null) {
                $itemId = $idMap[$entry['item_temp_id']] ?? null;
            }
            $this->db->insert(
                "INSERT INTO shop_transactions (character_id, actor, run_id, action, listing_id, item_instance_id, consumable_id, quantity, gold_delta, gold_after)
                 VALUES (?, 'bot', ?, ?, ?, ?, ?, ?, ?, ?)",
                [$cid, $runId, $entry['action'], $entry['listing_id'] ?? null, $itemId, $entry['consumable_id'] ?? null,
                    (int) ($entry['quantity'] ?? 1), (int) $entry['gold_delta'], (int) $entry['gold_after']],
            );
        }
        if ($decision['premium_changed'] ?? false) {
            $premium = $decision['premium'];
            $this->db->update(
                'UPDATE characters SET premium_cash = ?, premium = ?, row_version = row_version + 1 WHERE id = ?',
                [(int) $premium['cash'], Premium::encode($premium), $cid],
            );
            $catalog = $this->premiumCatalog();
            foreach ($decision['premium_bought'] ?? [] as $buy) {
                $this->db->insert(
                    "INSERT INTO shop_transactions (character_id, actor, run_id, action, premium_item_id, quantity, gold_delta, gold_after, cash_delta, cash_after)
                     VALUES (?, 'bot', ?, 'premium', ?, 1, 0, ?, ?, ?)",
                    [$cid, $runId, $catalog[$buy['code']]['id'], (int) $decision['gold'], -(int) $buy['price_cents'], (int) $buy['cash_after']],
                );
            }
        }
        $this->db->update("UPDATE trip_orders SET status = 'cancelled' WHERE character_id = ? AND status = 'pending'", [$cid]);
        $this->db->insert(
            "INSERT INTO trip_orders (character_id, place_id, submitted_by, submitted_at, status) VALUES (?, ?, 'bot', ?, 'pending')",
            [$cid, $decision['place_id'], $run['started_at']],
        );
        foreach ($decision['fed'] ?? [] as $itemId) {
            $this->db->update(
                "UPDATE item_instances SET status = 'destroyed', character_id = NULL WHERE id = ? AND character_id = ?",
                [$itemId, $cid],
            );
        }
        $this->db->update('UPDATE mail SET is_read = 1 WHERE character_id = ? AND is_read = 0', [$cid]);
        $public = [
            'points' => $decision['points'],
            'equipped' => array_map(fn ($id) => $id === null ? null : ($idMap[$id] ?? $id), $decision['equipped']),
            'sold' => $decision['sold_items'],
            'bought' => $decision['bought'],
            'belt' => $decision['belt'],
            'place' => $decision['place'],
            'premium' => array_column($decision['premium_bought'] ?? [], 'name'),
            'why' => $decision['why'],
        ];
        $this->db->insert('INSERT INTO bot_decisions (run_id, character_id, decision) VALUES (?, ?, ?)', [$runId, $cid, self::dumps($public)]);
    }

    /**
     * After the trip, spend every point the trip just granted and wear the best gear.
     * The next run's planner still shops, trains, and picks the map. Points are already gone.
     */
    public function settleAuto(int $characterId, int $seed, array $balance): void
    {
        $state = $this->loadCharacter($characterId);
        if (! $state['auto_mode'] || $state['profile'] === null) {
            return;
        }
        $hadPoints = (int) $state['unspent'] > 0;
        if ($hadPoints) {
            Allocation::allocate($state, Rng::bot($seed, $characterId), $balance);
        }
        $before = array_map(fn ($item) => $item['id'] ?? null, $state['equipment']);
        Gear::equipBest($state, $balance);
        $after = array_map(fn ($item) => $item['id'] ?? null, $state['equipment']);
        if (! $hadPoints && $before === $after) {
            return;
        }
        if ($hadPoints) {
            $stats = $state['stats'];
            $this->db->update(
                'UPDATE characters SET stat_str = ?, stat_dex = ?, stat_int = ?, stat_vit = ?, stat_luk = ?,
                        unspent_points = ?, row_version = row_version + 1
                  WHERE id = ?',
                [
                    $stats['str'], $stats['dex'], $stats['int'], $stats['vit'], $stats['luk'],
                    (int) $state['unspent'], $characterId,
                ],
            );
        }
        if ($before !== $after) {
            $this->db->delete('DELETE FROM character_equipment WHERE character_id = ?', [$characterId]);
            foreach ($state['equipment'] as $slot => $item) {
                if ($item === null || ($item['id'] ?? null) === null) {
                    continue;
                }
                $this->db->insert(
                    'INSERT INTO character_equipment (character_id, slot, item_instance_id) VALUES (?, ?, ?)',
                    [$characterId, $slot, $item['id']],
                );
            }
        }
    }
}
