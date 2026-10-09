<?php

namespace App\Services;

use App\Game\Balance;
use App\Game\Forge;
use App\Game\Growth;
use App\Game\Premium;
use App\Game\Progression;
use App\Game\Rules;
use App\Game\ShopMath;
use App\Game\Stats;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Read models for the pages. Everything is scoped by the session's account id. */
class GameQueries
{
    public const MAIL_PER_PAGE = 15;

    private const BUFF_EFFECTS = ['buff_attack_pct' => 'might', 'buff_defense_pct' => 'stoneskin', 'luck_bonus' => 'luck'];

    private ?int $contentCeiling = null;

    // ------------------------------------------------------------ helpers

    public static function utc(?string $value): ?string
    {
        return $value === null ? null : str_replace(' ', 'T', $value).'Z';
    }

    private static function json(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }

    private ?array $premiumCatalog = null;

    /** @return array<string, array> every premium item, keyed by code */
    public function premiumCatalog(): array
    {
        if ($this->premiumCatalog === null) {
            $this->premiumCatalog = [];
            foreach (DB::table('premium_items')->orderBy('sort_order')->orderBy('id')->get() as $row) {
                $this->premiumCatalog[$row->code] = Premium::row((array) $row);
            }
        }

        return $this->premiumCatalog;
    }

    public function premiumState(array|object $char): array
    {
        $char = (array) $char;
        $cash = array_key_exists('premium_cash', $char) && $char['premium_cash'] !== null ? (int) $char['premium_cash'] : null;

        return Premium::state($cash, $char['premium'] ?? null, $this->premiumCatalog(), Balance::get());
    }

    /** What the UI needs to draw a premium piece. */
    public static function premiumView(array $item): array
    {
        return [
            'code' => $item['code'],
            'name' => $item['name'],
            'category' => $item['category'],
            'tier' => $item['tier'],
            'glyph' => $item['glyph'],
            'style' => $item['style'],
            'description' => $item['description'],
            'price_cents' => $item['price_cents'],
            'price' => Premium::money($item['price_cents']),
            'bonuses' => (object) $item['bonuses'],
            'power' => $item['power'],
            'grant_gold' => $item['grant_gold'],
        ];
    }

    /** @return array{title: array|null, gear: list<array>} the worn pieces, for names and portraits */
    private function appearance(array $premium): array
    {
        $title = null;
        $gear = [];
        foreach ($premium['gear'] as $item) {
            $view = self::premiumView($item);
            $gear[] = $view;
            if ($item['category'] === 'title') {
                $title = $view;
            }
        }

        return ['title' => $title, 'gear' => $gear];
    }

    private function bonusFor(array $growth, array $premium, array $balance): array
    {
        return Stats::mergeBonus(
            Growth::bonuses($growth['ranks'], $growth['horcrux']['tier'], $balance),
            Premium::bonuses($premium['gear']),
        );
    }

    private function powerFor(int $level, array $growth, array $premium, array $balance): int
    {
        return Growth::powerLevel($level, $growth['ranks'], $growth['horcrux']['tier'], $balance, Premium::power($premium['gear']));
    }

    public function character(int $accountId): array
    {
        $row = DB::table('characters')->where('account_id', $accountId)->first();
        if (! $row) {
            throw new NotFoundHttpException('No character for this account.');
        }

        return (array) $row;
    }

    public function stats(array $char): array
    {
        return [
            'str' => (int) $char['stat_str'],
            'dex' => (int) $char['stat_dex'],
            'int' => (int) $char['stat_int'],
            'vit' => (int) $char['stat_vit'],
            'luk' => (int) $char['stat_luk'],
        ];
    }

    /** @return array<int, list<array>> affixes grouped by item id */
    private function affixesFor(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $grouped = [];
        foreach (DB::table('item_instance_affixes as a')
            ->join('affixes as af', 'af.id', '=', 'a.affix_id')
            ->whereIn('a.item_instance_id', $itemIds)
            ->orderBy('a.id')
            ->get(['a.item_instance_id', 'a.stat_key', 'a.value', 'af.name', 'af.kind']) as $row) {
            $grouped[(int) $row->item_instance_id][] = [
                'stat_key' => $row->stat_key,
                'value' => (int) $row->value,
                'name' => $row->name,
                'kind' => $row->kind,
            ];
        }

        return $grouped;
    }

    private function itemView(object $row, array $affixes): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->display_name,
            'template_name' => $row->template_name,
            'code' => $row->code,
            'rarity' => $row->rarity,
            'slot' => $row->template_slot,
            'template_slot' => $row->template_slot,
            'equip_slot' => $row->equip_slot ?? null,
            'item_type' => $row->item_type,
            'scaling_stat' => $row->scaling_stat,
            'two_handed' => (bool) $row->two_handed,
            'item_level' => (int) $row->item_level,
            'req_level' => (int) $row->req_level,
            'power' => (int) $row->power,
            'armor' => (int) $row->armor,
            'mres' => (int) $row->mres,
            'sell_value' => (int) $row->sell_value,
            'source' => $row->source,
            'implicit' => self::json($row->implicit_mods) ?: [],
            'affixes' => $affixes[(int) $row->id] ?? [],
            'description' => $row->description,
        ];
    }

    private function itemQuery()
    {
        return DB::table('item_instances as ii')
            ->join('item_templates as it', 'it.id', '=', 'ii.template_id')
            ->select('ii.*', 'it.code', 'it.name as template_name', 'it.slot as template_slot', 'it.item_type',
                'it.scaling_stat', 'it.two_handed', 'it.implicit_mods', 'it.req_level', 'it.description');
    }

    /** @return array<string, array> equipped items keyed by equipment slot */
    public function equipment(int $characterId): array
    {
        $rows = $this->itemQuery()
            ->join('character_equipment as ce', 'ce.item_instance_id', '=', 'ii.id')
            ->addSelect('ce.slot as equip_slot')
            ->where('ce.character_id', $characterId)
            ->get();
        $affixes = $this->affixesFor($rows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $out = [];
        foreach ($rows as $row) {
            $out[$row->equip_slot] = $this->itemView($row, $affixes);
        }

        return $out;
    }

    /** @return list<array> owned, unequipped items, best first */
    public function inventory(int $characterId): array
    {
        $rows = $this->itemQuery()
            ->leftJoin('character_equipment as ce', 'ce.item_instance_id', '=', 'ii.id')
            ->where('ii.character_id', $characterId)
            ->where('ii.status', 'owned')
            ->whereNull('ce.item_instance_id')
            ->orderByDesc('ii.item_level')
            ->orderByDesc('ii.id')
            ->get();
        $affixes = $this->affixesFor($rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $rows->map(fn ($row) => $this->itemView($row, $affixes))->all();
    }

    /**
     * Odds and gold for combining two pieces. The page displays this; it does not roll.
     *
     * @return array{ok: bool, reason: ?string, gold: int, chances: ?array, slot: ?string, left_id: int, right_id: int}
     */
    public function forgeQuote(int $accountId, int $leftId, int $rightId): array
    {
        $char = $this->character($accountId);
        $left = $this->forgePiece((int) $char['id'], $leftId);
        $right = $this->forgePiece((int) $char['id'], $rightId);
        if ($left === null || $right === null) {
            return [
                'ok' => false,
                'reason' => 'Those pieces are not both in your pack.',
                'gold' => 0,
                'chances' => null,
                'slot' => null,
                'left_id' => $leftId,
                'right_id' => $rightId,
            ];
        }

        return Forge::quote($left, $right, (int) $char['gold'], (int) $char['stat_luk'], Balance::get());
    }

    /** @return array{id: int, name: string, slot: string, rarity: string, item_level: int, equipped: bool}|null */
    private function forgePiece(int $characterId, int $itemId): ?array
    {
        $row = DB::table('item_instances as ii')
            ->join('item_templates as it', 'it.id', '=', 'ii.template_id')
            ->leftJoin('character_equipment as ce', 'ce.item_instance_id', '=', 'ii.id')
            ->where('ii.id', $itemId)
            ->where('ii.character_id', $characterId)
            ->where('ii.status', 'owned')
            ->first(['ii.id', 'ii.display_name', 'ii.rarity', 'ii.item_level', 'it.slot as template_slot', 'ce.item_instance_id as equipped_id']);
        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'name' => $row->display_name,
            'slot' => $row->template_slot,
            'rarity' => $row->rarity,
            'item_level' => (int) $row->item_level,
            'equipped' => $row->equipped_id !== null,
        ];
    }

    public function consumables(int $characterId): array
    {
        return DB::table('character_consumables as cc')
            ->join('consumable_templates as ct', 'ct.id', '=', 'cc.consumable_id')
            ->where('cc.character_id', $characterId)
            ->where('cc.quantity', '>', 0)
            ->orderBy('ct.name')
            ->get(['cc.consumable_id as id', 'cc.quantity', 'cc.belt_qty', 'ct.code', 'ct.name', 'ct.effect_type',
                'ct.effect_value', 'ct.use_trigger', 'ct.req_level', 'ct.sell_price', 'ct.description'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'quantity' => (int) $r->quantity,
                'belt_qty' => (int) $r->belt_qty,
                'effect_type' => $r->effect_type,
                'effect_value' => (float) $r->effect_value,
                'use_trigger' => $r->use_trigger,
                'req_level' => (int) $r->req_level,
                'sell_price' => (int) $r->sell_price,
                'description' => $r->description,
            ])->all();
    }

    /** Buffs the belt will apply at the start of the next trip. */
    private function beltBuffs(array $consumables): array
    {
        $buffs = [];
        foreach ($consumables as $c) {
            $kind = self::BUFF_EFFECTS[$c['effect_type']] ?? null;
            if ($kind && $c['use_trigger'] === 'trip_start' && $c['belt_qty'] > 0 && ! in_array($kind, $buffs, true)) {
                $buffs[] = $kind;
            }
        }

        return $buffs;
    }

    private function place(int $id): ?array
    {
        $row = DB::table('places')->where('id', $id)->first();

        return $row ? (array) $row : null;
    }

    public function pendingOrder(int $characterId): ?array
    {
        $row = DB::table('trip_orders as o')->join('places as p', 'p.id', '=', 'o.place_id')
            ->where('o.character_id', $characterId)->where('o.status', 'pending')
            ->first(['o.id', 'o.place_id', 'o.submitted_at', 'p.name', 'p.code', 'p.place_type']);

        return $row ? [
            'id' => (int) $row->id,
            'place_id' => (int) $row->place_id,
            'name' => $row->name,
            'code' => $row->code,
            'place_type' => $row->place_type,
            'submitted_at' => self::utc($row->submitted_at),
        ] : null;
    }

    /** What the character will do on the next run if nothing changes. */
    private function nextDestination(array $char, ?array $order): array
    {
        if ((int) $char['injury_severity'] >= 2) {
            return ['label' => 'Forced rest in Lanternford', 'kind' => 'blocked'];
        }
        if (! empty($char['auto_mode'])) {
            if (($char['auto_place'] ?? 'profile') === 'best') {
                return ['label' => 'Best place you can clear', 'kind' => 'auto_best'];
            }

            return ['label' => 'Chosen when the run starts', 'kind' => 'auto'];
        }
        if ($order) {
            return ['label' => $order['name'], 'kind' => 'order'];
        }
        if ($char['auto_repeat'] && $char['last_place_id']) {
            $place = $this->place((int) $char['last_place_id']);
            if ($place) {
                return ['label' => $place['name'], 'kind' => $place['place_type'] === 'rest' ? 'rest' : 'repeat'];
            }
        }

        return ['label' => 'Rest in Lanternford', 'kind' => 'rest'];
    }

    /** Authored cap, or the highest recommended level once generated regions go past it. */
    private function levelCap(array $balance): int
    {
        if ($this->contentCeiling === null) {
            $this->contentCeiling = (int) DB::table('places')->max('rec_max_level');
        }

        return Progression::levelCap($balance, $this->contentCeiling);
    }

    // -------------------------------------------------------------- shared

    public function heroSummary(int $accountId): ?array
    {
        $row = DB::table('characters')->where('account_id', $accountId)->first();
        if (! $row) {
            return null;
        }
        $char = (array) $row;
        $balance = Balance::get();
        $level = (int) $char['level'];
        $atCap = $level >= $this->levelCap($balance);
        $growth = $this->growthState((int) $char['id']);
        $premium = $this->premiumState($char);

        return [
            'id' => (int) $char['id'],
            'name' => $char['name'],
            'title' => $this->appearance($premium)['title'],
            'cash' => $premium['cash'],
            'level' => $level,
            'power' => $this->powerFor($level, $growth, $premium, $balance),
            'xp' => (int) $char['xp'],
            'xp_to_next' => $atCap ? null : Progression::xpToNext($level, $balance),
            'gold' => (int) $char['gold'],
            'unspent' => (int) $char['unspent_points'],
            'injury_severity' => (int) $char['injury_severity'],
            'injury_runs_left' => (int) $char['injury_runs_left'],
            'unread' => DB::table('mail')->where('character_id', $char['id'])->where('is_read', 0)->whereNull('deleted_at')->count(),
            'row_version' => (int) $char['row_version'],
        ];
    }

    public function runStatus(): array
    {
        $last = DB::table('simulation_runs')->orderByDesc('id')->first();
        $expedition = $last?->status === 'running' || DB::table('simulation_runs')->where('status', 'running')->exists();
        $arena = DB::table('arena_tournaments')->where('status', 'running')->exists();

        return [
            'running' => $expedition || $arena,
            'kind' => $arena ? 'arena' : ($expedition ? 'expedition' : null),
            'last' => $last ? [
                'id' => (int) $last->id,
                'status' => $last->status,
                'started_at' => self::utc($last->started_at),
                'finished_at' => self::utc($last->finished_at),
                'characters_done' => (int) $last->characters_done,
                'characters_total' => (int) $last->characters_total,
            ] : null,
        ];
    }

    // --------------------------------------------------------------- pages

    public function dashboard(int $accountId): array
    {
        $char = $this->character($accountId);
        $cid = (int) $char['id'];
        $order = $this->pendingOrder($cid);
        $consumables = $this->consumables($cid);
        $equipment = $this->equipment($cid);
        $growth = $this->growthState($cid);
        $bonus = $this->bonusFor($growth, $this->premiumState($char), Balance::get());
        $derived = Stats::derived($this->stats($char), (int) $char['level'], array_values($equipment), null, (int) $char['injury_severity'], [], $bonus);

        return [
            'next' => $this->nextDestination($char, $order),
            'order' => $order,
            'autoRepeat' => (bool) $char['auto_repeat'],
            'belt' => array_values(array_filter($consumables, fn ($c) => $c['belt_qty'] > 0)),
            'beltSlots' => (int) Balance::get()['combat']['belt_slots'],
            'derived' => array_intersect_key($derived, array_flip(['hp', 'attack', 'defense', 'mres', 'crit', 'dodge', 'speed', 'damage_type'])),
            'equipment' => $equipment,
            'recentTrips' => $this->recentTrips($cid, 6),
            'recentMail' => DB::table('mail')->where('character_id', $cid)->whereNull('deleted_at')
                ->orderByDesc('id')->limit(4)->get(['id', 'subject', 'is_read', 'created_at', 'payload'])
                ->map(fn ($m) => $this->mailRow($m))->all(),
            'counters' => [
                'trips' => (int) $char['trips_total'],
                'cleared' => (int) $char['trips_cleared'],
                'retreated' => (int) $char['trips_retreated'],
                'defeats' => (int) $char['defeats'],
                'kills' => (int) $char['kills'],
                'boss_kills' => (int) $char['boss_kills'],
            ],
        ];
    }

    public function recentTrips(int $characterId, int $limit): array
    {
        return DB::table('trips as t')->join('places as p', 'p.id', '=', 't.place_id')
            ->where('t.character_id', $characterId)->orderByDesc('t.id')->limit($limit)
            ->get(['t.id', 't.run_id', 't.outcome', 't.xp_gained', 't.gold_gained', 't.level_before', 't.level_after',
                't.max_hp', 't.hp_end', 't.encounters_won', 't.created_at', 'p.name as place_name', 'p.code as place_code', 'p.place_type'])
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'run_id' => (int) $t->run_id,
                'outcome' => $t->outcome,
                'xp' => (int) $t->xp_gained,
                'gold' => (int) $t->gold_gained,
                'level_before' => (int) $t->level_before,
                'level_after' => (int) $t->level_after,
                'hp_pct' => $t->max_hp > 0 ? (int) round(100 * $t->hp_end / $t->max_hp) : 0,
                'encounters_won' => (int) $t->encounters_won,
                'place' => $t->place_name,
                'place_code' => $t->place_code,
                'place_type' => $t->place_type,
                'created_at' => self::utc($t->created_at),
            ])->all();
    }

    public function characterPage(int $accountId): array
    {
        $balance = Balance::get();
        $char = $this->character($accountId);
        $cid = (int) $char['id'];
        $level = (int) $char['level'];
        $stats = $this->stats($char);
        $equipment = $this->equipment($cid);
        $gear = array_values($equipment);
        $consumables = $this->consumables($cid);
        $buffs = $this->beltBuffs($consumables);
        $injury = (int) $char['injury_severity'];
        $growth = $this->growthState($cid);
        $premium = $this->premiumState($char);
        $bonus = $this->bonusFor($growth, $premium, $balance);
        $derived = Stats::derived($stats, $level, $gear, $balance, $injury, $buffs, $bonus);
        $base = Stats::derived($stats, $level, $gear, $balance, $injury, [], $bonus);
        $plain = Stats::derived($stats, $level, $gear, $balance, $injury, [], []);

        $perPoint = [];
        foreach (Stats::PRIMARY as $stat) {
            $bumped = $stats;
            $bumped[$stat]++;
            $after = Stats::derived($bumped, $level, $gear, $balance, $injury, $buffs, $bonus);
            $delta = [];
            foreach (['hp', 'attack', 'defense', 'mres', 'crit', 'dodge', 'speed', 'regen', 'luck', 'gold_find'] as $key) {
                $change = round($after[$key] - $derived[$key], 4);
                if ($change != 0) {
                    $delta[$key] = $change;
                }
            }
            $perPoint[$stat] = $delta;
        }
        $bestPlace = $char['best_place_id'] ? $this->place((int) $char['best_place_id']) : null;

        return [
            'character' => [
                'id' => $cid,
                'name' => $char['name'],
                'level' => $level,
                'xp' => (int) $char['xp'],
                'total_xp' => (int) $char['total_xp'],
                'xp_to_next' => $level >= $this->levelCap($balance) ? null : Progression::xpToNext($level, $balance),
                'gold' => (int) $char['gold'],
                'stats' => $stats,
                'unspent' => (int) $char['unspent_points'],
                'retreat_threshold_pct' => (int) $char['retreat_threshold_pct'],
                'potion_threshold_pct' => (int) $char['potion_threshold_pct'],
                'auto_repeat' => (bool) $char['auto_repeat'],
                'auto_mode' => (bool) $char['auto_mode'],
                'auto_place' => ($char['auto_place'] ?? 'profile') === 'best' ? 'best' : 'profile',
                'auto_profile' => $char['auto_profile_id']
                    ? DB::table('bot_profiles')->where('id', $char['auto_profile_id'])->value('code')
                    : null,
                'injury_severity' => $injury,
                'injury_runs_left' => (int) $char['injury_runs_left'],
                'row_version' => (int) $char['row_version'],
                'best_place' => $bestPlace['name'] ?? null,
                'created_at' => self::utc($char['created_at']),
            ],
            'derived' => $derived,
            'baseDerived' => $base,
            'buffs' => $buffs,
            'gear' => $gear ? array_intersect_key($equipment, array_flip(Rules::EQUIP_SLOTS)) : (object) [],
            'perPoint' => $perPoint,
            'respecCost' => ShopMath::respecCost($level, $balance),
            'pointPool' => Progression::pointPool($level, $balance),
            'baseStat' => (int) $balance['character']['base_stat'],
            'caps' => array_intersect_key($balance['derived'], array_flip(['crit_cap', 'dodge_cap', 'regen_cap', 'luck_cap', 'lifesteal_cap', 'penetration_cap', 'dmg_reduction_cap', 'xp_bonus_cap'])),
            'power' => $this->powerFor($level, $growth, $premium, $balance),
            'powerParts' => [
                'level' => (int) $balance['growth']['power_per_level'] * $level,
                'growth' => Growth::powerLevel(0, $growth['ranks'], $growth['horcrux']['tier'], $balance),
                'premium' => Premium::power($premium['gear']),
            ],
            'plainDerived' => $plain,
            'bonusSources' => [
                ['source' => 'Skills', 'bonus' => (object) array_filter(Growth::bonuses($growth['ranks'], 0, $balance))],
                ['source' => 'Horcrux', 'bonus' => (object) array_filter(Growth::bonuses([], $growth['horcrux']['tier'], $balance))],
                ['source' => 'Premium', 'bonus' => (object) array_filter(Premium::bonuses($premium['gear']))],
            ],
            'appearance' => $this->appearance($premium),
            'cash' => $premium['cash'],
            'skills' => $growth['skills'],
            'horcrux' => $growth['horcrux'],
            'materials' => $growth['materials'],
            'autoProfiles' => DB::table('bot_profiles')->orderBy('id')->get(['code', 'name', 'archetype', 'description'])
                ->map(fn ($row) => [
                    'code' => $row->code,
                    'name' => $row->name,
                    'archetype' => $row->archetype,
                    'description' => $row->description,
                ])->all(),
        ] + $this->inventoryBlock($char, $balance, $bonus);
    }

    /** Public sheet for any adventurer. Inventory, materials, belt counts, and orders stay private. */
    public function publicSheet(int $characterId): array
    {
        $row = DB::table('characters')->where('id', $characterId)->first();
        if (! $row) {
            throw new NotFoundHttpException('No such adventurer.');
        }
        $balance = Balance::get();
        $char = (array) $row;
        $level = (int) $char['level'];
        $stats = $this->stats($char);
        $equipment = $this->equipment($characterId);
        $gear = array_values($equipment);
        $buffs = $this->beltBuffs($this->consumables($characterId));
        $injury = (int) $char['injury_severity'];
        $growth = $this->growthState($characterId);
        $premium = $this->premiumState($char);
        $bonus = $this->bonusFor($growth, $premium, $balance);
        $derived = Stats::derived($stats, $level, $gear, $balance, $injury, $buffs, $bonus);
        $base = Stats::derived($stats, $level, $gear, $balance, $injury, [], $bonus);
        $bestPlace = $char['best_place_id'] ? $this->place((int) $char['best_place_id']) : null;
        $skills = array_map(fn (array $skill) => [
            'code' => $skill['code'],
            'name' => $skill['name'],
            'glyph' => $skill['glyph'],
            'blurb' => $skill['blurb'],
            'rank' => $skill['rank'],
            'max_rank' => $skill['max_rank'],
            'lines' => $skill['lines'],
        ], $growth['skills']);

        return [
            'character' => [
                'id' => $characterId,
                'name' => $char['name'],
                'is_bot' => (bool) $char['is_bot'],
                'level' => $level,
                'xp' => (int) $char['xp'],
                'total_xp' => (int) $char['total_xp'],
                'xp_to_next' => $level >= $this->levelCap($balance) ? null : Progression::xpToNext($level, $balance),
                'gold' => (int) $char['gold'],
                'stats' => $stats,
                'injury_severity' => $injury,
                'injury_runs_left' => (int) $char['injury_runs_left'],
                'best_place' => $bestPlace['name'] ?? null,
                'created_at' => self::utc($char['created_at']),
                'kills' => (int) $char['kills'],
                'boss_kills' => (int) $char['boss_kills'],
                'trips_cleared' => (int) $char['trips_cleared'],
                'defeats' => (int) $char['defeats'],
            ],
            'derived' => $derived,
            'baseDerived' => $base,
            'buffs' => $buffs,
            'power' => $this->powerFor($level, $growth, $premium, $balance),
            'appearance' => $this->appearance($premium),
            'skills' => $skills,
            'horcrux' => $growth['horcrux'],
            'equipment' => $equipment === [] ? (object) [] : $equipment,
        ];
    }

    /** @return array{ranks: array<string, int>, skills: list<array>, horcrux: array, materials: list<array>} */
    private function growthState(int $characterId): array
    {
        $balance = Balance::get();
        $char = DB::table('characters')->where('id', $characterId)->first(['materials', 'skills', 'horcrux_tier', 'horcrux_progress', 'gold']);
        $owned = self::json($char->materials ?? null) ?: [];
        $stacks = DB::table('material_templates')
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'code', 'name', 'kind', 'glyph', 'description'])
            ->map(function ($row) use ($owned) {
                $row->qty = (int) ($owned[$row->code] ?? 0);

                return $row;
            });
        $places = [];
        foreach (DB::table('place_materials as pm')
            ->join('places as p', 'p.id', '=', 'pm.place_id')
            ->join('material_templates as m', 'm.id', '=', 'pm.material_id')
            ->orderBy('p.min_level')->orderBy('p.name')
            ->get(['m.code', 'p.name']) as $row) {
            $places[$row->code][] = $row->name;
        }
        $materials = $stacks->map(fn ($row) => [
            'code' => $row->code,
            'name' => $row->name,
            'kind' => $row->kind,
            'glyph' => $row->glyph,
            'description' => $row->description,
            'qty' => (int) $row->qty,
            'places' => $places[$row->code] ?? [],
        ])->all();
        $qtyList = array_map(fn ($row) => ['code' => $row['code'], 'kind' => $row['kind'], 'qty' => $row['qty']], $materials);
        $ranks = [];
        foreach (self::json($char->skills ?? null) ?: [] as $code => $rank) {
            $ranks[$code] = (int) $rank;
        }
        $gold = (int) ($char->gold ?? 0);
        $skills = [];
        foreach ($balance['growth']['skills'] as $skill) {
            $rank = (int) ($ranks[$skill['code']] ?? 0);
            $cost = Growth::rankCost($rank + 1, $balance);
            $have = $cost ? Growth::materialQty($qtyList, $cost['material']) : 0;
            $needName = null;
            if ($cost) {
                $needName = $cost['material'] === 'essence'
                    ? 'any place essence'
                    : (collect($materials)->firstWhere('code', $cost['material'])['name'] ?? $cost['material']);
            }
            $lines = [];
            foreach (['attack' => 'Attack', 'defense' => 'Defense', 'mres' => 'Magic Res', 'hp' => 'HP', 'crit_pct' => 'Crit', 'magic_find_pct' => 'Magic Find'] as $key => $label) {
                if (isset($skill[$key])) {
                    $lines[] = '+'.($skill[$key] * max(1, $rank ?: 1))." {$label}".($rank === 0 ? ' at rank 1' : '');
                }
            }
            $skills[] = [
                'code' => $skill['code'],
                'name' => $skill['name'],
                'glyph' => $skill['glyph'],
                'blurb' => $skill['blurb'],
                'rank' => $rank,
                'max_rank' => (int) $balance['growth']['max_rank'],
                'lines' => $lines,
                'next' => $cost ? [
                    'rank' => (int) $cost['rank'],
                    'gold' => (int) $cost['gold'],
                    'qty' => (int) $cost['qty'],
                    'have' => $have,
                    'material' => $needName,
                    'ready' => $gold >= (int) $cost['gold'] && $have >= (int) $cost['qty'],
                ] : null,
            ];
        }
        $progress = (int) ($char->horcrux_progress ?? 0);
        $tier = (int) ($char->horcrux_tier ?? 0);
        $thresholds = $balance['growth']['horcrux']['thresholds'];
        $maxTier = count($thresholds) - 1;
        $nextAt = $tier < $maxTier ? (int) $thresholds[$tier + 1] : null;
        $per = $balance['growth']['horcrux']['per_tier'];

        return [
            'ranks' => $ranks,
            'skills' => $skills,
            'horcrux' => [
                'name' => $balance['growth']['horcrux']['name'],
                'tier' => $tier,
                'max_tier' => $maxTier,
                'progress' => $progress,
                'next_at' => $nextAt,
                'per_tier' => $per,
            ],
            'materials' => $materials,
        ];
    }

    /** Inventory, belt, and chests for the hero page. */
    private function inventoryBlock(array $char, array $balance, array $bonus): array
    {
        $cid = (int) $char['id'];
        $level = (int) $char['level'];
        $stats = $this->stats($char);
        $equipment = $this->equipment($cid);
        $inventory = $this->inventory($cid);
        $current = Stats::derived($stats, $level, array_values($equipment), $balance, (int) $char['injury_severity'], [], $bonus);
        $keys = ['hp', 'attack', 'defense', 'mres', 'crit', 'dodge', 'speed', 'luck'];
        foreach ($inventory as &$item) {
            $slot = $this->targetSlot($item, $equipment);
            $loadout = $equipment;
            unset($loadout[$slot]);
            [$ok, $note] = Rules::canEquip($item, $slot, $level, $loadout);
            $item['target_slot'] = $slot;
            $item['can_equip'] = $ok;
            $item['equip_note'] = $ok
                ? ($note === 'clears_offhand' && isset($equipment['offhand']) ? 'Two-handed: your off-hand goes back to the inventory.' : null)
                : Rules::equipMessage($note);
            $item['feed'] = Growth::feedValue($item, $balance);
            $trial = $loadout;
            $trial[$slot] = $item + ['equip_slot' => $slot];
            if ($slot === 'weapon' && $item['two_handed']) {
                unset($trial['offhand']);
            }
            $after = Stats::derived($stats, $level, array_values($trial), $balance, (int) $char['injury_severity'], [], $bonus);
            $delta = [];
            foreach ($keys as $key) {
                $change = round($after[$key] - $current[$key], 4);
                if ($change != 0) {
                    $delta[$key] = $change;
                }
            }
            $item['delta'] = $delta;
        }
        unset($item);

        return [
            'rowVersion' => (int) $char['row_version'],
            'injury' => (int) $char['injury_severity'],
            'equipment' => (object) $equipment,
            'inventory' => $inventory,
            'consumables' => $this->consumables($cid),
            'chests' => $this->sealedChests($cid),
            'capacity' => (int) $balance['loot']['inventory_capacity'],
            'beltSlots' => (int) $balance['combat']['belt_slots'],
        ];
    }

    public function packPage(int $accountId): array
    {
        $balance = Balance::get();
        $char = $this->character($accountId);
        $cid = (int) $char['id'];
        $level = (int) $char['level'];
        $stats = $this->stats($char);
        $equipment = $this->equipment($cid);
        $inventory = $this->inventory($cid);
        $current = Stats::derived($stats, $level, array_values($equipment), $balance);
        $keys = ['hp', 'attack', 'defense', 'mres', 'crit', 'dodge', 'speed', 'luck'];

        foreach ($inventory as &$item) {
            $slot = $this->targetSlot($item, $equipment);
            $loadout = $equipment;
            unset($loadout[$slot]);
            [$ok, $note] = Rules::canEquip($item, $slot, $level, $loadout);
            $item['target_slot'] = $slot;
            $item['can_equip'] = $ok;
            $item['equip_note'] = $ok
                ? ($note === 'clears_offhand' && isset($equipment['offhand']) ? 'Two-handed: your off-hand goes back to the pack.' : null)
                : Rules::equipMessage($note);
            $trial = $loadout;
            $trial[$slot] = $item + ['equip_slot' => $slot];
            if ($slot === 'weapon' && $item['two_handed']) {
                unset($trial['offhand']);
            }
            $after = Stats::derived($stats, $level, array_values($trial), $balance);
            $delta = [];
            foreach ($keys as $key) {
                $change = round($after[$key] - $current[$key], 4);
                if ($change != 0) {
                    $delta[$key] = $change;
                }
            }
            $item['delta'] = $delta;
        }
        unset($item);

        return [
            'rowVersion' => (int) $char['row_version'],
            'level' => $level,
            'gold' => (int) $char['gold'],
            'injury' => (int) $char['injury_severity'],
            'equipment' => (object) $equipment,
            'inventory' => $inventory,
            'consumables' => $this->consumables($cid),
            'chests' => $this->sealedChests($cid),
            'capacity' => (int) $balance['loot']['inventory_capacity'],
            'beltSlots' => (int) $balance['combat']['belt_slots'],
            'slots' => Rules::EQUIP_SLOTS,
            'derived' => array_intersect_key($current, array_flip([...$keys, 'damage_type'])),
        ];
    }

    private function targetSlot(array $item, array $equipment): string
    {
        if ($item['template_slot'] !== 'ring') {
            return $item['template_slot'];
        }
        if (! isset($equipment['ring1'])) {
            return 'ring1';
        }

        return isset($equipment['ring2']) ? 'ring1' : 'ring2';
    }

    public function marketPage(int $accountId): array
    {
        $balance = Balance::get();
        $char = $this->character($accountId);
        $cid = (int) $char['id'];
        $level = (int) $char['level'];
        $owned = [];
        foreach ($this->consumables($cid) as $c) {
            $owned[$c['id']] = $c['quantity'];
        }
        $listings = DB::table('shop_listings as sl')
            ->leftJoin('item_templates as it', 'it.id', '=', 'sl.item_template_id')
            ->leftJoin('consumable_templates as ct', 'ct.id', '=', 'sl.consumable_id')
            ->where('sl.is_active', 1)
            ->orderBy('sl.sort_order')->orderBy('sl.id')
            ->get(['sl.id', 'sl.kind', 'sl.price', 'sl.min_level', 'sl.consumable_id',
                'it.name as item_name', 'it.slot', 'it.item_type', 'it.scaling_stat', 'it.two_handed', 'it.base_power',
                'it.base_armor', 'it.base_mres', 'it.implicit_mods', 'it.description as item_desc',
                'ct.name as cons_name', 'ct.code as cons_code', 'ct.effect_type', 'ct.effect_value', 'ct.use_trigger',
                'ct.description as cons_desc'])
            ->map(fn ($l) => [
                'id' => (int) $l->id,
                'kind' => $l->kind,
                'price' => (int) $l->price,
                'min_level' => (int) $l->min_level,
                'name' => $l->kind === 'gear' ? $l->item_name : $l->cons_name,
                'description' => $l->kind === 'gear' ? $l->item_desc : $l->cons_desc,
                'slot' => $l->slot,
                'item_type' => $l->item_type,
                'scaling_stat' => $l->scaling_stat,
                'two_handed' => (bool) $l->two_handed,
                'power' => (int) $l->base_power,
                'armor' => (int) $l->base_armor,
                'mres' => (int) $l->base_mres,
                'implicit' => self::json($l->implicit_mods) ?: [],
                'code' => $l->cons_code,
                'effect_type' => $l->effect_type,
                'effect_value' => $l->effect_value === null ? null : (float) $l->effect_value,
                'use_trigger' => $l->use_trigger,
                'owned' => $l->consumable_id ? ($owned[(int) $l->consumable_id] ?? 0) : null,
                'locked' => $level < (int) $l->min_level,
            ])->all();
        $packCount = DB::table('v_inventory')->where('character_id', $cid)->count();

        return [
            'rowVersion' => (int) $char['row_version'],
            'gold' => (int) $char['gold'],
            'level' => $level,
            'listings' => $listings,
            'inventory' => $this->inventory($cid),
            'consumables' => $this->consumables($cid),
            'packCount' => $packCount,
            'capacity' => (int) $balance['loot']['inventory_capacity'],
            'maxStack' => (int) $balance['shop']['max_stack'],
            'ledger' => DB::table('shop_transactions as st')
                ->leftJoin('item_instances as ii', 'ii.id', '=', 'st.item_instance_id')
                ->leftJoin('consumable_templates as ct', 'ct.id', '=', 'st.consumable_id')
                ->leftJoin('premium_items as pi', 'pi.id', '=', 'st.premium_item_id')
                ->where('st.character_id', $cid)->orderByDesc('st.id')->limit(12)
                ->get(['st.id', 'st.actor', 'st.action', 'st.quantity', 'st.gold_delta', 'st.gold_after', 'st.created_at',
                    'ii.display_name', 'ct.name as cons_name', 'pi.name as premium_name'])
                ->map(fn ($t) => [
                    'id' => (int) $t->id,
                    'actor' => $t->actor,
                    'action' => $t->action,
                    'quantity' => (int) $t->quantity,
                    'gold_delta' => (int) $t->gold_delta,
                    'gold_after' => (int) $t->gold_after,
                    'what' => $t->display_name ?? $t->cons_name ?? $t->premium_name ?? '—',
                    'created_at' => self::utc($t->created_at),
                ])->all(),
        ];
    }

    public const PREMIUM_DELTA_KEYS = ['hp', 'attack', 'defense', 'mres', 'crit', 'crit_mult', 'dodge', 'speed', 'luck', 'gold_find', 'lifesteal', 'penetration', 'dmg_reduction', 'xp_mult'];

    public function premiumPage(int $accountId): array
    {
        $balance = Balance::get();
        $char = $this->character($accountId);
        $cid = (int) $char['id'];
        $level = (int) $char['level'];
        $stats = $this->stats($char);
        $gear = array_values($this->equipment($cid));
        $growth = $this->growthState($cid);
        $premium = $this->premiumState($char);
        $catalog = $this->premiumCatalog();
        $current = Stats::derived($stats, $level, $gear, $balance, 0, [], $this->bonusFor($growth, $premium, $balance));

        $items = [];
        foreach ($catalog as $item) {
            if (! $item['is_active']) {
                continue;
            }
            $owned = in_array($item['code'], $premium['owned'], true);
            $worn = ($premium['equipped'][$item['category']] ?? null) === $item['code'];
            $delta = [];
            if ($item['category'] !== Premium::BUNDLE && ! $worn) {
                $trial = $premium;
                Premium::wear($trial, $item['code'], $catalog);
                $after = Stats::derived($stats, $level, $gear, $balance, 0, [], $this->bonusFor($growth, $trial, $balance));
                foreach (self::PREMIUM_DELTA_KEYS as $key) {
                    $change = round($after[$key] - $current[$key], 4);
                    if ($change != 0) {
                        $delta[$key] = $change;
                    }
                }
            }
            [$canBuy, $why] = Premium::canBuy($item, $premium);
            $items[] = self::premiumView($item) + [
                'owned' => $owned,
                'worn' => $worn,
                'can_buy' => $canBuy,
                'buy_note' => $owned && $item['category'] !== Premium::BUNDLE ? null : $why,
                'delta' => (object) $delta,
            ];
        }

        return [
            'rowVersion' => (int) $char['row_version'],
            'cash' => $premium['cash'],
            'cashLabel' => Premium::money($premium['cash']),
            'startingCash' => Premium::money((int) $balance['premium']['starting_cash_cents']),
            'gold' => (int) $char['gold'],
            'power' => $this->powerFor($level, $growth, $premium, $balance),
            'premiumPower' => Premium::power($premium['gear']),
            'categories' => [...$balance['premium']['categories'], Premium::BUNDLE],
            'items' => $items,
            'appearance' => $this->appearance($premium),
            'bonus' => (object) array_filter(Premium::bonuses($premium['gear'])),
            'name' => $char['name'],
            'level' => $level,
            'ledger' => DB::table('shop_transactions as st')
                ->join('premium_items as pi', 'pi.id', '=', 'st.premium_item_id')
                ->where('st.character_id', $cid)->where('st.action', 'premium')
                ->orderByDesc('st.id')->limit(12)
                ->get(['st.id', 'st.actor', 'st.cash_delta', 'st.cash_after', 'st.gold_delta', 'st.created_at', 'pi.name', 'pi.glyph'])
                ->map(fn ($t) => [
                    'id' => (int) $t->id,
                    'actor' => $t->actor,
                    'what' => $t->name,
                    'glyph' => $t->glyph,
                    'cash' => Premium::money(abs((int) $t->cash_delta)),
                    'cash_after' => Premium::money((int) $t->cash_after),
                    'gold_delta' => (int) $t->gold_delta,
                    'created_at' => self::utc($t->created_at),
                ])->all(),
        ];
    }

    public function mapPage(int $accountId): array
    {
        $char = $this->character($accountId);
        $cid = (int) $char['id'];
        $level = (int) $char['level'];
        $history = [];
        foreach (DB::table('trips')->where('character_id', $cid)->groupBy('place_id')
            ->get(['place_id', DB::raw("SUM(outcome = 'cleared') AS clears"), DB::raw("SUM(outcome = 'defeated') AS defeats"), DB::raw('COUNT(*) AS trips')]) as $row) {
            $history[(int) $row->place_id] = ['trips' => (int) $row->trips, 'clears' => (int) $row->clears, 'defeats' => (int) $row->defeats];
        }
        $monsters = [];
        foreach (DB::table('place_monsters as pm')->join('monsters as m', 'm.id', '=', 'pm.monster_id')
            ->orderBy('m.level')->get(['pm.place_id', 'm.name', 'm.level', 'm.damage_type']) as $m) {
            $monsters[(int) $m->place_id][] = ['name' => $m->name, 'level' => (int) $m->level, 'damage_type' => $m->damage_type];
        }
        $bosses = DB::table('monsters')->where('is_boss', 1)->get(['id', 'name', 'level', 'damage_type'])->keyBy('id');
        $order = $this->pendingOrder($cid);

        $regions = DB::table('regions')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'blurb' => $r->blurb,
            ])->all();

        $places = DB::table('places as p')
            ->join('regions as r', 'r.id', '=', 'p.region_id')
            ->where('p.is_active', 1)
            ->orderBy('p.min_level')->orderBy('p.id')
            ->get(['p.*', 'r.code as region_code'])
            ->map(function ($p) use ($level, $history, $monsters, $bosses) {
                $place = (array) $p;
                [$ok, $reason] = Rules::canEnter($level, $place);
                $boss = $place['boss_monster_id'] ? $bosses->get($place['boss_monster_id']) : null;
                $warning = null;
                if ($ok && $place['place_type'] !== 'rest') {
                    if ($level < (int) $place['rec_min_level']) {
                        $warning = "Recommended Lv {$place['rec_min_level']}–{$place['rec_max_level']}: expect heavy losses.";
                    } elseif ($level > (int) $place['rec_max_level']) {
                        $warning = 'You out-level this place: low XP here.';
                    } elseif ($boss && $level < (int) $boss->level - 4) {
                        $warning = "The boss is Lv {$boss->level}. Clearing this at Lv {$level} is a hard fight unless your gear is ahead.";
                    }
                }
                $denizens = $monsters[(int) $place['id']] ?? [];
                if ($boss && ! in_array($boss->name, array_column($denizens, 'name'), true)) {
                    $denizens[] = ['name' => $boss->name, 'level' => (int) $boss->level, 'damage_type' => $boss->damage_type];
                }

                return [
                    'id' => (int) $place['id'],
                    'code' => $place['code'],
                    'name' => $place['name'],
                    'place_type' => $place['place_type'],
                    'description' => $place['description'],
                    'min_level' => (int) $place['min_level'],
                    'max_level' => $place['max_level'] === null ? null : (int) $place['max_level'],
                    'rec_min_level' => (int) $place['rec_min_level'],
                    'rec_max_level' => (int) $place['rec_max_level'],
                    'rec_level' => (int) $place['rec_level'],
                    'encounter_min' => (int) $place['encounter_min'],
                    'encounter_max' => (int) $place['encounter_max'],
                    'boss' => $boss ? ['name' => $boss->name, 'level' => (int) $boss->level, 'chance' => (float) $place['boss_chance']] : null,
                    'gold_mult' => (float) $place['gold_mult'],
                    'rarity_bonus' => (float) $place['rarity_bonus'],
                    'region_code' => $place['region_code'],
                    'map_x' => (int) $place['map_x'],
                    'map_y' => (int) $place['map_y'],
                    'open' => $ok,
                    'locked_reason' => $ok ? null : Rules::rejectMessage($level, $place, $reason),
                    'warning' => $warning,
                    'monsters' => $denizens,
                    'history' => $history[(int) $place['id']] ?? ['trips' => 0, 'clears' => 0, 'defeats' => 0],
                ];
            })->all();

        return [
            'level' => $level,
            'regions' => $regions,
            'places' => $places,
            'order' => $order,
            'next' => $this->nextDestination($char, $order),
            'lastPlaceId' => $char['last_place_id'] === null ? null : (int) $char['last_place_id'],
            'autoRepeat' => (bool) $char['auto_repeat'],
            'injury' => (int) $char['injury_severity'],
        ];
    }

    private function mailRow(object $m): array
    {
        $payload = self::json($m->payload ?? null) ?: [];

        return [
            'id' => (int) $m->id,
            'subject' => $m->subject,
            'is_read' => (bool) $m->is_read,
            'created_at' => self::utc($m->created_at),
            'outcome' => $payload['outcome'] ?? null,
            'xp' => $payload['xp'] ?? null,
            'gold' => $payload['gold'] ?? null,
            'items' => count($payload['items'] ?? []),
            'level_up' => isset($payload['level_before'], $payload['level_after']) && $payload['level_after'] !== $payload['level_before'],
            'trip_id' => $payload['trip_id'] ?? null,
        ];
    }

    public function mailIndex(int $accountId, int $page, ?string $outcome, bool $unread): array
    {
        $char = $this->character($accountId);
        $query = DB::table('mail')->where('character_id', $char['id'])->whereNull('deleted_at');
        if ($unread) {
            $query->where('is_read', 0);
        }
        if ($outcome) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.outcome')) = ?", [$outcome]);
        }
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::MAIL_PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $query->orderByDesc('id')->offset(($page - 1) * self::MAIL_PER_PAGE)->limit(self::MAIL_PER_PAGE)
            ->get(['id', 'subject', 'is_read', 'created_at', 'payload']);

        return [
            'mail' => $rows->map(fn ($m) => $this->mailRow($m))->all(),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'filters' => ['outcome' => $outcome, 'unread' => $unread],
        ];
    }

    public function mailOne(int $accountId, int $mailId): array
    {
        $m = DB::table('mail as m')->join('characters as c', 'c.id', '=', 'm.character_id')
            ->where('m.id', $mailId)->where('c.account_id', $accountId)->whereNull('m.deleted_at')
            ->first(['m.*']);
        if (! $m) {
            throw new NotFoundHttpException;
        }
        $row = $this->mailRow($m);
        $newer = DB::table('mail')->where('character_id', $m->character_id)->whereNull('deleted_at')->where('id', '>', $m->id)->min('id');
        $older = DB::table('mail')->where('character_id', $m->character_id)->whereNull('deleted_at')->where('id', '<', $m->id)->max('id');

        return $row + [
            'body' => $m->body_text,
            'payload' => self::json($m->payload),
            'trip_id' => $m->trip_id === null ? null : (int) $m->trip_id,
            'run_id' => $m->run_id === null ? null : (int) $m->run_id,
            'newer_id' => $newer === null ? null : (int) $newer,
            'older_id' => $older === null ? null : (int) $older,
        ];
    }

    /** @return list<array{id: int, place: string, rolls: int}> */
    public function sealedChests(int $characterId): array
    {
        return DB::table('loot_boxes as b')
            ->join('places as p', 'p.id', '=', 'b.place_id')
            ->where('b.character_id', $characterId)
            ->where('b.status', 'sealed')
            ->orderBy('b.id')
            ->get(['b.id', 'b.rolls', 'p.name as place'])
            ->map(fn ($b) => [
                'id' => (int) $b->id,
                'place' => $b->place,
                'rolls' => (int) $b->rolls,
            ])->all();
    }

    /** @return array{status: string, rolls: int}|null */
    private function tripChest(int $tripId): ?array
    {
        $box = DB::table('loot_boxes')->where('trip_id', $tripId)->first(['status', 'rolls']);
        if (! $box) {
            return null;
        }

        return ['status' => $box->status, 'rolls' => (int) $box->rolls];
    }

    public function trip(int $accountId, int $tripId): array
    {
        $t = DB::table('trips as t')->join('characters as c', 'c.id', '=', 't.character_id')
            ->join('places as p', 'p.id', '=', 't.place_id')
            ->where('t.id', $tripId)->where('c.account_id', $accountId)
            ->first(['t.*', 'c.name as hero', 'p.name as place_name', 'p.code as place_code', 'p.place_type',
                'p.rec_min_level', 'p.rec_max_level']);
        if (! $t) {
            throw new NotFoundHttpException;
        }
        $loot = DB::table('trip_loot as tl')->join('item_instances as ii', 'ii.id', '=', 'tl.item_instance_id')
            ->where('tl.trip_id', $tripId)
            ->get(['tl.kept', 'tl.encounter_seq', 'ii.id', 'ii.display_name', 'ii.rarity', 'ii.item_level', 'ii.status', 'ii.source'])
            ->map(fn ($l) => [
                'id' => (int) $l->id,
                'name' => $l->display_name,
                'rarity' => $l->rarity,
                'item_level' => (int) $l->item_level,
                'kept' => (bool) $l->kept,
                'status' => $l->status,
                'source' => $l->source,
                'encounter_seq' => $l->encounter_seq === null ? null : (int) $l->encounter_seq,
            ])->all();
        $encounters = DB::table('trip_encounters')->where('trip_id', $tripId)->orderBy('seq')->get()
            ->map(function ($e) {
                $log = self::json($e->log) ?: [];
                $monsters = self::json($e->monsters) ?: [];

                return [
                    'seq' => (int) $e->seq,
                    'is_boss' => (bool) $e->is_boss,
                    'result' => $e->result,
                    'rounds' => (int) $e->rounds,
                    'damage_dealt' => (int) $e->damage_dealt,
                    'damage_taken' => (int) $e->damage_taken,
                    'hp_before' => (int) $e->hp_before,
                    'hp_after' => (int) $e->hp_after,
                    'xp' => (int) $e->xp,
                    'gold' => (int) $e->gold,
                    'units' => $this->encounterUnits($monsters, $log, (bool) $e->is_boss),
                    'log' => $log,
                ];
            })->all();
        $snapshot = self::json($t->snapshot) ?: [];
        $mailId = DB::table('mail')->where('trip_id', $tripId)->whereNull('deleted_at')->value('id');

        return [
            'id' => (int) $t->id,
            'run_id' => (int) $t->run_id,
            'hero' => $t->hero,
            'place' => ['name' => $t->place_name, 'code' => $t->place_code, 'type' => $t->place_type,
                'rec' => [(int) $t->rec_min_level, (int) $t->rec_max_level]],
            'outcome' => $t->outcome,
            'max_hp' => (int) $t->max_hp,
            'hp_end' => (int) $t->hp_end,
            'xp' => (int) $t->xp_gained,
            'gold' => (int) $t->gold_gained,
            'level_before' => (int) $t->level_before,
            'level_after' => (int) $t->level_after,
            'encounters_planned' => (int) $t->encounters_planned,
            'encounters_won' => (int) $t->encounters_won,
            'boss_encountered' => (bool) $t->boss_encountered,
            'boss_killed' => (bool) $t->boss_killed,
            'created_at' => self::utc($t->created_at),
            'derived' => $snapshot['derived'] ?? null,
            'buffs' => $snapshot['buffs'] ?? [],
            'consumables_used' => self::json($t->consumables_used) ?: [],
            'encounters' => $encounters,
            'loot' => $loot,
            'chest' => $this->tripChest($tripId),
            'mail_id' => $mailId === null ? null : (int) $mailId,
        ];
    }

    /**
     * Monster units as the battle log names them. Runs before M6 (Python) named every monster "Name#i";
     * later runs use "Name" for a lone monster and "Name #i" in groups.
     */
    private function encounterUnits(array $monsters, array $log, bool $isBoss): array
    {
        $names = [];
        foreach ($log as $event) {
            foreach (['a', 't'] as $key) {
                if (isset($event[$key])) {
                    $names[$event[$key]] = true;
                }
            }
        }
        $count = count($monsters);
        $units = [];
        foreach ($monsters as $i => $m) {
            $n = $i + 1;
            $modern = $count > 1 ? "{$m['name']} #{$n}" : $m['name'];
            $legacy = "{$m['name']}#{$n}";
            $units[] = [
                'name' => isset($names[$legacy]) && ! isset($names[$modern]) ? $legacy : $modern,
                'label' => $count > 1 ? "{$m['name']} {$n}" : $m['name'],
                'base' => $m['name'],
                'code' => $m['code'] ?? null,
                'level' => $m['level'] ?? null,
                'hp' => (int) ($m['hp'] ?? 0),
                'is_boss' => (bool) ($m['is_boss'] ?? $isBoss),
            ];
        }

        return $units;
    }

    public function leaderboard(string $sort): array
    {
        $orders = [
            'level' => [['level', 'desc'], ['total_xp', 'desc']],
            'power' => null,
            'gold' => [['gold', 'desc']],
            'bosses' => [['boss_kills', 'desc']],
            'clears' => [['trips_cleared', 'desc']],
            'defeats' => [['defeats', 'desc']],
            'kills' => [['kills', 'desc']],
        ];
        $sort = array_key_exists($sort, $orders) ? $sort : 'level';
        $balance = Balance::get();
        $query = DB::table('v_leaderboard as v')
            ->join('characters as c', 'c.id', '=', 'v.id')
            ->select('v.*', 'c.skills', 'c.horcrux_tier', 'c.premium', 'c.premium_cash');
        foreach ($orders[$sort] ?? [['level', 'desc'], ['total_xp', 'desc']] as [$column, $dir]) {
            $query->orderBy("v.{$column}", $dir);
        }
        $rows = $query->orderBy('v.name')->get()->map(function ($r) use ($balance) {
            $ranks = [];
            foreach (self::json($r->skills ?? null) ?: [] as $code => $rank) {
                $ranks[$code] = (int) $rank;
            }
            $premium = $this->premiumState($r);
            $look = $this->appearance($premium);

            return [
                'id' => (int) $r->id,
                'name' => $r->name,
                'title' => $look['title'],
                'glyphs' => array_values(array_map(fn ($g) => $g['glyph'], array_filter($look['gear'], fn ($g) => $g['category'] !== 'title'))),
                'is_bot' => (bool) $r->is_bot,
                'level' => (int) $r->level,
                'power' => Growth::powerLevel((int) $r->level, $ranks, (int) $r->horcrux_tier, $balance, Premium::power($premium['gear'])),
                'total_xp' => (int) $r->total_xp,
                'gold' => (int) $r->gold,
                'kills' => (int) $r->kills,
                'boss_kills' => (int) $r->boss_kills,
                'trips_cleared' => (int) $r->trips_cleared,
                'defeats' => (int) $r->defeats,
                'best_place' => $r->best_place,
            ];
        })->all();
        if ($sort === 'power') {
            usort($rows, fn ($a, $b) => $b['power'] <=> $a['power'] ?: strcmp($a['name'], $b['name']));
        }

        return [
            'sort' => $sort,
            'rows' => $rows,
        ];
    }

    public function bots(): array
    {
        $bots = DB::table('characters as c')->join('bot_profiles as bp', 'bp.id', '=', 'c.bot_profile_id')
            ->where('c.is_bot', 1)->orderBy('c.id')
            ->get(['c.id', 'c.name', 'c.level', 'c.gold', 'c.kills', 'c.boss_kills', 'c.trips_cleared', 'c.defeats',
                'c.injury_severity', 'c.stat_str', 'c.stat_dex', 'c.stat_int', 'c.stat_vit', 'c.stat_luk',
                'c.premium', 'c.premium_cash', 'bp.archetype', 'bp.description', 'bp.name as profile_name', 'bp.preferred_scaling', 'bp.risk_offset',
                'bp.buff_preference']);
        $ids = $bots->pluck('id')->all();
        $decisions = DB::table('bot_decisions')->whereIn('character_id', $ids)->orderByDesc('id')->limit(count($ids) * 4)
            ->get(['character_id', 'run_id', 'decision', 'created_at'])->groupBy('character_id');
        $lastTrips = DB::table('trips as t')->join('places as p', 'p.id', '=', 't.place_id')
            ->whereIn('t.id', DB::table('trips')->whereIn('character_id', $ids)->groupBy('character_id')->select(DB::raw('MAX(id)')))
            ->get(['t.character_id', 't.outcome', 't.xp_gained', 'p.name as place'])->keyBy('character_id');

        return $bots->map(function ($b) use ($decisions, $lastTrips) {
            $last = $lastTrips->get($b->id);
            $premium = $this->premiumState($b);

            return [
                'id' => (int) $b->id,
                'name' => $b->name,
                'level' => (int) $b->level,
                'gold' => (int) $b->gold,
                'cash' => $premium['cash'],
                'appearance' => $this->appearance($premium),
                'kills' => (int) $b->kills,
                'boss_kills' => (int) $b->boss_kills,
                'trips_cleared' => (int) $b->trips_cleared,
                'defeats' => (int) $b->defeats,
                'injury' => (int) $b->injury_severity,
                'stats' => ['str' => (int) $b->stat_str, 'dex' => (int) $b->stat_dex, 'int' => (int) $b->stat_int, 'vit' => (int) $b->stat_vit, 'luk' => (int) $b->stat_luk],
                'archetype' => $b->archetype,
                'profile' => $b->profile_name,
                'description' => $b->description,
                'preferred_scaling' => $b->preferred_scaling,
                'risk_offset' => (int) $b->risk_offset,
                'buff_preference' => $b->buff_preference,
                'last_trip' => $last ? ['outcome' => $last->outcome, 'xp' => (int) $last->xp_gained, 'place' => $last->place] : null,
                'decisions' => ($decisions->get($b->id) ?? collect())->take(3)->map(fn ($d) => [
                    'run_id' => (int) $d->run_id,
                    'created_at' => self::utc($d->created_at),
                    'decision' => self::json($d->decision),
                ])->values()->all(),
            ];
        })->all();
    }

    public function runs(): array
    {
        $runs = DB::table('simulation_runs')->orderByDesc('id')->limit(40)->get();
        $counts = [];
        foreach (DB::table('trips')->whereIn('run_id', $runs->pluck('id'))->groupBy('run_id', 'outcome')
            ->get(['run_id', 'outcome', DB::raw('COUNT(*) AS n')]) as $row) {
            $counts[(int) $row->run_id][$row->outcome] = (int) $row->n;
        }

        return $runs->map(fn ($r) => [
            'id' => (int) $r->id,
            'status' => $r->status,
            'seed' => (string) $r->seed,
            'started_at' => self::utc($r->started_at),
            'finished_at' => self::utc($r->finished_at),
            'host' => $r->host,
            'characters_done' => (int) $r->characters_done,
            'characters_total' => (int) $r->characters_total,
            'resume_count' => (int) $r->resume_count,
            'args' => self::json($r->args) ?: [],
            'error' => $r->error_text,
            'outcomes' => $counts[(int) $r->id] ?? (object) [],
        ])->all();
    }

    public function arenaPage(int $accountId): array
    {
        $char = $this->character($accountId);
        $cid = (int) $char['id'];
        $signedUp = (int) $char['arena_entered'] === 1;

        $waiting = DB::table('characters')
            ->where('arena_entered', 1)->where('is_active', 1)->where('is_bot', 0)
            ->orderBy('name')
            ->get(['id', 'name', 'level', 'is_bot']);
        $bots = DB::table('characters')->where('is_bot', 1)->where('is_active', 1)->orderBy('name')
            ->get(['id', 'name', 'level', 'is_bot']);
        $roster = $waiting->concat($bots)->map(fn ($c) => [
            'id' => (int) $c->id,
            'name' => $c->name,
            'level' => (int) $c->level,
            'is_bot' => (bool) $c->is_bot,
            'auto' => (bool) $c->is_bot,
        ])->all();

        $latest = DB::table('arena_tournaments')->where('status', 'completed')->orderByDesc('id')->first();
        $bracket = null;
        if ($latest) {
            $matches = DB::table('arena_matches as m')
                ->join('characters as a', 'a.id', '=', 'm.character_a_id')
                ->leftJoin('characters as b', 'b.id', '=', 'm.character_b_id')
                ->join('characters as w', 'w.id', '=', 'm.winner_id')
                ->where('m.tournament_id', $latest->id)
                ->orderBy('m.round_no')->orderBy('m.slot_no')
                ->get(['m.round_no', 'm.slot_no', 'm.reason', 'm.rounds', 'm.a_hp_end', 'm.b_hp_end', 'm.log',
                    'a.id as a_id', 'a.name as a_name', 'b.id as b_id', 'b.name as b_name', 'w.name as winner']);
            $rounds = [];
            foreach ($matches as $m) {
                $rounds[(int) $m->round_no][] = [
                    'slot' => (int) $m->slot_no,
                    'reason' => $m->reason,
                    'rounds' => (int) $m->rounds,
                    'a' => ['id' => (int) $m->a_id, 'name' => $m->a_name, 'hp' => (int) $m->a_hp_end],
                    'b' => $m->b_id === null ? null : ['id' => (int) $m->b_id, 'name' => $m->b_name, 'hp' => (int) $m->b_hp_end],
                    'winner' => $m->winner,
                    'mine' => (int) $m->a_id === $cid || (int) $m->b_id === $cid,
                    'log' => self::json($m->log) ?: [],
                ];
            }
            $champion = DB::table('characters')->where('id', $latest->champion_id)->first(['name', 'is_bot']);
            $bracket = [
                'id' => (int) $latest->id,
                'seed' => (string) $latest->seed,
                'finished_at' => self::utc($latest->finished_at),
                'entrants' => (int) $latest->entrant_count,
                'champion' => $champion?->name,
                'champion_bot' => (bool) ($champion?->is_bot),
                'rounds' => array_values($rounds),
            ];
        }

        $history = DB::table('arena_tournaments as t')
            ->join('characters as c', 'c.id', '=', 't.champion_id')
            ->where('t.status', 'completed')
            ->orderByDesc('t.id')->limit(8)
            ->get(['t.id', 't.finished_at', 't.entrant_count', 'c.name', 'c.is_bot'])
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'finished_at' => self::utc($t->finished_at),
                'entrants' => (int) $t->entrant_count,
                'champion' => $t->name,
                'champion_bot' => (bool) $t->is_bot,
            ])->all();

        return [
            'signedUp' => $signedUp,
            'roster' => $roster,
            'bracket' => $bracket,
            'history' => $history,
        ];
    }
}
