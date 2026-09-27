-- The Salt Marches and The Ashen Crown. DESIGN.md §7.1. Safe to run once; skips if brinefen exists.

INSERT INTO regions (code, name, blurb, sort_order) VALUES
('wilds', 'The Wilds', 'Lanternford and the roads that leave it.', 1),
('salt_marches', 'The Salt Marches', 'Drowned country past the peaks. Salt, glass, and a court under the water.', 2),
('ashen_crown', 'The Ashen Crown', 'The last range. Cinders, a dead citadel, and the crown that still burns.', 3)
ON DUPLICATE KEY UPDATE name = VALUES(name), blurb = VALUES(blurb), sort_order = VALUES(sort_order);

UPDATE places p
JOIN regions r ON r.code = 'wilds'
SET p.region_id = r.id
WHERE p.region_id IS NULL;

INSERT INTO loot_tables (code, name, description)
SELECT * FROM (
    SELECT 'lt_brinefen' AS code, 'Fen Spoils' AS name, 'Gear pulled out of the brine.' AS description
    UNION ALL SELECT 'lt_cove', 'Cove Salvage', 'What the tide gives back.'
    UNION ALL SELECT 'lt_tidevault', 'Vault Relics', 'Offerings left in the Tidevault.'
    UNION ALL SELECT 'lt_spire', 'Spire Finds', 'Glass and steel from the salt spire.'
    UNION ALL SELECT 'lt_court', 'Court Treasures', 'Regalia of the sunken court.'
    UNION ALL SELECT 'lt_wastes', 'Cinder Scrap', 'Arms cooled in the ash.'
    UNION ALL SELECT 'lt_citadel', 'Citadel Relics', 'What the fallen keep still holds.'
    UNION ALL SELECT 'lt_monastery', 'Monastery Relics', 'Vestments that never stopped burning.'
    UNION ALL SELECT 'lt_stair', 'Stair Finds', 'Gear left on the black steps.'
    UNION ALL SELECT 'lt_crown', 'Crown Relics', 'The last vault under the embers.'
) v
WHERE NOT EXISTS (SELECT 1 FROM places WHERE code = 'brinefen');

INSERT INTO item_templates
  (code, name, slot, item_type, scaling_stat, two_handed, base_power, base_armor, base_mres, implicit_mods, req_level, base_value, description)
SELECT * FROM (
    SELECT 'tide_cutlass' AS code, 'Tide Cutlass' AS name, 'weapon' AS slot, 'sword' AS item_type, 'str' AS scaling_stat, 0 AS two_handed, 26 AS base_power, 0 AS base_armor, 0 AS base_mres, NULL AS implicit_mods, 16 AS req_level, 240 AS base_value, 'A curved blade that still smells of the sea.' AS description
    UNION ALL SELECT 'reef_bow', 'Reef Bow', 'weapon', 'bow', 'dex', 1, 32, 0, 0, JSON_OBJECT('speed', 2), 18, 320, 'Salt has eaten the limbs, not the draw.'
    UNION ALL SELECT 'saltglass_wand', 'Saltglass Wand', 'weapon', 'wand', 'int', 0, 30, 0, 4, NULL, 18, 320, 'A wand of cloudy glass, warm at the tip.'
    UNION ALL SELECT 'siren_blade', 'Siren Blade', 'weapon', 'dagger', 'dex', 0, 36, 0, 0, JSON_OBJECT('crit_pct', 6), 21, 480, 'The edge hums when blood is near.'
    UNION ALL SELECT 'ashen_greataxe', 'Ashen Greataxe', 'weapon', 'axe', 'str', 1, 48, 0, 0, JSON_OBJECT('crit_dmg_pct', 12), 24, 700, 'The haft is charred. The edge is not.'
    UNION ALL SELECT 'crown_staff', 'Crown Staff', 'weapon', 'staff', 'int', 1, 56, 0, 8, JSON_OBJECT('int', 4), 28, 980, 'A staff crowned with a coal that will not die.'
    UNION ALL SELECT 'brine_hauberk', 'Brine Hauberk', 'chest', 'mail', NULL, 0, 0, 22, 6, JSON_OBJECT('speed', -1), 17, 300, 'Mail rinsed in salt until it stopped rusting.'
    UNION ALL SELECT 'spire_plate', 'Spire Plate', 'chest', 'mail', NULL, 0, 0, 28, 10, NULL, 22, 520, 'Plates of glass-hard steel from the spire.'
    UNION ALL SELECT 'sovereign_plate', 'Sovereign Plate', 'chest', 'mail', NULL, 0, 0, 36, 14, NULL, 28, 900, 'Black plate that still holds a little heat.'
    UNION ALL SELECT 'tide_helm', 'Tide Helm', 'head', 'mail', NULL, 0, 0, 8, 2, NULL, 16, 180, 'A helm crusted with salt and barnacle.'
    UNION ALL SELECT 'ash_helm', 'Ash Helm', 'head', 'mail', NULL, 0, 0, 12, 4, NULL, 24, 420, 'The visor never quite cools.'
    UNION ALL SELECT 'brine_kite', 'Brine Kite', 'offhand', 'shield', NULL, 0, 0, 12, 4, NULL, 16, 200, 'A kite shield dragged off a wreck.'
    UNION ALL SELECT 'crown_aegis', 'Crown Aegis', 'offhand', 'shield', NULL, 0, 0, 18, 8, NULL, 26, 640, 'A shield that drinks a spell and stays warm.'
    UNION ALL SELECT 'marsh_boots', 'Marsh Boots', 'feet', 'leather', NULL, 0, 0, 3, 0, JSON_OBJECT('speed', 2), 16, 140, 'Boots that do not fill with water.'
    UNION ALL SELECT 'cinder_gauntlets', 'Cinder Gauntlets', 'hands', 'mail', NULL, 0, 0, 4, 2, JSON_OBJECT('crit_pct', 3), 22, 280, 'Gauntlets for hands that expect fire.'
    UNION ALL SELECT 'pearl_ring', 'Pearl Ring', 'ring', 'jewelry', NULL, 0, 0, 0, 0, JSON_OBJECT('luk', 3), 16, 200, 'A dull pearl that still feels lucky.'
    UNION ALL SELECT 'sovereign_amulet', 'Sovereign Amulet', 'amulet', 'jewelry', NULL, 0, 0, 0, 0, JSON_OBJECT('vit', 4, 'hp', 30), 26, 560, 'It beats slower than a heart, and hotter.'
) v
WHERE NOT EXISTS (SELECT 1 FROM places WHERE code = 'brinefen');

INSERT INTO loot_table_entries (loot_table_id, entry_type, item_template_id, weight)
SELECT lt.id, v.entry_type, it.id, v.weight
FROM (
    SELECT 'lt_brinefen' AS table_code, 'nothing' AS entry_type, NULL AS item_code, 6 AS weight
    UNION ALL SELECT 'lt_brinefen', 'item', 'tide_cutlass', 10
    UNION ALL SELECT 'lt_brinefen', 'item', 'brine_kite', 8
    UNION ALL SELECT 'lt_brinefen', 'item', 'tide_helm', 10
    UNION ALL SELECT 'lt_brinefen', 'item', 'brine_hauberk', 8
    UNION ALL SELECT 'lt_brinefen', 'item', 'marsh_boots', 10
    UNION ALL SELECT 'lt_brinefen', 'item', 'pearl_ring', 6
    UNION ALL SELECT 'lt_brinefen', 'item', 'frostbite_blade', 6
    UNION ALL SELECT 'lt_brinefen', 'item', 'drakescale_mail', 4
    UNION ALL SELECT 'lt_cove', 'nothing', NULL, 6
    UNION ALL SELECT 'lt_cove', 'item', 'tide_cutlass', 8
    UNION ALL SELECT 'lt_cove', 'item', 'reef_bow', 8
    UNION ALL SELECT 'lt_cove', 'item', 'saltglass_wand', 8
    UNION ALL SELECT 'lt_cove', 'item', 'brine_hauberk', 10
    UNION ALL SELECT 'lt_cove', 'item', 'brine_kite', 8
    UNION ALL SELECT 'lt_cove', 'item', 'pearl_ring', 8
    UNION ALL SELECT 'lt_cove', 'item', 'marsh_boots', 6
    UNION ALL SELECT 'lt_cove', 'item', 'ember_staff', 4
    UNION ALL SELECT 'lt_tidevault', 'nothing', NULL, 5
    UNION ALL SELECT 'lt_tidevault', 'item', 'reef_bow', 8
    UNION ALL SELECT 'lt_tidevault', 'item', 'saltglass_wand', 8
    UNION ALL SELECT 'lt_tidevault', 'item', 'siren_blade', 6
    UNION ALL SELECT 'lt_tidevault', 'item', 'spire_plate', 4
    UNION ALL SELECT 'lt_tidevault', 'item', 'brine_hauberk', 8
    UNION ALL SELECT 'lt_tidevault', 'item', 'cinder_gauntlets', 4
    UNION ALL SELECT 'lt_tidevault', 'item', 'pearl_ring', 8
    UNION ALL SELECT 'lt_tidevault', 'item', 'tide_helm', 6
    UNION ALL SELECT 'lt_spire', 'nothing', NULL, 5
    UNION ALL SELECT 'lt_spire', 'item', 'siren_blade', 10
    UNION ALL SELECT 'lt_spire', 'item', 'saltglass_wand', 8
    UNION ALL SELECT 'lt_spire', 'item', 'spire_plate', 8
    UNION ALL SELECT 'lt_spire', 'item', 'cinder_gauntlets', 8
    UNION ALL SELECT 'lt_spire', 'item', 'ashen_greataxe', 4
    UNION ALL SELECT 'lt_spire', 'item', 'crown_aegis', 3
    UNION ALL SELECT 'lt_spire', 'item', 'pearl_ring', 6
    UNION ALL SELECT 'lt_spire', 'item', 'ash_helm', 4
    UNION ALL SELECT 'lt_court', 'nothing', NULL, 4
    UNION ALL SELECT 'lt_court', 'item', 'siren_blade', 10
    UNION ALL SELECT 'lt_court', 'item', 'ashen_greataxe', 8
    UNION ALL SELECT 'lt_court', 'item', 'spire_plate', 10
    UNION ALL SELECT 'lt_court', 'item', 'crown_aegis', 6
    UNION ALL SELECT 'lt_court', 'item', 'sovereign_amulet', 4
    UNION ALL SELECT 'lt_court', 'item', 'ash_helm', 6
    UNION ALL SELECT 'lt_court', 'item', 'pearl_ring', 6
    UNION ALL SELECT 'lt_wastes', 'nothing', NULL, 5
    UNION ALL SELECT 'lt_wastes', 'item', 'ashen_greataxe', 10
    UNION ALL SELECT 'lt_wastes', 'item', 'spire_plate', 8
    UNION ALL SELECT 'lt_wastes', 'item', 'ash_helm', 10
    UNION ALL SELECT 'lt_wastes', 'item', 'cinder_gauntlets', 8
    UNION ALL SELECT 'lt_wastes', 'item', 'crown_aegis', 6
    UNION ALL SELECT 'lt_wastes', 'item', 'sovereign_amulet', 4
    UNION ALL SELECT 'lt_wastes', 'item', 'siren_blade', 6
    UNION ALL SELECT 'lt_citadel', 'nothing', NULL, 5
    UNION ALL SELECT 'lt_citadel', 'item', 'ashen_greataxe', 8
    UNION ALL SELECT 'lt_citadel', 'item', 'crown_staff', 6
    UNION ALL SELECT 'lt_citadel', 'item', 'sovereign_plate', 6
    UNION ALL SELECT 'lt_citadel', 'item', 'crown_aegis', 8
    UNION ALL SELECT 'lt_citadel', 'item', 'ash_helm', 8
    UNION ALL SELECT 'lt_citadel', 'item', 'sovereign_amulet', 6
    UNION ALL SELECT 'lt_citadel', 'item', 'cinder_gauntlets', 6
    UNION ALL SELECT 'lt_monastery', 'nothing', NULL, 4
    UNION ALL SELECT 'lt_monastery', 'item', 'crown_staff', 10
    UNION ALL SELECT 'lt_monastery', 'item', 'sovereign_plate', 8
    UNION ALL SELECT 'lt_monastery', 'item', 'sovereign_amulet', 8
    UNION ALL SELECT 'lt_monastery', 'item', 'crown_aegis', 8
    UNION ALL SELECT 'lt_monastery', 'item', 'ashen_greataxe', 6
    UNION ALL SELECT 'lt_monastery', 'item', 'ash_helm', 6
    UNION ALL SELECT 'lt_stair', 'nothing', NULL, 4
    UNION ALL SELECT 'lt_stair', 'item', 'crown_staff', 8
    UNION ALL SELECT 'lt_stair', 'item', 'sovereign_plate', 10
    UNION ALL SELECT 'lt_stair', 'item', 'crown_aegis', 8
    UNION ALL SELECT 'lt_stair', 'item', 'sovereign_amulet', 8
    UNION ALL SELECT 'lt_stair', 'item', 'ashen_greataxe', 6
    UNION ALL SELECT 'lt_stair', 'item', 'ash_helm', 6
    UNION ALL SELECT 'lt_crown', 'nothing', NULL, 4
    UNION ALL SELECT 'lt_crown', 'item', 'crown_staff', 12
    UNION ALL SELECT 'lt_crown', 'item', 'sovereign_plate', 12
    UNION ALL SELECT 'lt_crown', 'item', 'crown_aegis', 10
    UNION ALL SELECT 'lt_crown', 'item', 'sovereign_amulet', 10
    UNION ALL SELECT 'lt_crown', 'item', 'ashen_greataxe', 6
) v
JOIN loot_tables lt ON lt.code = v.table_code
LEFT JOIN item_templates it ON it.code = v.item_code
WHERE NOT EXISTS (SELECT 1 FROM places WHERE code = 'brinefen');

INSERT INTO monsters
  (code, name, level, is_boss, hp, attack, defense, mres, speed, crit_pct, dodge_pct, damage_type, xp, gold_min, gold_max, loot_table_id, drop_chance, description)
SELECT v.code, v.name, v.level, v.is_boss, v.hp, v.attack, v.defense, v.mres, v.speed, v.crit_pct, v.dodge_pct, v.damage_type, v.xp, v.gold_min, v.gold_max, lt.id, v.drop_chance, v.description
FROM (
    SELECT 'marsh_leech' AS code, 'Marsh Leech' AS name, 14 AS level, 0 AS is_boss, 160 AS hp, 42 AS attack, 15 AS defense, 14 AS mres, 29 AS speed, 8.0 AS crit_pct, 15.0 AS dodge_pct, 'physical' AS damage_type, 524 AS xp, 28 AS gold_min, 56 AS gold_max, 'lt_brinefen' AS loot, 0.150 AS drop_chance, 'It drops from the reeds and does not let go.' AS description
    UNION ALL SELECT 'fen_lurker', 'Fen Lurker', 15, 0, 200, 43, 21, 14, 25, 5.0, 5.0, 'physical', 581, 30, 60, 'lt_brinefen', 0.160, 'Eyes just above the water, then a lunge.'
    UNION ALL SELECT 'brine_hag', 'Brine Hag', 18, 1, 673, 71, 27, 28, 29, 10.0, 8.0, 'magic', 2292, 144, 288, 'lt_brinefen', 1.000, 'She seasons her curses with salt.'
    UNION ALL SELECT 'salt_wretch', 'Salt Wretch', 16, 0, 212, 45, 22, 15, 26, 5.0, 5.0, 'physical', 640, 32, 64, 'lt_cove', 0.160, 'A sailor who never made the beach.'
    UNION ALL SELECT 'tide_reaver', 'Tide Reaver', 17, 0, 190, 50, 18, 17, 32, 8.0, 12.0, 'physical', 701, 34, 68, 'lt_cove', 0.170, 'Fast on the wet stone, and it knows it.'
    UNION ALL SELECT 'drowned_captain', 'Drowned Captain', 19, 1, 744, 71, 35, 24, 29, 8.0, 5.0, 'physical', 2484, 152, 304, 'lt_cove', 1.000, 'The coat is rotten. The cutlass is not.'
    UNION ALL SELECT 'vault_eel', 'Vault Eel', 18, 0, 201, 53, 19, 18, 33, 6.0, 15.0, 'physical', 764, 36, 72, 'lt_tidevault', 0.150, 'It lives in the flooded vault and resents light.'
    UNION ALL SELECT 'tide_priest', 'Tide Priest', 20, 0, 208, 63, 16, 29, 32, 6.0, 8.0, 'magic', 894, 40, 80, 'lt_tidevault', 0.180, 'It still sings the old tide-prayers.'
    UNION ALL SELECT 'tide_warden', 'Tide Warden', 22, 1, 852, 81, 40, 28, 32, 8.0, 5.0, 'physical', 3096, 176, 352, 'lt_tidevault', 1.000, 'The vault door was never the hard part.'
    UNION ALL SELECT 'glass_shardling', 'Glass Shardling', 21, 0, 218, 66, 17, 31, 33, 6.0, 10.0, 'magic', 962, 42, 84, 'lt_spire', 0.160, 'A person-shape made of singing glass.'
    UNION ALL SELECT 'spire_sentinel', 'Spire Sentinel', 22, 0, 398, 53, 44, 18, 29, 3.0, 0.0, 'physical', 1032, 44, 88, 'lt_spire', 0.180, 'Salt-glass armor, and it does not blink.'
    UNION ALL SELECT 'glass_siren', 'Glass Siren', 24, 1, 878, 92, 36, 36, 35, 10.0, 8.0, 'magic', 3528, 192, 384, 'lt_spire', 1.000, 'Her voice cracks stone. Then she does.'
    UNION ALL SELECT 'court_guard', 'Court Guard', 23, 0, 296, 63, 31, 22, 33, 5.0, 5.0, 'physical', 1103, 46, 92, 'lt_court', 0.160, 'Coral plate, and a spear that still remembers drill.'
    UNION ALL SELECT 'brine_oracle', 'Brine Oracle', 24, 0, 246, 75, 19, 35, 36, 6.0, 8.0, 'magic', 1176, 48, 96, 'lt_court', 0.180, 'It speaks in bubbles and means every one.'
    UNION ALL SELECT 'queen_of_brine', 'Queen of Brine', 26, 1, 946, 99, 39, 39, 37, 10.0, 8.0, 'magic', 3978, 208, 416, 'lt_court', 1.000, 'The court kneels. Visitors do not get the chance.'
    UNION ALL SELECT 'ash_hound', 'Ash Hound', 24, 0, 262, 68, 25, 24, 39, 8.0, 12.0, 'physical', 1176, 48, 96, 'lt_wastes', 0.160, 'It hunts by the heat you give off.'
    UNION ALL SELECT 'cinder_cultist', 'Cinder Cultist', 25, 0, 256, 78, 20, 36, 37, 6.0, 8.0, 'magic', 1250, 50, 100, 'lt_wastes', 0.180, 'Robes of grey ash, hands of live coal.'
    UNION ALL SELECT 'cinder_matron', 'Cinder Matron', 27, 1, 980, 103, 40, 41, 38, 10.0, 8.0, 'magic', 4209, 216, 432, 'lt_wastes', 1.000, 'The wastes answer when she calls.'
    UNION ALL SELECT 'ash_knight', 'Ash Knight', 26, 0, 465, 62, 52, 22, 33, 3.0, 0.0, 'physical', 1326, 52, 104, 'lt_citadel', 0.170, 'Plate fused shut. It still salutes.'
    UNION ALL SELECT 'citadel_wraith', 'Citadel Wraith', 26, 0, 266, 81, 21, 38, 38, 6.0, 12.0, 'magic', 1326, 52, 104, 'lt_citadel', 0.180, 'A banner-bearer who burned with the keep.'
    UNION ALL SELECT 'castellan_of_ash', 'Castellan of Ash', 28, 1, 1068, 101, 50, 35, 38, 8.0, 5.0, 'physical', 4446, 224, 448, 'lt_citadel', 1.000, 'The citadel''s last order was hold.'
    UNION ALL SELECT 'cinder_monk', 'Cinder Monk', 27, 0, 344, 73, 37, 25, 37, 5.0, 8.0, 'physical', 1403, 54, 108, 'lt_monastery', 0.160, 'Bare fists, and they are enough.'
    UNION ALL SELECT 'ash_cantor', 'Ash Cantor', 27, 0, 275, 83, 22, 39, 39, 6.0, 8.0, 'magic', 1403, 54, 108, 'lt_monastery', 0.180, 'The hymn is fire. Standing in it is the mistake.'
    UNION ALL SELECT 'abbot_of_cinders', 'Abbot of Cinders', 29, 1, 1049, 110, 43, 44, 40, 10.0, 8.0, 'magic', 4686, 232, 464, 'lt_monastery', 1.000, 'He kept the vigil. The vigil kept him.'
    UNION ALL SELECT 'stair_gargoyle', 'Stair Gargoyle', 28, 0, 498, 66, 55, 23, 35, 3.0, 0.0, 'physical', 1482, 56, 112, 'lt_stair', 0.170, 'It was decoration until you reached its step.'
    UNION ALL SELECT 'ember_acolyte', 'Ember Acolyte', 28, 0, 285, 86, 22, 41, 40, 6.0, 8.0, 'magic', 1482, 56, 112, 'lt_stair', 0.180, 'Every landing has one, and they share a fire.'
    UNION ALL SELECT 'stair_warden', 'Stair Warden', 30, 1, 1140, 108, 54, 37, 40, 8.0, 5.0, 'physical', 4929, 240, 480, 'lt_stair', 1.000, 'Nothing climbs past it without paying.'
    UNION ALL SELECT 'sovereign_guard', 'Sovereign Guard', 29, 0, 368, 78, 39, 27, 39, 5.0, 5.0, 'physical', 1562, 58, 116, 'lt_crown', 0.170, 'The crown''s household, still on duty.'
    UNION ALL SELECT 'crown_phoenix', 'Crown Phoenix', 29, 0, 294, 89, 23, 42, 41, 8.0, 12.0, 'magic', 1562, 58, 116, 'lt_crown', 0.180, 'A bird of coal and verdict.'
    UNION ALL SELECT 'ashen_sovereign', 'Ashen Sovereign', 30, 1, 1083, 114, 44, 45, 41, 10.0, 8.0, 'magic', 4929, 240, 480, 'lt_crown', 1.000, 'The last landlord of the crown. It does not share.'
) v
JOIN loot_tables lt ON lt.code = v.loot
WHERE NOT EXISTS (SELECT 1 FROM places WHERE code = 'brinefen');

INSERT INTO places
  (region_id, code, name, place_type, min_level, max_level, rec_min_level, rec_max_level, rec_level,
   encounter_min, encounter_max, boss_monster_id, boss_chance, rarity_bonus, gold_mult,
   chest_loot_table_id, map_x, map_y, description)
SELECT r.id, v.code, v.name, v.place_type, v.min_level, NULL, v.rec_min, v.rec_max, v.rec_level,
       v.enc_min, v.enc_max, m.id, v.boss_chance, v.rarity_bonus, v.gold_mult,
       lt.id, v.map_x, v.map_y, v.description
FROM (
    SELECT 'salt_marches' AS region_code, 'brinefen' AS code, 'Brinefen' AS name, 'marsh' AS place_type, 13 AS min_level, 14 AS rec_min, 17 AS rec_max, 15 AS rec_level, 5 AS enc_min, 6 AS enc_max, 'brine_hag' AS boss, 0.35 AS boss_chance, 0.12 AS rarity_bonus, 1.10 AS gold_mult, 'lt_brinefen' AS chest, 24 AS map_x, 68 AS map_y, 'Reeds, black water, and a hag who charges rent in blood.' AS description
    UNION ALL SELECT 'salt_marches', 'wreckers_cove', 'Wrecker''s Cove', 'coast', 15, 16, 19, 17, 5, 7, 'drowned_captain', 0.35, 0.15, 1.25, 'lt_cove', 74, 64, 'A beach of broken ships. The crew is still aboard.'
    UNION ALL SELECT 'salt_marches', 'tidevault', 'Tidevault', 'dungeon', 17, 18, 21, 19, 5, 7, 'tide_warden', 0.40, 0.18, 1.15, 'lt_tidevault', 18, 38, 'A temple the tide fills twice a day. The priests stayed.'
    UNION ALL SELECT 'salt_marches', 'saltglass_spire', 'Saltglass Spire', 'ruins', 19, 20, 23, 21, 6, 7, 'glass_siren', 0.40, 0.20, 1.20, 'lt_spire', 78, 28, 'A tower of fused salt and glass. It sings in a high wind.'
    UNION ALL SELECT 'salt_marches', 'sunken_court', 'The Sunken Court', 'dungeon', 21, 22, 25, 23, 6, 8, 'queen_of_brine', 0.45, 0.25, 1.40, 'lt_court', 46, 14, 'A palace under the water. The queen still holds court.'
    UNION ALL SELECT 'ashen_crown', 'cinder_wastes', 'Cinder Wastes', 'cave', 23, 24, 26, 25, 5, 7, 'cinder_matron', 0.35, 0.20, 1.15, 'lt_wastes', 22, 70, 'Ash to the knee, and hounds that hunt by heat.'
    UNION ALL SELECT 'ashen_crown', 'fallen_citadel', 'Fallen Citadel', 'ruins', 24, 25, 27, 26, 6, 7, 'castellan_of_ash', 0.40, 0.22, 1.25, 'lt_citadel', 76, 62, 'A keep that burned from the inside and kept its garrison.'
    UNION ALL SELECT 'ashen_crown', 'ash_monastery', 'Ash Monastery', 'temple', 26, 26, 28, 27, 6, 8, 'abbot_of_cinders', 0.40, 0.25, 1.20, 'lt_monastery', 20, 36, 'Monks who took a vow of fire and kept it.'
    UNION ALL SELECT 'ashen_crown', 'black_stair', 'The Black Stair', 'mountain', 27, 27, 29, 28, 6, 8, 'stair_warden', 0.40, 0.25, 1.30, 'lt_stair', 74, 26, 'A stair cut into the mountain. Every landing is guarded.'
    UNION ALL SELECT 'ashen_crown', 'crown_of_embers', 'Crown of Embers', 'cave', 28, 29, 30, 30, 7, 8, 'ashen_sovereign', 0.45, 0.30, 1.50, 'lt_crown', 48, 12, 'The last vault. The sovereign does not retire.'
) v
JOIN regions r ON r.code = v.region_code
JOIN monsters m ON m.code = v.boss
JOIN loot_tables lt ON lt.code = v.chest
WHERE NOT EXISTS (SELECT 1 FROM places WHERE code = 'brinefen');

INSERT INTO place_monsters (place_id, monster_id, weight, min_group, max_group)
SELECT p.id, m.id, v.weight, v.min_group, v.max_group
FROM (
    SELECT 'brinefen' AS place_code, 'marsh_leech' AS monster_code, 50 AS weight, 1 AS min_group, 3 AS max_group
    UNION ALL SELECT 'brinefen', 'fen_lurker', 35, 1, 2
    UNION ALL SELECT 'brinefen', 'salt_wretch', 15, 1, 2
    UNION ALL SELECT 'wreckers_cove', 'salt_wretch', 40, 1, 2
    UNION ALL SELECT 'wreckers_cove', 'tide_reaver', 40, 1, 2
    UNION ALL SELECT 'wreckers_cove', 'vault_eel', 20, 1, 2
    UNION ALL SELECT 'tidevault', 'vault_eel', 35, 1, 3
    UNION ALL SELECT 'tidevault', 'tide_priest', 45, 1, 2
    UNION ALL SELECT 'tidevault', 'glass_shardling', 20, 1, 2
    UNION ALL SELECT 'saltglass_spire', 'glass_shardling', 40, 1, 3
    UNION ALL SELECT 'saltglass_spire', 'spire_sentinel', 40, 1, 1
    UNION ALL SELECT 'saltglass_spire', 'court_guard', 20, 1, 2
    UNION ALL SELECT 'sunken_court', 'spire_sentinel', 20, 1, 1
    UNION ALL SELECT 'sunken_court', 'court_guard', 45, 1, 2
    UNION ALL SELECT 'sunken_court', 'brine_oracle', 35, 1, 2
    UNION ALL SELECT 'cinder_wastes', 'ash_hound', 50, 1, 3
    UNION ALL SELECT 'cinder_wastes', 'cinder_cultist', 35, 1, 2
    UNION ALL SELECT 'cinder_wastes', 'citadel_wraith', 15, 1, 2
    UNION ALL SELECT 'fallen_citadel', 'cinder_cultist', 25, 1, 2
    UNION ALL SELECT 'fallen_citadel', 'ash_knight', 45, 1, 2
    UNION ALL SELECT 'fallen_citadel', 'cinder_monk', 30, 1, 2
    UNION ALL SELECT 'ash_monastery', 'cinder_monk', 40, 1, 2
    UNION ALL SELECT 'ash_monastery', 'ash_cantor', 40, 1, 2
    UNION ALL SELECT 'ash_monastery', 'ember_acolyte', 20, 1, 2
    UNION ALL SELECT 'black_stair', 'stair_gargoyle', 40, 1, 1
    UNION ALL SELECT 'black_stair', 'ember_acolyte', 35, 1, 2
    UNION ALL SELECT 'black_stair', 'sovereign_guard', 25, 1, 2
    UNION ALL SELECT 'crown_of_embers', 'sovereign_guard', 55, 1, 2
    UNION ALL SELECT 'crown_of_embers', 'crown_phoenix', 45, 1, 2
) v
JOIN places p ON p.code = v.place_code
JOIN monsters m ON m.code = v.monster_code
WHERE NOT EXISTS (
    SELECT 1 FROM place_monsters pm
    JOIN places bp ON bp.id = pm.place_id
    WHERE bp.code = 'brinefen'
);
