-- Six bot personalities and their starter kits. DESIGN.md §8.1.

INSERT INTO bot_profiles
  (code, name, archetype, description,
   w_str, w_dex, w_int, w_vit, w_luk, preferred_scaling, gear_weights,
   risk_offset, retreat_threshold_pct, randomness, rarity_preference,
   memory_window, fail_threshold, fail_avoid_runs, stepup_after_wins, stepup_hp_pct,
   rest_when_injured, sell_junk, gold_reserve_pct, potion_target, buff_preference, shop_min_gain_pct)
VALUES
('bulwark', 'Bulwark', 'tank',
 'Holds the line, spends on stone skin, and leaves before the fight turns ugly.',
 0.30, 0.10, 0.00, 0.50, 0.10, 'str',
 JSON_OBJECT('hp', 1.0, 'attack', 0.6, 'defense', 1.2, 'mres', 0.7, 'crit', 0.2, 'dodge', 0.3, 'speed', 0.2, 'mf', 0.1),
 0, 20, 0.10, 0.00, 8, 2, 3, 2, 50, 1, 1, 40, 2, 'stoneskin', 5),
('shade', 'Shade', 'rogue',
 'Slips a level ahead of comfort and would rather bandage than rest.',
 0.15, 0.50, 0.00, 0.15, 0.20, 'dex',
 JSON_OBJECT('hp', 0.5, 'attack', 1.0, 'defense', 0.4, 'mres', 0.3, 'crit', 1.0, 'dodge', 0.9, 'speed', 0.6, 'mf', 0.3),
 1, 35, 0.25, 0.30, 8, 2, 2, 2, 60, 0, 1, 25, 2, 'might', 5),
('arcanist', 'Arcanist', 'mage',
 'One defeat is enough. Avoids that road for a long while after.',
 0.00, 0.10, 0.60, 0.20, 0.10, 'int',
 JSON_OBJECT('hp', 0.7, 'attack', 1.2, 'defense', 0.4, 'mres', 0.6, 'crit', 0.5, 'dodge', 0.3, 'speed', 0.3, 'mf', 0.2),
 0, 40, 0.15, 0.20, 8, 1, 4, 3, 65, 1, 1, 35, 3, 'stoneskin', 4),
('berserker', 'Redhand', 'berserker',
 'Pushes two levels up, spends almost everything, and rarely walks away.',
 0.60, 0.15, 0.00, 0.20, 0.05, 'str',
 JSON_OBJECT('hp', 0.6, 'attack', 1.3, 'defense', 0.4, 'mres', 0.2, 'crit', 0.7, 'dodge', 0.2, 'speed', 0.3, 'mf', 0.0),
 2, 10, 0.30, 0.00, 8, 3, 2, 1, 40, 0, 1, 10, 1, 'might', 3),
('ranger', 'Ranger', 'ranger',
 'Keeps to the recommended range and travels light.',
 0.10, 0.55, 0.00, 0.25, 0.10, 'dex',
 JSON_OBJECT('hp', 0.8, 'attack', 1.0, 'defense', 0.5, 'mres', 0.4, 'crit', 0.8, 'dodge', 0.6, 'speed', 0.7, 'mf', 0.2),
 0, 30, 0.15, 0.10, 8, 2, 3, 2, 60, 1, 1, 30, 2, 'none', 5),
('prospector', 'Prospector', 'gambler',
 'Follows the smell of rare loot and carries a charm when the road looks rich.',
 0.20, 0.20, 0.00, 0.25, 0.35, 'str',
 JSON_OBJECT('hp', 0.8, 'attack', 0.8, 'defense', 0.6, 'mres', 0.4, 'crit', 0.4, 'dodge', 0.4, 'speed', 0.3, 'mf', 1.2),
 1, 30, 0.35, 1.00, 8, 2, 2, 2, 55, 0, 1, 20, 2, 'luck', 8);

INSERT INTO characters
  (account_id, is_bot, bot_profile_id, name, level, xp, total_xp, gold,
   stat_str, stat_dex, stat_int, stat_vit, stat_luk, unspent_points,
   retreat_threshold_pct, potion_threshold_pct, auto_repeat, is_active)
SELECT NULL, 1, bp.id, v.name, 1, 0, 0, 50,
       5, 5, 5, 5, 5, 5,
       bp.retreat_threshold_pct,
       LEAST(90, bp.retreat_threshold_pct + 15),
       1, 1
FROM (
    SELECT 'bulwark' AS code, 'Brakka the Wall' AS name
    UNION ALL SELECT 'shade', 'Vex'
    UNION ALL SELECT 'arcanist', 'Old Minnow'
    UNION ALL SELECT 'berserker', 'Grul Redhand'
    UNION ALL SELECT 'ranger', 'Sable'
    UNION ALL SELECT 'prospector', 'Lucky Pim'
) v
JOIN bot_profiles bp ON bp.code = v.code;

-- Starter weapon matches preferred scaling. Armor is the same for every bot.
INSERT INTO item_instances
  (template_id, character_id, status, rarity, item_level, display_name, power, armor, mres, sell_value, source)
SELECT it.id, c.id, 'owned', 'common', it.req_level, it.name,
       it.base_power, it.base_armor, it.base_mres,
       ROUND(it.base_value * (1 + 0.05 * it.req_level)),
       'starter'
FROM characters c
JOIN bot_profiles bp ON bp.id = c.bot_profile_id
JOIN item_templates it ON it.code = CASE bp.preferred_scaling
    WHEN 'str' THEN 'rusty_sword'
    WHEN 'dex' THEN 'worn_dagger'
    ELSE 'oak_staff'
END
WHERE c.is_bot = 1;

INSERT INTO item_instances
  (template_id, character_id, status, rarity, item_level, display_name, power, armor, mres, sell_value, source)
SELECT it.id, c.id, 'owned', 'common', it.req_level, it.name,
       it.base_power, it.base_armor, it.base_mres,
       ROUND(it.base_value * (1 + 0.05 * it.req_level)),
       'starter'
FROM characters c
JOIN item_templates it ON it.code IN ('cloth_tunic', 'soft_boots')
WHERE c.is_bot = 1;

INSERT INTO character_equipment (character_id, slot, item_instance_id)
SELECT ii.character_id, it.slot, ii.id
FROM item_instances ii
JOIN item_templates it ON it.id = ii.template_id
JOIN characters c ON c.id = ii.character_id
WHERE c.is_bot = 1 AND ii.source = 'starter' AND it.slot <> 'ring';

INSERT INTO character_consumables (character_id, consumable_id, quantity, belt_qty)
SELECT c.id, ct.id, 2, 1
FROM characters c
JOIN consumable_templates ct ON ct.code = 'minor_healing_potion'
WHERE c.is_bot = 1;
