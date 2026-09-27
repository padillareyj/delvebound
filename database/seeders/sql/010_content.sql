-- Places, monsters, item templates, affixes, and loot tables. DESIGN.md §7.

INSERT INTO regions (code, name, blurb, sort_order) VALUES
('wilds', 'The Wilds', 'Lanternford and the roads that leave it.', 1),
('salt_marches', 'The Salt Marches', 'Drowned country past the peaks. Salt, glass, and a court under the water.', 2),
('ashen_crown', 'The Ashen Crown', 'The last range. Cinders, a dead citadel, and the crown that still burns.', 3);

INSERT INTO loot_tables (code, name, description) VALUES
('lt_forest', 'Forest Spoils', 'Gear lost on the Greenwood paths.'),
('lt_mine', 'Mine Salvage', 'Tools and armor from the copper tunnels.'),
('lt_crypt', 'Crypt Relics', 'Burial goods from the Sunken Crypt.'),
('lt_peaks', 'Peak Finds', 'Gear frozen along the Frostfang trails.'),
('lt_depths', 'Depth Relics', 'Fire-touched arms from the Obsidian Depths.');

INSERT INTO item_templates
  (code, name, slot, item_type, scaling_stat, two_handed, base_power, base_armor, base_mres, implicit_mods, req_level, base_value, description)
VALUES
('rusty_sword', 'Rusty Sword', 'weapon', 'sword', 'str', 0, 4, 0, 0, NULL, 1, 5, 'A notched blade that still cuts.'),
('worn_dagger', 'Worn Dagger', 'weapon', 'dagger', 'dex', 0, 3, 0, 0, JSON_OBJECT('crit_pct', 3), 1, 5, 'Light in the hand, quick to the ribs.'),
('oak_staff', 'Oak Staff', 'weapon', 'staff', 'int', 1, 6, 0, 1, NULL, 1, 5, 'A length of oak that channels a spark.'),
('iron_longsword', 'Iron Longsword', 'weapon', 'sword', 'str', 0, 9, 0, 0, NULL, 4, 25, 'A straight blade for a practiced arm.'),
('hunters_shortbow', 'Hunter''s Shortbow', 'weapon', 'bow', 'dex', 1, 10, 0, 0, JSON_OBJECT('speed', 2), 4, 25, 'A short hunting bow, strung tight.'),
('apprentice_wand', 'Apprentice Wand', 'weapon', 'wand', 'int', 0, 8, 0, 2, NULL, 4, 25, 'A student''s wand, still warm from practice.'),
('war_axe', 'War Axe', 'weapon', 'axe', 'str', 1, 18, 0, 0, JSON_OBJECT('crit_dmg_pct', 10), 8, 70, 'A heavy axe meant to end a fight.'),
('runed_staff', 'Runed Staff', 'weapon', 'staff', 'int', 1, 17, 0, 4, JSON_OBJECT('int', 2), 8, 70, 'Runes crawl along the shaft.'),
('shadowfang', 'Shadowfang Dagger', 'weapon', 'dagger', 'dex', 0, 14, 0, 0, JSON_OBJECT('crit_pct', 5), 10, 110, 'The edge drinks the light.'),
('frostbite_blade', 'Frostbite Blade', 'weapon', 'sword', 'str', 0, 20, 0, 3, NULL, 12, 160, 'Frost clings to the fuller.'),
('ember_staff', 'Ember Staff', 'weapon', 'staff', 'int', 1, 27, 0, 6, JSON_OBJECT('int', 3), 13, 200, 'The head of the staff never cools.'),
('wooden_buckler', 'Wooden Buckler', 'offhand', 'shield', NULL, 0, 0, 3, 0, NULL, 1, 6, 'A small round shield of banded wood.'),
('iron_kite_shield', 'Iron Kite Shield', 'offhand', 'shield', NULL, 0, 0, 8, 2, NULL, 6, 45, 'A kite shield that covers the body.'),
('cloth_tunic', 'Cloth Tunic', 'chest', 'cloth', NULL, 0, 0, 2, 1, NULL, 1, 3, 'A plain tunic, better than nothing.'),
('leather_jerkin', 'Leather Jerkin', 'chest', 'leather', NULL, 0, 0, 5, 1, JSON_OBJECT('dex', 1), 3, 20, 'Boiled leather, supple and quiet.'),
('chainmail', 'Chainmail', 'chest', 'mail', NULL, 0, 0, 10, 2, JSON_OBJECT('speed', -1), 6, 50, 'A hauberk. Heavy, and it shows.'),
('drakescale_mail', 'Drakescale Mail', 'chest', 'mail', NULL, 0, 0, 18, 8, NULL, 13, 220, 'Scales that once belonged to something larger.'),
('leather_cap', 'Leather Cap', 'head', 'leather', NULL, 0, 0, 2, 0, NULL, 1, 4, 'A soft cap that turns a glancing blow.'),
('iron_helm', 'Iron Helm', 'head', 'mail', NULL, 0, 0, 5, 1, NULL, 5, 35, 'An iron helm with a dented nasal.'),
('soft_boots', 'Soft Boots', 'feet', 'cloth', NULL, 0, 0, 1, 0, JSON_OBJECT('speed', 1), 1, 4, 'Quiet boots for a long road.'),
('leather_gloves', 'Leather Gloves', 'hands', 'leather', NULL, 0, 0, 1, 0, JSON_OBJECT('crit_pct', 1), 2, 8, 'Gloves that keep a grip.'),
('copper_ring', 'Copper Ring', 'ring', 'jewelry', NULL, 0, 0, 0, 0, JSON_OBJECT('luk', 2), 1, 10, 'A cheap ring that still feels lucky.'),
('amulet_of_vigor', 'Amulet of Vigor', 'amulet', 'jewelry', NULL, 0, 0, 0, 0, JSON_OBJECT('vit', 2, 'hp', 10), 5, 40, 'The stone beats like a second heart.');

INSERT INTO affixes
  (code, name, kind, stat_key, slot_groups, min_ilvl, weight, value_min_base, value_max_base, value_per_ilvl)
VALUES
('sturdy', 'Sturdy', 'prefix', 'armor', 'armor,offhand', 1, 100, 1, 3, 0.50),
('heavy', 'Heavy', 'prefix', 'attack', 'weapon', 1, 100, 1, 3, 0.60),
('vital', 'Vital', 'prefix', 'hp', 'armor,offhand,jewelry', 1, 100, 5, 10, 2.00),
('keen', 'Keen', 'prefix', 'crit_pct', 'weapon,jewelry', 2, 70, 1, 3, 0.15),
('swift', 'Swift', 'prefix', 'speed', 'armor,jewelry', 2, 70, 1, 2, 0.15),
('warded', 'Warded', 'prefix', 'mres', 'armor,offhand,jewelry', 1, 80, 1, 3, 0.40),
('brutal', 'Brutal', 'prefix', 'crit_dmg_pct', 'weapon', 5, 50, 5, 10, 1.00),
('of_bear', 'of the Bear', 'suffix', 'str', 'weapon,offhand,armor,jewelry', 1, 100, 1, 2, 0.25),
('of_fox', 'of the Fox', 'suffix', 'dex', 'weapon,offhand,armor,jewelry', 1, 100, 1, 2, 0.25),
('of_owl', 'of the Owl', 'suffix', 'int', 'weapon,offhand,armor,jewelry', 1, 100, 1, 2, 0.25),
('of_ox', 'of the Ox', 'suffix', 'vit', 'weapon,offhand,armor,jewelry', 1, 100, 1, 2, 0.25),
('of_fortune', 'of Fortune', 'suffix', 'luk', 'armor,jewelry', 1, 70, 1, 3, 0.25),
('of_evasion', 'of Evasion', 'suffix', 'dodge_pct', 'armor,jewelry', 3, 60, 1, 2, 0.10),
('of_plenty', 'of Plenty', 'suffix', 'gold_find_pct', 'jewelry', 1, 50, 5, 10, 0.50),
('of_seeker', 'of the Seeker', 'suffix', 'magic_find_pct', 'armor,jewelry', 4, 40, 3, 6, 0.30);

-- Loot table entries. Weights are from DESIGN.md §7.5.
INSERT INTO loot_table_entries (loot_table_id, entry_type, item_template_id, weight)
SELECT lt.id, 'nothing', NULL, 10 FROM loot_tables lt WHERE lt.code = 'lt_forest'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'rusty_sword' WHERE lt.code = 'lt_forest'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'worn_dagger' WHERE lt.code = 'lt_forest'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'oak_staff' WHERE lt.code = 'lt_forest'
UNION ALL SELECT lt.id, 'item', it.id, 12 FROM loot_tables lt JOIN item_templates it ON it.code = 'wooden_buckler' WHERE lt.code = 'lt_forest'
UNION ALL SELECT lt.id, 'item', it.id, 12 FROM loot_tables lt JOIN item_templates it ON it.code = 'cloth_tunic' WHERE lt.code = 'lt_forest'
UNION ALL SELECT lt.id, 'item', it.id, 14 FROM loot_tables lt JOIN item_templates it ON it.code = 'leather_cap' WHERE lt.code = 'lt_forest'
UNION ALL SELECT lt.id, 'item', it.id, 14 FROM loot_tables lt JOIN item_templates it ON it.code = 'soft_boots' WHERE lt.code = 'lt_forest'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'leather_gloves' WHERE lt.code = 'lt_forest'
UNION ALL SELECT lt.id, 'item', it.id, 6 FROM loot_tables lt JOIN item_templates it ON it.code = 'copper_ring' WHERE lt.code = 'lt_forest'
UNION ALL SELECT lt.id, 'item', it.id, 2 FROM loot_tables lt JOIN item_templates it ON it.code = 'leather_jerkin' WHERE lt.code = 'lt_forest';

INSERT INTO loot_table_entries (loot_table_id, entry_type, item_template_id, weight)
SELECT lt.id, 'nothing', NULL, 10 FROM loot_tables lt WHERE lt.code = 'lt_mine'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'iron_longsword' WHERE lt.code = 'lt_mine'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'hunters_shortbow' WHERE lt.code = 'lt_mine'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'apprentice_wand' WHERE lt.code = 'lt_mine'
UNION ALL SELECT lt.id, 'item', it.id, 14 FROM loot_tables lt JOIN item_templates it ON it.code = 'leather_jerkin' WHERE lt.code = 'lt_mine'
UNION ALL SELECT lt.id, 'item', it.id, 12 FROM loot_tables lt JOIN item_templates it ON it.code = 'leather_gloves' WHERE lt.code = 'lt_mine'
UNION ALL SELECT lt.id, 'item', it.id, 8 FROM loot_tables lt JOIN item_templates it ON it.code = 'leather_cap' WHERE lt.code = 'lt_mine'
UNION ALL SELECT lt.id, 'item', it.id, 8 FROM loot_tables lt JOIN item_templates it ON it.code = 'iron_helm' WHERE lt.code = 'lt_mine'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'copper_ring' WHERE lt.code = 'lt_mine'
UNION ALL SELECT lt.id, 'item', it.id, 8 FROM loot_tables lt JOIN item_templates it ON it.code = 'wooden_buckler' WHERE lt.code = 'lt_mine'
UNION ALL SELECT lt.id, 'item', it.id, 4 FROM loot_tables lt JOIN item_templates it ON it.code = 'iron_kite_shield' WHERE lt.code = 'lt_mine'
UNION ALL SELECT lt.id, 'item', it.id, 3 FROM loot_tables lt JOIN item_templates it ON it.code = 'amulet_of_vigor' WHERE lt.code = 'lt_mine';

INSERT INTO loot_table_entries (loot_table_id, entry_type, item_template_id, weight)
SELECT lt.id, 'nothing', NULL, 8 FROM loot_tables lt WHERE lt.code = 'lt_crypt'
UNION ALL SELECT lt.id, 'item', it.id, 6 FROM loot_tables lt JOIN item_templates it ON it.code = 'iron_longsword' WHERE lt.code = 'lt_crypt'
UNION ALL SELECT lt.id, 'item', it.id, 6 FROM loot_tables lt JOIN item_templates it ON it.code = 'hunters_shortbow' WHERE lt.code = 'lt_crypt'
UNION ALL SELECT lt.id, 'item', it.id, 6 FROM loot_tables lt JOIN item_templates it ON it.code = 'apprentice_wand' WHERE lt.code = 'lt_crypt'
UNION ALL SELECT lt.id, 'item', it.id, 8 FROM loot_tables lt JOIN item_templates it ON it.code = 'war_axe' WHERE lt.code = 'lt_crypt'
UNION ALL SELECT lt.id, 'item', it.id, 8 FROM loot_tables lt JOIN item_templates it ON it.code = 'runed_staff' WHERE lt.code = 'lt_crypt'
UNION ALL SELECT lt.id, 'item', it.id, 3 FROM loot_tables lt JOIN item_templates it ON it.code = 'shadowfang' WHERE lt.code = 'lt_crypt'
UNION ALL SELECT lt.id, 'item', it.id, 12 FROM loot_tables lt JOIN item_templates it ON it.code = 'chainmail' WHERE lt.code = 'lt_crypt'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'iron_kite_shield' WHERE lt.code = 'lt_crypt'
UNION ALL SELECT lt.id, 'item', it.id, 12 FROM loot_tables lt JOIN item_templates it ON it.code = 'iron_helm' WHERE lt.code = 'lt_crypt'
UNION ALL SELECT lt.id, 'item', it.id, 8 FROM loot_tables lt JOIN item_templates it ON it.code = 'amulet_of_vigor' WHERE lt.code = 'lt_crypt'
UNION ALL SELECT lt.id, 'item', it.id, 8 FROM loot_tables lt JOIN item_templates it ON it.code = 'copper_ring' WHERE lt.code = 'lt_crypt';

INSERT INTO loot_table_entries (loot_table_id, entry_type, item_template_id, weight)
SELECT lt.id, 'nothing', NULL, 6 FROM loot_tables lt WHERE lt.code = 'lt_peaks'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'war_axe' WHERE lt.code = 'lt_peaks'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'runed_staff' WHERE lt.code = 'lt_peaks'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'shadowfang' WHERE lt.code = 'lt_peaks'
UNION ALL SELECT lt.id, 'item', it.id, 6 FROM loot_tables lt JOIN item_templates it ON it.code = 'frostbite_blade' WHERE lt.code = 'lt_peaks'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'chainmail' WHERE lt.code = 'lt_peaks'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'iron_kite_shield' WHERE lt.code = 'lt_peaks'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'iron_helm' WHERE lt.code = 'lt_peaks'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'amulet_of_vigor' WHERE lt.code = 'lt_peaks'
UNION ALL SELECT lt.id, 'item', it.id, 6 FROM loot_tables lt JOIN item_templates it ON it.code = 'copper_ring' WHERE lt.code = 'lt_peaks'
UNION ALL SELECT lt.id, 'item', it.id, 2 FROM loot_tables lt JOIN item_templates it ON it.code = 'drakescale_mail' WHERE lt.code = 'lt_peaks';

INSERT INTO loot_table_entries (loot_table_id, entry_type, item_template_id, weight)
SELECT lt.id, 'nothing', NULL, 5 FROM loot_tables lt WHERE lt.code = 'lt_depths'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'frostbite_blade' WHERE lt.code = 'lt_depths'
UNION ALL SELECT lt.id, 'item', it.id, 8 FROM loot_tables lt JOIN item_templates it ON it.code = 'ember_staff' WHERE lt.code = 'lt_depths'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'shadowfang' WHERE lt.code = 'lt_depths'
UNION ALL SELECT lt.id, 'item', it.id, 8 FROM loot_tables lt JOIN item_templates it ON it.code = 'drakescale_mail' WHERE lt.code = 'lt_depths'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'iron_kite_shield' WHERE lt.code = 'lt_depths'
UNION ALL SELECT lt.id, 'item', it.id, 10 FROM loot_tables lt JOIN item_templates it ON it.code = 'amulet_of_vigor' WHERE lt.code = 'lt_depths'
UNION ALL SELECT lt.id, 'item', it.id, 6 FROM loot_tables lt JOIN item_templates it ON it.code = 'chainmail' WHERE lt.code = 'lt_depths'
UNION ALL SELECT lt.id, 'item', it.id, 6 FROM loot_tables lt JOIN item_templates it ON it.code = 'copper_ring' WHERE lt.code = 'lt_depths';

INSERT INTO monsters
  (code, name, level, is_boss, hp, attack, defense, mres, speed, crit_pct, dodge_pct, damage_type, xp, gold_min, gold_max, loot_table_id, drop_chance, description)
SELECT 'giant_rat', 'Giant Rat', 1, 0, 26, 7, 2, 2, 9, 5.0, 5.0, 'physical', 10, 2, 4, lt.id, 0.120, 'A rat the size of a dog, and hungrier.'
FROM loot_tables lt WHERE lt.code = 'lt_forest'
UNION ALL
SELECT 'forest_wolf', 'Forest Wolf', 2, 0, 44, 10, 3, 2, 14, 8.0, 8.0, 'physical', 28, 4, 8, lt.id, 0.150, 'It hunts in pairs when the path is quiet.'
FROM loot_tables lt WHERE lt.code = 'lt_forest'
UNION ALL
SELECT 'goblin_scout', 'Goblin Scout', 3, 0, 56, 12, 5, 3, 13, 6.0, 10.0, 'physical', 52, 6, 12, lt.id, 0.200, 'Small, sharp, and already running.'
FROM loot_tables lt WHERE lt.code = 'lt_forest'
UNION ALL
SELECT 'thornback_boar', 'Thornback Boar', 4, 1, 170, 20, 9, 5, 10, 5.0, 0.0, 'physical', 240, 32, 64, lt.id, 1.000, 'Bristles like spearheads. It does not flee.'
FROM loot_tables lt WHERE lt.code = 'lt_forest'
UNION ALL
SELECT 'cave_bat', 'Cave Bat', 3, 0, 39, 11, 3, 4, 17, 5.0, 20.0, 'physical', 52, 6, 12, lt.id, 0.120, 'A shriek, then teeth, then it is gone.'
FROM loot_tables lt WHERE lt.code = 'lt_mine'
UNION ALL
SELECT 'kobold_miner', 'Kobold Miner', 5, 0, 80, 16, 9, 5, 13, 5.0, 5.0, 'physical', 112, 10, 20, lt.id, 0.180, 'It knows every tunnel and resents visitors.'
FROM loot_tables lt WHERE lt.code = 'lt_mine'
UNION ALL
SELECT 'kobold_foreman', 'Kobold Foreman', 7, 1, 260, 29, 14, 8, 15, 8.0, 5.0, 'physical', 555, 56, 112, lt.id, 1.000, 'The pick on its shoulder is not for mining.'
FROM loot_tables lt WHERE lt.code = 'lt_mine'
UNION ALL
SELECT 'skeleton_warrior', 'Skeleton Warrior', 7, 0, 104, 25, 13, 5, 15, 5.0, 3.0, 'physical', 185, 14, 28, lt.id, 0.150, 'Old armor, older orders.'
FROM loot_tables lt WHERE lt.code = 'lt_crypt'
UNION ALL
SELECT 'crypt_wraith', 'Crypt Wraith', 8, 0, 93, 28, 5, 14, 19, 5.0, 15.0, 'magic', 226, 16, 32, lt.id, 0.180, 'Cold fire in the shape of a person.'
FROM loot_tables lt WHERE lt.code = 'lt_crypt'
UNION ALL
SELECT 'bone_lord', 'Bone Lord', 10, 1, 364, 39, 20, 13, 19, 8.0, 5.0, 'physical', 948, 80, 160, lt.id, 1.000, 'The crypt answers when it raises a hand.'
FROM loot_tables lt WHERE lt.code = 'lt_crypt'
UNION ALL
SELECT 'frost_wolf', 'Frost Wolf', 10, 0, 140, 30, 12, 11, 23, 10.0, 10.0, 'physical', 316, 20, 40, lt.id, 0.150, 'Breath steams even when it is still.'
FROM loot_tables lt WHERE lt.code = 'lt_peaks'
UNION ALL
SELECT 'ice_witch', 'Ice Witch', 11, 0, 122, 39, 9, 18, 21, 6.0, 8.0, 'magic', 365, 22, 44, lt.id, 0.200, 'She prefers the fight to stay at a distance.'
FROM loot_tables lt WHERE lt.code = 'lt_peaks'
UNION ALL
SELECT 'yeti_chieftain', 'Yeti Chieftain', 13, 1, 493, 51, 23, 17, 21, 8.0, 3.0, 'physical', 1407, 104, 208, lt.id, 1.000, 'The peaks go quiet when it walks.'
FROM loot_tables lt WHERE lt.code = 'lt_peaks'
UNION ALL
SELECT 'magma_imp', 'Magma Imp', 12, 0, 131, 40, 11, 18, 25, 6.0, 15.0, 'magic', 416, 24, 48, lt.id, 0.150, 'A cinder with opinions.'
FROM loot_tables lt WHERE lt.code = 'lt_depths'
UNION ALL
SELECT 'obsidian_golem', 'Obsidian Golem', 14, 0, 263, 40, 36, 12, 18, 3.0, 0.0, 'physical', 524, 28, 56, lt.id, 0.200, 'Glass-black stone, and it does not tire.'
FROM loot_tables lt WHERE lt.code = 'lt_depths'
UNION ALL
SELECT 'ember_drake', 'Ember Drake', 16, 1, 636, 63, 30, 24, 26, 10.0, 5.0, 'magic', 1920, 128, 256, lt.id, 1.000, 'The depths'' last landlord.'
FROM loot_tables lt WHERE lt.code = 'lt_depths';

INSERT INTO places
  (region_id, code, name, place_type, min_level, max_level, rec_min_level, rec_max_level, rec_level,
   encounter_min, encounter_max, boss_monster_id, boss_chance, rarity_bonus, gold_mult,
   chest_loot_table_id, map_x, map_y, description)
SELECT r.id, 'town', 'Lanternford', 'rest', 1, NULL, 1, 1, 1, 0, 0, NULL, 0.00, 0.00, 1.00, NULL, 50, 78,
       'A walled town of lanterns, ledgers, and a market that buys almost anything.'
FROM regions r WHERE r.code = 'wilds';

INSERT INTO places
  (region_id, code, name, place_type, min_level, max_level, rec_min_level, rec_max_level, rec_level,
   encounter_min, encounter_max, boss_monster_id, boss_chance, rarity_bonus, gold_mult,
   chest_loot_table_id, map_x, map_y, description)
SELECT r.id, 'greenwood', 'Greenwood Forest', 'forest', 1, 6, 1, 3, 2, 3, 4, m.id, 0.30, 0.00, 1.00, lt.id, 28, 58,
       'First roads out of town. Rats, wolves, and a boar that should wait until you are ready.'
FROM regions r, monsters m, loot_tables lt
WHERE r.code = 'wilds' AND m.code = 'thornback_boar' AND lt.code = 'lt_forest';

INSERT INTO places
  (region_id, code, name, place_type, min_level, max_level, rec_min_level, rec_max_level, rec_level,
   encounter_min, encounter_max, boss_monster_id, boss_chance, rarity_bonus, gold_mult,
   chest_loot_table_id, map_x, map_y, description)
SELECT r.id, 'copper_mine', 'Old Copper Mine', 'mine', 2, 9, 3, 6, 4, 4, 5, m.id, 0.30, 0.05, 1.30, lt.id, 72, 55,
       'Collapsed galleries and kobolds who still punch a clock.'
FROM regions r, monsters m, loot_tables lt
WHERE r.code = 'wilds' AND m.code = 'kobold_foreman' AND lt.code = 'lt_mine';

INSERT INTO places
  (region_id, code, name, place_type, min_level, max_level, rec_min_level, rec_max_level, rec_level,
   encounter_min, encounter_max, boss_monster_id, boss_chance, rarity_bonus, gold_mult,
   chest_loot_table_id, map_x, map_y, description)
SELECT r.id, 'sunken_crypt', 'Sunken Crypt', 'dungeon', 5, NULL, 6, 9, 8, 4, 6, m.id, 0.40, 0.10, 1.10, lt.id, 22, 32,
       'Half the halls are underwater. The dead do not mind.'
FROM regions r, monsters m, loot_tables lt
WHERE r.code = 'wilds' AND m.code = 'bone_lord' AND lt.code = 'lt_crypt';

INSERT INTO places
  (region_id, code, name, place_type, min_level, max_level, rec_min_level, rec_max_level, rec_level,
   encounter_min, encounter_max, boss_monster_id, boss_chance, rarity_bonus, gold_mult,
   chest_loot_table_id, map_x, map_y, description)
SELECT r.id, 'frostfang', 'Frostfang Peaks', 'mountain', 8, NULL, 9, 12, 11, 5, 6, m.id, 0.35, 0.10, 1.00, lt.id, 78, 28,
       'Wind, ice, and witches who like both.'
FROM regions r, monsters m, loot_tables lt
WHERE r.code = 'wilds' AND m.code = 'yeti_chieftain' AND lt.code = 'lt_peaks';

INSERT INTO places
  (region_id, code, name, place_type, min_level, max_level, rec_min_level, rec_max_level, rec_level,
   encounter_min, encounter_max, boss_monster_id, boss_chance, rarity_bonus, gold_mult,
   chest_loot_table_id, map_x, map_y, description)
SELECT r.id, 'obsidian_depths', 'Obsidian Depths', 'cave', 11, NULL, 12, 16, 14, 5, 7, m.id, 0.40, 0.20, 1.20, lt.id, 50, 12,
       'A volcanic vault. The drake at the bottom is not a rumor.'
FROM regions r, monsters m, loot_tables lt
WHERE r.code = 'wilds' AND m.code = 'ember_drake' AND lt.code = 'lt_depths';

INSERT INTO place_monsters (place_id, monster_id, weight, min_group, max_group)
SELECT p.id, m.id, v.weight, v.min_group, v.max_group
FROM (
    SELECT 'greenwood' AS place_code, 'giant_rat' AS monster_code, 50 AS weight, 1 AS min_group, 2 AS max_group
    UNION ALL SELECT 'greenwood', 'forest_wolf', 35, 1, 2
    UNION ALL SELECT 'greenwood', 'goblin_scout', 15, 1, 2
    UNION ALL SELECT 'copper_mine', 'cave_bat', 35, 1, 3
    UNION ALL SELECT 'copper_mine', 'kobold_miner', 50, 1, 2
    UNION ALL SELECT 'copper_mine', 'goblin_scout', 15, 1, 2
    UNION ALL SELECT 'sunken_crypt', 'skeleton_warrior', 45, 1, 2
    UNION ALL SELECT 'sunken_crypt', 'crypt_wraith', 35, 1, 2
    UNION ALL SELECT 'sunken_crypt', 'cave_bat', 20, 2, 3
    UNION ALL SELECT 'frostfang', 'frost_wolf', 50, 1, 3
    UNION ALL SELECT 'frostfang', 'ice_witch', 35, 1, 1
    UNION ALL SELECT 'frostfang', 'skeleton_warrior', 15, 2, 3
    UNION ALL SELECT 'obsidian_depths', 'magma_imp', 45, 1, 3
    UNION ALL SELECT 'obsidian_depths', 'obsidian_golem', 40, 1, 2
    UNION ALL SELECT 'obsidian_depths', 'ice_witch', 15, 1, 2
) v
JOIN places p ON p.code = v.place_code
JOIN monsters m ON m.code = v.monster_code;
