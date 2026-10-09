-- Legendary titles (DESIGN.md §6.4). Safe to run more than once.

INSERT INTO legendary_names
  (code, kind, name, item_type, slot, stat_key, placement, min_ilvl, weight)
SELECT v.code, v.kind, v.name, v.item_type, v.slot, v.stat_key, v.placement, v.min_ilvl, v.weight
FROM (
    SELECT 'base_sword_edge' AS code, 'base' AS kind, 'Edge' AS name, 'sword' AS item_type, 'weapon' AS slot, CAST(NULL AS CHAR(20)) AS stat_key, CAST(NULL AS CHAR(8)) AS placement, 1 AS min_ilvl, 100 AS weight
    UNION ALL SELECT 'base_sword_blade', 'base', 'Blade', 'sword', 'weapon', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_sword_falchion', 'base', 'Falchion', 'sword', 'weapon', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_dagger_fang', 'base', 'Fang', 'dagger', 'weapon', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_dagger_kris', 'base', 'Kris', 'dagger', 'weapon', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_dagger_shiv', 'base', 'Shiv', 'dagger', 'weapon', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_staff_spire', 'base', 'Spire', 'staff', 'weapon', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_staff_crook', 'base', 'Crook', 'staff', 'weapon', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_staff_staff', 'base', 'Staff', 'staff', 'weapon', NULL, NULL, 1, 60
    UNION ALL SELECT 'base_bow_bow', 'base', 'Bow', 'bow', 'weapon', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_bow_recurve', 'base', 'Recurve', 'bow', 'weapon', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_bow_yew', 'base', 'Yew', 'bow', 'weapon', NULL, NULL, 1, 60
    UNION ALL SELECT 'base_wand_wand', 'base', 'Wand', 'wand', 'weapon', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_wand_scepter', 'base', 'Scepter', 'wand', 'weapon', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_wand_rod', 'base', 'Rod', 'wand', 'weapon', NULL, NULL, 1, 60
    UNION ALL SELECT 'base_axe_cleaver', 'base', 'Cleaver', 'axe', 'weapon', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_axe_hewer', 'base', 'Hewer', 'axe', 'weapon', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_axe_reaver', 'base', 'Reaver', 'axe', 'weapon', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_shield_aegis', 'base', 'Aegis', 'shield', 'offhand', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_shield_bulwark', 'base', 'Bulwark', 'shield', 'offhand', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_shield_ward', 'base', 'Ward', 'shield', 'offhand', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_chest_cloth_vest', 'base', 'Vest', 'cloth', 'chest', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_chest_cloth_robe', 'base', 'Robe', 'cloth', 'chest', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_chest_leather_jerkin', 'base', 'Jerkin', 'leather', 'chest', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_chest_leather_hide', 'base', 'Hide', 'leather', 'chest', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_chest_mail_hauberk', 'base', 'Hauberk', 'mail', 'chest', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_chest_mail_plate', 'base', 'Plate', 'mail', 'chest', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_chest_mail_cuirass', 'base', 'Cuirass', 'mail', 'chest', NULL, NULL, 1, 60
    UNION ALL SELECT 'base_head_leather_cowl', 'base', 'Cowl', 'leather', 'head', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_head_leather_cap', 'base', 'Cap', 'leather', 'head', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_head_mail_helm', 'base', 'Helm', 'mail', 'head', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_head_mail_crown', 'base', 'Crown', 'mail', 'head', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_head_mail_visor', 'base', 'Visor', 'mail', 'head', NULL, NULL, 1, 60
    UNION ALL SELECT 'base_feet_cloth_slippers', 'base', 'Slippers', 'cloth', 'feet', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_feet_cloth_treads', 'base', 'Treads', 'cloth', 'feet', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_feet_leather_boots', 'base', 'Boots', 'leather', 'feet', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_feet_leather_striders', 'base', 'Striders', 'leather', 'feet', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_hands_leather_gloves', 'base', 'Gloves', 'leather', 'hands', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_hands_leather_grips', 'base', 'Grips', 'leather', 'hands', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_hands_mail_gauntlets', 'base', 'Gauntlets', 'mail', 'hands', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_hands_mail_fists', 'base', 'Fists', 'mail', 'hands', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_ring_band', 'base', 'Band', 'jewelry', 'ring', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_ring_loop', 'base', 'Loop', 'jewelry', 'ring', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_ring_ring', 'base', 'Ring', 'jewelry', 'ring', NULL, NULL, 1, 60
    UNION ALL SELECT 'base_amulet_pendant', 'base', 'Pendant', 'jewelry', 'amulet', NULL, NULL, 1, 100
    UNION ALL SELECT 'base_amulet_torc', 'base', 'Torc', 'jewelry', 'amulet', NULL, NULL, 1, 80
    UNION ALL SELECT 'base_amulet_amulet', 'base', 'Amulet', 'jewelry', 'amulet', NULL, NULL, 1, 60
    UNION ALL SELECT 'uniq_excalibur', 'unique', 'Excalibur', 'sword', 'weapon', NULL, NULL, 12, 100
    UNION ALL SELECT 'uniq_gram', 'unique', 'Gram', 'sword', 'weapon', NULL, NULL, 22, 80
    UNION ALL SELECT 'uniq_carnwennan', 'unique', 'Carnwennan', 'dagger', 'weapon', NULL, NULL, 10, 100
    UNION ALL SELECT 'uniq_ragnarok', 'unique', 'Ragnarok', 'axe', 'weapon', NULL, NULL, 20, 100
    UNION ALL SELECT 'uniq_laevateinn', 'unique', 'Laevateinn', 'staff', 'weapon', NULL, NULL, 18, 100
    UNION ALL SELECT 'uniq_failnaught', 'unique', 'Failnaught', 'bow', 'weapon', NULL, NULL, 16, 100
    UNION ALL SELECT 'uniq_starfall', 'unique', 'Starfall', 'wand', 'weapon', NULL, NULL, 14, 100
    UNION ALL SELECT 'uniq_ancile', 'unique', 'Ancile', 'shield', 'offhand', NULL, NULL, 12, 100
    UNION ALL SELECT 'uniq_tarnhelm', 'unique', 'Tarnhelm', 'mail', 'head', NULL, NULL, 16, 100
    UNION ALL SELECT 'uniq_worldheart', 'unique', 'Worldheart', 'jewelry', 'amulet', NULL, NULL, 18, 100
    UNION ALL SELECT 'uniq_ouroboros', 'unique', 'Ouroboros', 'jewelry', 'ring', NULL, NULL, 16, 100
    UNION ALL SELECT 'epi_hp_leeching', 'epithet', 'Leeching', NULL, NULL, 'hp', 'prefix', 1, 100
    UNION ALL SELECT 'epi_hp_bloodbound', 'epithet', 'Bloodbound', NULL, NULL, 'hp', 'prefix', 1, 60
    UNION ALL SELECT 'epi_hp_leech', 'epithet', 'of the Leech', NULL, NULL, 'hp', 'suffix', 1, 80
    UNION ALL SELECT 'epi_str_titanic', 'epithet', 'Titanic', NULL, NULL, 'str', 'prefix', 1, 100
    UNION ALL SELECT 'epi_str_crushing', 'epithet', 'Crushing', NULL, NULL, 'str', 'prefix', 1, 70
    UNION ALL SELECT 'epi_str_titan', 'epithet', 'of the Titan', NULL, NULL, 'str', 'suffix', 1, 80
    UNION ALL SELECT 'epi_dex_silent', 'epithet', 'Silent', NULL, NULL, 'dex', 'prefix', 1, 100
    UNION ALL SELECT 'epi_dex_precise', 'epithet', 'Precise', NULL, NULL, 'dex', 'prefix', 1, 70
    UNION ALL SELECT 'epi_dex_wind', 'epithet', 'of the Wind', NULL, NULL, 'dex', 'suffix', 1, 80
    UNION ALL SELECT 'epi_int_arcane', 'epithet', 'Arcane', NULL, NULL, 'int', 'prefix', 1, 100
    UNION ALL SELECT 'epi_int_runic', 'epithet', 'Runic', NULL, NULL, 'int', 'prefix', 1, 70
    UNION ALL SELECT 'epi_int_sage', 'epithet', 'of the Sage', NULL, NULL, 'int', 'suffix', 1, 80
    UNION ALL SELECT 'epi_vit_stalwart', 'epithet', 'Stalwart', NULL, NULL, 'vit', 'prefix', 1, 100
    UNION ALL SELECT 'epi_vit_unbroken', 'epithet', 'Unbroken', NULL, NULL, 'vit', 'prefix', 1, 60
    UNION ALL SELECT 'epi_vit_oak', 'epithet', 'of the Oak', NULL, NULL, 'vit', 'suffix', 1, 80
    UNION ALL SELECT 'epi_luk_fortunate', 'epithet', 'Fortunate', NULL, NULL, 'luk', 'prefix', 1, 100
    UNION ALL SELECT 'epi_luk_charmed', 'epithet', 'Charmed', NULL, NULL, 'luk', 'prefix', 1, 70
    UNION ALL SELECT 'epi_luk_fortune', 'epithet', 'of Fortune', NULL, NULL, 'luk', 'suffix', 1, 80
    UNION ALL SELECT 'epi_atk_savage', 'epithet', 'Savage', NULL, NULL, 'attack', 'prefix', 1, 100
    UNION ALL SELECT 'epi_atk_heavy', 'epithet', 'Heavy', NULL, NULL, 'attack', 'prefix', 1, 70
    UNION ALL SELECT 'epi_atk_ruin', 'epithet', 'of Ruin', NULL, NULL, 'attack', 'suffix', 1, 80
    UNION ALL SELECT 'epi_arm_ironclad', 'epithet', 'Ironclad', NULL, NULL, 'armor', 'prefix', 1, 100
    UNION ALL SELECT 'epi_arm_thick', 'epithet', 'Thick', NULL, NULL, 'armor', 'prefix', 1, 70
    UNION ALL SELECT 'epi_arm_wall', 'epithet', 'of the Wall', NULL, NULL, 'armor', 'suffix', 1, 80
    UNION ALL SELECT 'epi_mres_warded', 'epithet', 'Warded', NULL, NULL, 'mres', 'prefix', 1, 100
    UNION ALL SELECT 'epi_mres_spellturned', 'epithet', 'Spellturned', NULL, NULL, 'mres', 'prefix', 1, 50
    UNION ALL SELECT 'epi_mres_ward', 'epithet', 'of the Ward', NULL, NULL, 'mres', 'suffix', 1, 80
    UNION ALL SELECT 'epi_crit_deadly', 'epithet', 'Deadly', NULL, NULL, 'crit_pct', 'prefix', 1, 100
    UNION ALL SELECT 'epi_crit_executing', 'epithet', 'Executing', NULL, NULL, 'crit_pct', 'prefix', 1, 60
    UNION ALL SELECT 'epi_crit_assassin', 'epithet', 'of the Assassin', NULL, NULL, 'crit_pct', 'suffix', 1, 80
    UNION ALL SELECT 'epi_cdmg_brutal', 'epithet', 'Brutal', NULL, NULL, 'crit_dmg_pct', 'prefix', 1, 100
    UNION ALL SELECT 'epi_cdmg_sundering', 'epithet', 'Sundering', NULL, NULL, 'crit_dmg_pct', 'prefix', 1, 60
    UNION ALL SELECT 'epi_cdmg_carnage', 'epithet', 'of Carnage', NULL, NULL, 'crit_dmg_pct', 'suffix', 1, 80
    UNION ALL SELECT 'epi_dodge_evasive', 'epithet', 'Evasive', NULL, NULL, 'dodge_pct', 'prefix', 1, 100
    UNION ALL SELECT 'epi_dodge_ghostly', 'epithet', 'Ghostly', NULL, NULL, 'dodge_pct', 'prefix', 1, 60
    UNION ALL SELECT 'epi_dodge_evasion', 'epithet', 'of Evasion', NULL, NULL, 'dodge_pct', 'suffix', 1, 80
    UNION ALL SELECT 'epi_spd_fleet', 'epithet', 'Fleet', NULL, NULL, 'speed', 'prefix', 1, 100
    UNION ALL SELECT 'epi_spd_swift', 'epithet', 'Swift', NULL, NULL, 'speed', 'prefix', 1, 70
    UNION ALL SELECT 'epi_spd_gale', 'epithet', 'of the Gale', NULL, NULL, 'speed', 'suffix', 1, 80
    UNION ALL SELECT 'epi_gold_gilded', 'epithet', 'Gilded', NULL, NULL, 'gold_find_pct', 'prefix', 1, 100
    UNION ALL SELECT 'epi_gold_prosperous', 'epithet', 'Prosperous', NULL, NULL, 'gold_find_pct', 'prefix', 1, 60
    UNION ALL SELECT 'epi_gold_plenty', 'epithet', 'of Plenty', NULL, NULL, 'gold_find_pct', 'suffix', 1, 80
    UNION ALL SELECT 'epi_mf_seeking', 'epithet', 'Seeking', NULL, NULL, 'magic_find_pct', 'prefix', 1, 100
    UNION ALL SELECT 'epi_mf_fated', 'epithet', 'Fated', NULL, NULL, 'magic_find_pct', 'prefix', 1, 60
    UNION ALL SELECT 'epi_mf_seeker', 'epithet', 'of the Seeker', NULL, NULL, 'magic_find_pct', 'suffix', 1, 80
) v
WHERE NOT EXISTS (SELECT 1 FROM legendary_names n WHERE n.code = v.code);
