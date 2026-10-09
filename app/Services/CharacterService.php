<?php

namespace App\Services;

use App\Exceptions\GameException;
use App\Game\Balance;
use App\Game\Growth;
use App\Game\Progression;
use App\Game\Rules;
use App\Game\ShopMath;
use App\Game\Stats;
use Illuminate\Support\Facades\DB;

/**
 * Character writes from the website (DESIGN.md §11.1). One transaction each; the character row is locked
 * and its row_version checked. Every lookup goes through the session's account id.
 */
class CharacterService
{
    public function lock(int $accountId): array
    {
        $row = DB::table('characters')->where('account_id', $accountId)->lockForUpdate()->first();
        if (! $row) {
            throw new GameException('Character not found.');
        }

        return (array) $row;
    }

    public function checkVersion(array $char, ?int $posted): void
    {
        if ($posted === null || (int) $char['row_version'] !== $posted) {
            throw new GameException('Your character changed while you were editing. The page has been refreshed; try again.');
        }
    }

    public function bump(int $characterId): void
    {
        DB::table('characters')->where('id', $characterId)->increment('row_version');
    }

    /** @param array<string, int> $spend points per stat */
    public function spendPoints(int $accountId, int $version, array $spend): void
    {
        DB::transaction(function () use ($accountId, $version, $spend) {
            $char = $this->lock($accountId);
            $this->checkVersion($char, $version);
            $sets = [];
            foreach (Stats::PRIMARY as $stat) {
                $sets[$stat] = max(0, (int) ($spend[$stat] ?? 0));
            }
            $total = array_sum($sets);
            if ($total === 0) {
                throw new GameException('Choose at least one point to spend.');
            }
            if ($total > (int) $char['unspent_points']) {
                throw new GameException('That spends more points than you have.');
            }
            DB::update(
                'UPDATE characters SET stat_str = stat_str + ?, stat_dex = stat_dex + ?, stat_int = stat_int + ?,
                        stat_vit = stat_vit + ?, stat_luk = stat_luk + ?, unspent_points = unspent_points - ?,
                        row_version = row_version + 1
                  WHERE id = ?',
                [$sets['str'], $sets['dex'], $sets['int'], $sets['vit'], $sets['luk'], $total, $char['id']],
            );
        });
    }

    public function updatePrep(int $accountId, int $version, int $retreat, int $potion, bool $autoRepeat, bool $autoMode, ?string $autoProfile, string $autoPlace = 'profile'): void
    {
        if ($retreat < 0 || $retreat > 90 || $potion < 0 || $potion > 100) {
            throw new GameException('Retreat must be 0–90% and the potion threshold 0–100%.');
        }
        if (! in_array($autoPlace, ['profile', 'best'], true)) {
            throw new GameException('Pick how auto mode chooses the map.');
        }
        $profileId = null;
        if ($autoProfile !== null && $autoProfile !== '') {
            $profileId = DB::table('bot_profiles')->where('code', $autoProfile)->value('id');
            if (! $profileId) {
                throw new GameException('Pick one of the rival personalities.');
            }
        }
        if ($autoMode && ! $profileId) {
            throw new GameException('Auto mode needs a personality to follow.');
        }
        DB::transaction(function () use ($accountId, $version, $retreat, $potion, $autoRepeat, $autoMode, $profileId, $autoPlace) {
            $char = $this->lock($accountId);
            $this->checkVersion($char, $version);
            DB::table('characters')->where('id', $char['id'])->update([
                'retreat_threshold_pct' => $retreat,
                'potion_threshold_pct' => $potion,
                'auto_repeat' => $autoRepeat ? 1 : 0,
                'auto_mode' => $autoMode ? 1 : 0,
                'auto_profile_id' => $profileId,
                'auto_place' => $autoPlace,
                'row_version' => DB::raw('row_version + 1'),
            ]);
        });
    }

    public function respec(int $accountId, int $version): int
    {
        return DB::transaction(function () use ($accountId, $version) {
            $char = $this->lock($accountId);
            $this->checkVersion($char, $version);
            $balance = Balance::get();
            $level = (int) $char['level'];
            $cost = ShopMath::respecCost($level, $balance);
            if ((int) $char['gold'] < $cost) {
                throw new GameException("Respec costs {$cost} gold.");
            }
            $base = (int) $balance['character']['base_stat'];
            DB::table('characters')->where('id', $char['id'])->update([
                'stat_str' => $base, 'stat_dex' => $base, 'stat_int' => $base, 'stat_vit' => $base, 'stat_luk' => $base,
                'unspent_points' => Progression::pointPool($level, $balance),
                'gold' => DB::raw('gold - '.(int) $cost),
                'row_version' => DB::raw('row_version + 1'),
            ]);

            return $cost;
        });
    }

    private function ownedItem(int $characterId, int $itemId): array
    {
        $row = DB::table('item_instances as ii')->join('item_templates as it', 'it.id', '=', 'ii.template_id')
            ->where('ii.id', $itemId)->where('ii.character_id', $characterId)->where('ii.status', 'owned')
            ->first(['ii.*', 'it.slot as template_slot', 'it.slot', 'it.two_handed', 'it.req_level', 'it.scaling_stat']);
        if (! $row) {
            throw new GameException('That item is not in your pack.');
        }
        $item = (array) $row;
        $item['two_handed'] = (bool) $item['two_handed'];

        return $item;
    }

    /** @return array<string, array> current loadout keyed by slot (only the fields the rules need) */
    private function loadout(int $characterId): array
    {
        $out = [];
        foreach (DB::table('character_equipment as ce')->join('item_instances as ii', 'ii.id', '=', 'ce.item_instance_id')
            ->join('item_templates as it', 'it.id', '=', 'ii.template_id')
            ->where('ce.character_id', $characterId)
            ->get(['ce.slot as equip_slot', 'ii.id', 'it.slot as template_slot', 'it.two_handed', 'it.req_level']) as $row) {
            $out[$row->equip_slot] = ['id' => (int) $row->id, 'template_slot' => $row->template_slot, 'two_handed' => (bool) $row->two_handed, 'req_level' => (int) $row->req_level];
        }

        return $out;
    }

    public function equip(int $accountId, int $version, int $itemId, string $slot): string
    {
        if (! in_array($slot, Rules::EQUIP_SLOTS, true)) {
            throw new GameException('Unknown equipment slot.');
        }

        return DB::transaction(function () use ($accountId, $version, $itemId, $slot) {
            $char = $this->lock($accountId);
            $this->checkVersion($char, $version);
            $cid = (int) $char['id'];
            $item = $this->ownedItem($cid, $itemId);
            $loadout = $this->loadout($cid);
            unset($loadout[$slot]);
            [$ok, $note] = Rules::canEquip($item, $slot, (int) $char['level'], $loadout);
            if (! $ok) {
                throw new GameException(Rules::equipMessage($note));
            }
            DB::table('character_equipment')->where('character_id', $cid)->where('slot', $slot)->delete();
            DB::table('character_equipment')->where('item_instance_id', $itemId)->delete();
            if ($slot === 'weapon' && $item['two_handed']) {
                DB::table('character_equipment')->where('character_id', $cid)->where('slot', 'offhand')->delete();
            }
            DB::table('character_equipment')->insert(['character_id' => $cid, 'slot' => $slot, 'item_instance_id' => $itemId]);
            $this->bump($cid);

            return $item['display_name'];
        });
    }

    public function unequip(int $accountId, int $version, string $slot): void
    {
        if (! in_array($slot, Rules::EQUIP_SLOTS, true)) {
            throw new GameException('Unknown equipment slot.');
        }
        DB::transaction(function () use ($accountId, $version, $slot) {
            $char = $this->lock($accountId);
            $this->checkVersion($char, $version);
            $capacity = (int) Balance::get()['loot']['inventory_capacity'];
            if (DB::table('v_inventory')->where('character_id', $char['id'])->count() >= $capacity) {
                throw new GameException('Your pack is full. Sell something first.');
            }
            DB::table('character_equipment')->where('character_id', $char['id'])->where('slot', $slot)->delete();
            $this->bump((int) $char['id']);
        });
    }

    /** @param array<int, int> $belt quantity per consumable id */
    public function saveBelt(int $accountId, int $version, array $belt): void
    {
        DB::transaction(function () use ($accountId, $version, $belt) {
            $char = $this->lock($accountId);
            $this->checkVersion($char, $version);
            $slots = (int) Balance::get()['combat']['belt_slots'];
            $rows = DB::table('character_consumables as cc')->join('consumable_templates as ct', 'ct.id', '=', 'cc.consumable_id')
                ->where('cc.character_id', $char['id'])->get(['cc.consumable_id', 'cc.quantity', 'ct.use_trigger', 'ct.name']);
            $total = 0;
            $updates = [];
            foreach ($rows as $row) {
                $qty = max(0, (int) ($belt[(int) $row->consumable_id] ?? 0));
                if ($row->use_trigger === 'manual') {
                    $qty = 0;
                }
                if ($qty > (int) $row->quantity) {
                    throw new GameException("You only have {$row->quantity} × {$row->name}.");
                }
                $total += $qty;
                $updates[(int) $row->consumable_id] = $qty;
            }
            if ($total > $slots) {
                throw new GameException("The belt holds {$slots} items.");
            }
            foreach ($updates as $id => $qty) {
                DB::table('character_consumables')->where('character_id', $char['id'])->where('consumable_id', $id)->update(['belt_qty' => $qty]);
            }
            $this->bump((int) $char['id']);
        });
    }

    public function useBandage(int $accountId, int $version): void
    {
        DB::transaction(function () use ($accountId, $version) {
            $char = $this->lock($accountId);
            $this->checkVersion($char, $version);
            if ((int) $char['injury_severity'] !== 1) {
                throw new GameException('A bandage only helps while you are Wounded.');
            }
            $row = DB::table('character_consumables as cc')->join('consumable_templates as ct', 'ct.id', '=', 'cc.consumable_id')
                ->where('cc.character_id', $char['id'])->where('ct.effect_type', 'cure_injury')->where('cc.quantity', '>', 0)
                ->first(['cc.consumable_id', 'cc.quantity', 'cc.belt_qty']);
            if (! $row) {
                throw new GameException('You have no Bandage Kit.');
            }
            $left = (int) $row->quantity - 1;
            $stack = DB::table('character_consumables')->where('character_id', $char['id'])->where('consumable_id', $row->consumable_id);
            if ($left === 0) {
                $stack->delete();
            } else {
                $stack->update(['quantity' => $left, 'belt_qty' => min((int) $row->belt_qty, $left)]);
            }
            DB::table('characters')->where('id', $char['id'])->update([
                'injury_severity' => 0, 'injury_runs_left' => 0, 'row_version' => DB::raw('row_version + 1'),
            ]);
            ShopService::ledger((int) $char['id'], 'use', null, null, (int) $row->consumable_id, 1, 0, (int) $char['gold']);
        });
    }

    /** Orders flow with level validation (DESIGN.md §11.1). Returns the place name. */
    public function setOrder(int $accountId, int $placeId): string
    {
        return DB::transaction(function () use ($accountId, $placeId) {
            $char = $this->lock($accountId);
            $row = DB::table('places')->where('id', $placeId)->where('is_active', 1)->first();
            if (! $row) {
                throw new GameException('That place is not on the map.');
            }
            $place = (array) $row;
            [$ok, $reason] = Rules::canEnter((int) $char['level'], $place);
            if (! $ok) {
                throw new GameException(Rules::rejectMessage((int) $char['level'], $place, $reason));
            }
            DB::table('trip_orders')->where('character_id', $char['id'])->where('status', 'pending')->update(['status' => 'cancelled']);
            DB::table('trip_orders')->insert(['character_id' => $char['id'], 'place_id' => $placeId, 'submitted_by' => 'web']);
            $this->bump((int) $char['id']);

            return $place['name'];
        });
    }

    public function enterArena(int $accountId, int $version): void
    {
        DB::transaction(function () use ($accountId, $version) {
            $char = $this->lock($accountId);
            $this->checkVersion($char, $version);
            if ((int) $char['arena_entered'] === 1) {
                throw new GameException('You are already signed up for the arena.');
            }
            DB::table('characters')->where('id', $char['id'])->update([
                'arena_entered' => 1,
                'row_version' => DB::raw('row_version + 1'),
            ]);
        });
    }

    public function leaveArena(int $accountId, int $version): void
    {
        DB::transaction(function () use ($accountId, $version) {
            $char = $this->lock($accountId);
            $this->checkVersion($char, $version);
            if ((int) $char['arena_entered'] !== 1) {
                throw new GameException('You are not signed up for the arena.');
            }
            DB::table('characters')->where('id', $char['id'])->update([
                'arena_entered' => 0,
                'row_version' => DB::raw('row_version + 1'),
            ]);
        });
    }

    public function trainSkill(int $accountId, int $version, string $code): string
    {
        return DB::transaction(function () use ($accountId, $version, $code) {
            $char = $this->lock($accountId);
            $this->checkVersion($char, $version);
            $balance = Balance::get();
            $skill = Growth::skillByCode($code, $balance);
            if ($skill === null) {
                throw new GameException('That skill does not exist.');
            }
            $cid = (int) $char['id'];
            $ranks = self::jsonMap($char['skills'] ?? null);
            $rank = (int) ($ranks[$code] ?? 0);
            $cost = Growth::rankCost($rank + 1, $balance);
            if ($cost === null) {
                throw new GameException("{$skill['name']} is already at its highest rank.");
            }
            if ((int) $char['gold'] < (int) $cost['gold']) {
                throw new GameException("Rank ".($rank + 1)." costs {$cost['gold']} gold.");
            }
            $materials = $this->materialStacks($char);
            if (! Growth::pay($materials, $cost['material'], (int) $cost['qty'])) {
                throw new GameException('You do not have the materials for that rank.');
            }
            $ranks[$code] = $rank + 1;
            DB::table('characters')->where('id', $cid)->update([
                'gold' => (int) $char['gold'] - (int) $cost['gold'],
                'materials' => json_encode(self::qtyMap($materials), JSON_THROW_ON_ERROR),
                'skills' => json_encode($ranks, JSON_THROW_ON_ERROR),
                'row_version' => DB::raw('row_version + 1'),
            ]);

            return $skill['name'];
        });
    }

    public function feedHorcrux(int $accountId, int $version, int $itemId): string
    {
        return DB::transaction(function () use ($accountId, $version, $itemId) {
            $char = $this->lock($accountId);
            $this->checkVersion($char, $version);
            $balance = Balance::get();
            $cid = (int) $char['id'];
            $item = DB::table('item_instances')->where('id', $itemId)->where('character_id', $cid)->where('status', 'owned')->lockForUpdate()->first();
            if (! $item) {
                throw new GameException('That item is not in your inventory.');
            }
            if (DB::table('character_equipment')->where('item_instance_id', $itemId)->exists()) {
                throw new GameException('Unequip it before feeding the horcrux.');
            }
            $tier = (int) $char['horcrux_tier'];
            if ($tier >= Growth::maxTier($balance)) {
                throw new GameException('The horcrux cannot take any more.');
            }
            $progress = (int) $char['horcrux_progress'] + Growth::feedValue([
                'rarity' => $item->rarity,
                'item_level' => (int) $item->item_level,
            ], $balance);
            DB::table('item_instances')->where('id', $itemId)->update(['status' => 'destroyed', 'character_id' => null]);
            DB::table('characters')->where('id', $cid)->update([
                'horcrux_tier' => Growth::tierFor($progress, $balance),
                'horcrux_progress' => $progress,
                'row_version' => DB::raw('row_version + 1'),
            ]);

            return $item->display_name;
        });
    }

    /** @return list<array{code: string, qty: int, kind: string}> */
    private function materialStacks(array $char): array
    {
        $owned = self::jsonMap($char['materials'] ?? null);

        return DB::table('material_templates')
            ->orderBy('sort_order')->orderBy('id')
            ->get(['code', 'kind'])
            ->map(fn ($row) => ['code' => $row->code, 'kind' => $row->kind, 'qty' => (int) ($owned[$row->code] ?? 0)])
            ->all();
    }

    /** @param list<array{code: string, qty: int}> $materials */
    private static function qtyMap(array $materials): array
    {
        $map = [];
        foreach ($materials as $mat) {
            if ((int) $mat['qty'] > 0) {
                $map[$mat['code']] = (int) $mat['qty'];
            }
        }

        return $map;
    }

    private static function jsonMap(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    public function cancelOrder(int $accountId): void
    {
        DB::transaction(function () use ($accountId) {
            $char = $this->lock($accountId);
            DB::table('trip_orders')->where('character_id', $char['id'])->where('status', 'pending')->update(['status' => 'cancelled']);
            $this->bump((int) $char['id']);
        });
    }

    public function markMailRead(int $accountId, int $mailId): void
    {
        DB::update(
            'UPDATE mail m JOIN characters c ON c.id = m.character_id SET m.is_read = 1
              WHERE m.id = ? AND c.account_id = ? AND m.is_read = 0',
            [$mailId, $accountId],
        );
    }

    public function markAllMailRead(int $accountId): void
    {
        DB::update(
            'UPDATE mail m JOIN characters c ON c.id = m.character_id SET m.is_read = 1
              WHERE c.account_id = ? AND m.is_read = 0 AND m.deleted_at IS NULL',
            [$accountId],
        );
    }

    public function deleteMail(int $accountId, int $mailId): void
    {
        $changed = DB::update(
            'UPDATE mail m JOIN characters c ON c.id = m.character_id SET m.deleted_at = UTC_TIMESTAMP()
              WHERE m.id = ? AND c.account_id = ? AND m.deleted_at IS NULL',
            [$mailId, $accountId],
        );
        if ($changed === 0) {
            throw new GameException('That letter is already gone.');
        }
    }
}
