<?php

namespace Tests\Unit;

use App\Game\Combat;
use App\Game\ItemTemplates;
use App\Game\Loot;
use App\Game\Numbers;
use App\Game\Progression;
use App\Game\Rng;
use App\Game\Rules;
use App\Game\Trip;

class CoreTest extends GameTestCase
{
    public function test_multi_level_up_and_monster_xp(): void
    {
        $gained = Progression::applyXp(1, 0, 500, self::$balance);
        $this->assertSame(3, $gained['level']);
        $this->assertSame(80, $gained['xp']);
        $this->assertSame(10, $gained['points']);
        $this->assertSame(40 + 60, $gained['gold']);
        $this->assertSame(100, Progression::monsterXp(100, 5, 5, self::$balance));
        $this->assertSame(20, Progression::monsterXp(100, 1, 10, self::$balance));
        $this->assertSame(150, Progression::monsterXp(100, 20, 10, self::$balance));
        $this->assertSame(30, Progression::levelCap(self::$balance, 16));
        $raised = self::$balance;
        $raised['character']['level_cap'] = Progression::levelCap(self::$balance, 32);
        $this->assertSame(32, Progression::applyXp(30, 0, 500000, $raised)['level']);
    }

    public function test_injury_transitions(): void
    {
        $this->assertSame([1, 1], Progression::nextInjury('defeated', 0, 0));
        $this->assertSame([2, 1], Progression::nextInjury('defeated', 1, 1));
        $this->assertSame([0, 0], Progression::nextInjury('cleared', 1, 1));
        $this->assertSame([0, 0], Progression::nextInjury('rested', 2, 1));
        $this->assertSame([0, 0], Progression::nextInjury('blocked_injured', 2, 1));
        $this->assertSame([0, 0], Progression::nextInjury('order_rejected', 1, 1));
        $this->assertSame([0, 0], Progression::nextInjury('retreated', 0, 0));
    }

    public function test_defeat_halves_rewards_and_loses_items(): void
    {
        $result = Trip::resolve($this->fightContext(monsterHp: 5000, monsterAttack: 80, dropChance: 1), Rng::trip(7, 1));
        $this->assertSame('defeated', $result['outcome']);
        $this->assertSame([], $result['items']);
        $this->assertSame(['severity' => 1, 'runs_left' => 1], $result['injury']);
        $pouch = array_sum(array_column($result['encounters'], 'xp'));
        $this->assertSame(Numbers::halfUp($pouch * 0.5), $result['xp_gained']);
    }

    public function test_clear_bonus_and_determinism(): void
    {
        $ctx = $this->fightContext(monsterHp: 5, monsterAttack: 1, encounters: [1, 1]);
        $first = Trip::resolve($ctx, Rng::trip(42, 3));
        $second = Trip::resolve($ctx, Rng::trip(42, 3));
        $this->assertSame('cleared', $first['outcome']);
        $this->assertSame($first['outcome'], $second['outcome']);
        $this->assertSame($first['xp_gained'], $second['xp_gained']);
        $pouch = array_sum(array_column($first['encounters'], 'xp'));
        $this->assertSame(Numbers::halfUp($pouch * 0.2), $first['clear_bonus']);
        $this->assertSame($first['encounters'][0]['log'], $second['encounters'][0]['log']);
    }

    public function test_order_rejected_rests(): void
    {
        $ctx = $this->fightContext();
        $ctx['plan'] = 'rejected';
        $ctx['reject_reason'] = 'too_high';
        $ctx['character']['level'] = 7;
        $ctx['open_places'] = ['Old Copper Mine (Lv 3–6, max 9)'];
        $result = Trip::resolve($ctx, Rng::trip(1, 1));
        $this->assertSame('order_rejected', $result['outcome']);
        $this->assertSame(35, $result['gold_gained']);
        $this->assertSame(['severity' => 0, 'runs_left' => 0], $result['injury']);
    }

    public function test_potion_choice_and_smoke(): void
    {
        $belt = [
            ['code' => 'minor_healing_potion', 'effect_type' => 'heal_pct', 'effect_value' => 30, 'belt_qty' => 1],
            ['code' => 'healing_potion', 'effect_type' => 'heal_pct', 'effect_value' => 50, 'belt_qty' => 1],
        ];
        $this->assertSame('minor_healing_potion', $belt[Combat::choosePotion(40, 100, 50, $belt)]['code']);
        $this->assertSame('healing_potion', $belt[Combat::choosePotion(10, 100, 80, $belt)]['code']);
        $this->assertNull(Combat::choosePotion(90, 100, 50, $belt));
        $base = Combat::fleeChance(10, [10], self::$balance);
        $smoked = Combat::fleeChance(10, [10], self::$balance, true);
        $this->assertEqualsWithDelta(min(0.95, $base + 0.30), $smoked, 1e-9);
    }

    public function test_attack_is_at_least_one(): void
    {
        $attacker = ['attack' => 1, 'crit' => 0, 'crit_mult' => 1.5, 'damage_type' => 'physical'];
        $target = ['hp' => 10, 'dodge' => 0, 'defense' => 1000, 'mres' => 0];
        $event = Combat::resolveAttack($attacker, $target, Rng::trip(1, 1), self::$balance);
        $this->assertSame('hit', $event['ev']);
        $this->assertGreaterThanOrEqual(1, $event['dmg']);
    }

    public function test_affix_constraints(): void
    {
        $affix = fn (int $id, string $kind, string $key, string $groups, float $perIlvl) => [
            'id' => $id, 'code' => "a{$id}", 'name' => "A{$id}", 'kind' => $kind, 'stat_key' => $key, 'slot_groups' => $groups,
            'min_ilvl' => 1, 'weight' => 100, 'value_min_base' => 1, 'value_max_base' => 3, 'value_per_ilvl' => $perIlvl,
        ];
        $pool = [
            $affix(1, 'suffix', 'dex', 'armor', 0),
            $affix(2, 'prefix', 'armor', 'armor', 0.5),
            $affix(3, 'prefix', 'mres', 'armor', 0.4),
            $affix(4, 'suffix', 'vit', 'armor', 0.25),
            $affix(5, 'prefix', 'attack', 'weapon', 0),
        ];
        $rolled = Loot::rollAffixes($pool, ['slot' => 'chest', 'implicit' => ['dex' => 1]], 10, 4, Rng::trip(3, 9), self::$balance, true);
        $keys = array_column($rolled, 'stat_key');
        $this->assertNotContains('dex', $keys);
        $this->assertNotContains('attack', $keys);
        $this->assertLessThanOrEqual(2, count(array_filter($rolled, fn ($a) => $a['kind'] === 'prefix')));
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_legendary_name_follows_the_weapon_and_its_strongest_stat(): void
    {
        $pool = [
            ['kind' => 'base', 'name' => 'Edge', 'item_type' => 'sword', 'slot' => 'weapon', 'stat_key' => null, 'placement' => null, 'min_ilvl' => 1, 'weight' => 100],
            ['kind' => 'base', 'name' => 'Cleaver', 'item_type' => 'axe', 'slot' => 'weapon', 'stat_key' => null, 'placement' => null, 'min_ilvl' => 1, 'weight' => 100],
            ['kind' => 'epithet', 'name' => 'Leeching', 'item_type' => null, 'slot' => null, 'stat_key' => 'hp', 'placement' => 'prefix', 'min_ilvl' => 1, 'weight' => 100],
            ['kind' => 'epithet', 'name' => 'of the Titan', 'item_type' => null, 'slot' => null, 'stat_key' => 'str', 'placement' => 'suffix', 'min_ilvl' => 1, 'weight' => 100],
            ['kind' => 'unique', 'name' => 'Excalibur', 'item_type' => 'sword', 'slot' => 'weapon', 'stat_key' => null, 'placement' => null, 'min_ilvl' => 12, 'weight' => 100],
            ['kind' => 'unique', 'name' => 'Ragnarok', 'item_type' => 'axe', 'slot' => 'weapon', 'stat_key' => null, 'placement' => null, 'min_ilvl' => 20, 'weight' => 100],
        ];
        $sword = ['name' => 'Rusty Sword', 'item_type' => 'sword', 'slot' => 'weapon', 'implicit' => []];
        $affixes = [
            ['kind' => 'prefix', 'name' => 'Vital', 'stat_key' => 'hp', 'value' => 12],
            ['kind' => 'suffix', 'name' => 'of the Bear', 'stat_key' => 'str', 'value' => 2],
        ];
        $forged = self::$balance;
        $forged['loot']['legendary_unique_weight'] = 0;
        $forged['loot']['legendary_forged_weight'] = 100;
        $this->assertSame('Leeching Edge', Loot::legendaryName($sword, 5, $affixes, $pool, Rng::trip(1, 1), $forged));

        $unique = self::$balance;
        $unique['loot']['legendary_unique_weight'] = 100;
        $unique['loot']['legendary_forged_weight'] = 0;
        $this->assertSame('Excalibur', Loot::legendaryName($sword, 12, $affixes, $pool, Rng::trip(1, 1), $unique));
        $this->assertSame('Leeching Edge', Loot::legendaryName($sword, 11, $affixes, $pool, Rng::trip(1, 1), $unique));
        $axe = ['name' => 'War Axe', 'item_type' => 'axe', 'slot' => 'weapon', 'implicit' => ['hp' => 8]];
        $this->assertSame('Ragnarok', Loot::legendaryName($axe, 20, [], $pool, Rng::trip(2, 2), $unique));

        $this->assertSame('Keen Worn Dagger of the Owl', Loot::itemName('Worn Dagger', [
            ['kind' => 'prefix', 'name' => 'Keen', 'value' => 2],
            ['kind' => 'suffix', 'name' => 'of the Owl', 'value' => 1],
        ]));
        $again = Loot::legendaryName($sword, 5, $affixes, $pool, Rng::trip(1, 1), $forged);
        $this->assertSame('Leeching Edge', $again);
    }

    public function test_chest_roll_count_grows_with_place_level(): void
    {
        $this->assertSame(2, Loot::chestRollCount(2, self::$balance));
        $this->assertSame(2, Loot::chestRollCount(10, self::$balance));
        $this->assertSame(3, Loot::chestRollCount(14, self::$balance));
        $this->assertSame(4, Loot::chestRollCount(30, self::$balance));
    }

    public function test_chest_contents_follow_the_stored_seed(): void
    {
        $table = ['entries' => [[
            'entry_type' => 'item',
            'weight' => 1,
            'template' => [
                'id' => 1, 'code' => 'rusty_sword', 'name' => 'Rusty Sword', 'slot' => 'weapon',
                'base_power' => 4, 'base_armor' => 0, 'base_mres' => 0, 'implicit' => [],
                'req_level' => 1, 'base_value' => 5,
            ],
        ]]];
        $once = Loot::rollChest($table, 2, 0.25, 8, [], Rng::chest(99), self::$balance);
        $twice = Loot::rollChest($table, 2, 0.25, 8, [], Rng::chest(99), self::$balance);
        $this->assertCount(2, $once);
        $this->assertSame($once, $twice);
        $this->assertSame('chest', $once[0]['source']);
    }

    public function test_boss_rarity_floor(): void
    {
        $rng = Rng::trip(5, 5);
        $seen = [];
        for ($i = 0; $i < 30; $i++) {
            $seen[Loot::rollRarity(self::$balance, 0, $rng, 'uncommon')['name']] = true;
        }
        $this->assertArrayNotHasKey('common', $seen);
    }

    public function test_two_handed_equip_rule(): void
    {
        $sword = ['slot' => 'weapon', 'req_level' => 1, 'two_handed' => false];
        $staff = ['slot' => 'weapon', 'req_level' => 1, 'two_handed' => true];
        $shield = ['slot' => 'offhand', 'req_level' => 1, 'two_handed' => false];
        $this->assertSame([true, 'clears_offhand'], Rules::canEquip($staff, 'weapon', 1, ['offhand' => $shield]));
        $this->assertSame([false, 'two_handed'], Rules::canEquip($shield, 'offhand', 1, ['weapon' => $staff]));
        $this->assertTrue(Rules::canEquip($shield, 'offhand', 1, ['weapon' => $sword])[0]);
        $this->assertSame([false, 'level'], Rules::canEquip(['slot' => 'head', 'req_level' => 5, 'two_handed' => false], 'head', 1, []));
    }

    public function test_capacity_sells_common_before_new_drop(): void
    {
        $new = [['rarity' => 'rare', 'sell_value' => 100, 'display_name' => 'New', 'id' => null]];
        $plan = Loot::planCapacity($new, [['id' => 4, 'sell_value' => 3, 'name' => 'Old']], 1, 1);
        $this->assertSame($new, $plan['kept']);
        $this->assertSame(4, $plan['sold_owned'][0]['id']);
    }

    public function test_item_template_authoring_rules(): void
    {
        [$ok, $reason, $row] = ItemTemplates::prepare([
            'name' => 'Ash Spear', 'slot' => 'weapon', 'item_type' => 'sword', 'scaling_stat' => 'str',
            'two_handed' => 1, 'base_power' => 7, 'req_level' => 2, 'base_value' => 12,
            'implicit' => [['key' => 'speed', 'value' => 1]],
        ]);
        $this->assertTrue($ok, (string) $reason);
        $this->assertSame('ash_spear', $row['code']);
        $this->assertSame(['speed' => 1], $row['implicit_mods']);

        [$ok] = ItemTemplates::prepare(['name' => 'X', 'slot' => 'weapon', 'item_type' => 'sword', 'req_level' => 1, 'base_value' => 1]);
        $this->assertFalse($ok);

        [$ok] = ItemTemplates::prepare([
            'name' => 'Cap', 'slot' => 'head', 'item_type' => 'leather', 'scaling_stat' => 'str',
            'req_level' => 1, 'base_value' => 1,
        ]);
        $this->assertFalse($ok);

        [$ok] = ItemTemplates::prepare([
            'name' => 'Cap', 'slot' => 'head', 'item_type' => 'leather',
            'implicit' => [['key' => 'not_a_stat', 'value' => 1]], 'req_level' => 1, 'base_value' => 1,
        ]);
        $this->assertFalse($ok);
    }

    public function test_rng_is_seeded_and_streams_are_separate(): void
    {
        $draw = fn (Rng $rng) => [$rng->random(), $rng->random(), $rng->random()];
        $this->assertSame($draw(Rng::trip(42, 1)), $draw(Rng::trip(42, 1)));
        $this->assertNotSame($draw(Rng::trip(42, 1)), $draw(Rng::trip(42, 2)));
        $this->assertNotSame($draw(Rng::bot(42, 1)), $draw(Rng::trip(42, 1)));
    }
}
