-- Materials, and which places drop them (DESIGN.md §6.7).
-- Drop chances live in config/balance.json (growth.drops), keyed by kind.

INSERT INTO material_templates (code, name, kind, glyph, description, sort_order)
SELECT v.code, v.name, v.kind, v.glyph, v.description, v.sort_order
FROM (
    SELECT 'spark_dust' AS code, 'Spark Dust' AS name, 'dust' AS kind, '✨' AS glyph, 'Fine grit shaken loose in every fight. The first two ranks of a skill drink it.' AS description, 10 AS sort_order
    UNION ALL SELECT 'rune_shard', 'Rune Shard', 'shard', '🔷', 'A cracked rune. Ranks three and four of a skill need these.', 20
    UNION ALL SELECT 'verdant_sap', 'Verdant Sap', 'essence', '🍃', 'Greenwood''s own sap. Any place essence finishes the fifth rank of a skill.', 100
    UNION ALL SELECT 'copper_ore', 'Copper Ore', 'essence', '⛏️', 'Ore from the Old Copper Mine. Any place essence finishes a skill.', 110
    UNION ALL SELECT 'grave_salt', 'Grave Salt', 'essence', '🧂', 'Salt crusted off the Sunken Crypt. Any place essence finishes a skill.', 120
    UNION ALL SELECT 'frost_crystal', 'Frost Crystal', 'essence', '❄️', 'Ice that does not melt, from Frostfang Peaks. Any place essence finishes a skill.', 130
    UNION ALL SELECT 'ember_core', 'Ember Core', 'essence', '🔥', 'A coal that is still a heart, from the Obsidian Depths. Any place essence finishes a skill.', 140
    UNION ALL SELECT 'brine_pearl', 'Brine Pearl', 'essence', '🫧', 'A pearl the fen will not give up twice. Any place essence finishes a skill.', 150
    UNION ALL SELECT 'wreck_nail', 'Wreck Nail', 'essence', '⚓', 'Iron from a ship that should have stayed sunk. Any place essence finishes a skill.', 160
    UNION ALL SELECT 'tideglass', 'Tideglass', 'essence', '🪟', 'Glass the tide polished inside the Tidevault. Any place essence finishes a skill.', 170
    UNION ALL SELECT 'saltglass', 'Saltglass', 'essence', '💎', 'A shard of the Saltglass Spire. Any place essence finishes a skill.', 180
    UNION ALL SELECT 'court_pearl', 'Court Pearl', 'essence', '👑', 'A pearl from the Sunken Court. Any place essence finishes a skill.', 190
    UNION ALL SELECT 'cinder_ash', 'Cinder Ash', 'essence', '🌫️', 'Ash that is still warm, from the Cinder Wastes. Any place essence finishes a skill.', 200
    UNION ALL SELECT 'citadel_cinder', 'Citadel Cinder', 'essence', '🏰', 'A cinder from the Fallen Citadel. Any place essence finishes a skill.', 210
    UNION ALL SELECT 'monks_ash', 'Monk''s Ash', 'essence', '🕯️', 'Ash from a vow that kept burning. Any place essence finishes a skill.', 220
    UNION ALL SELECT 'stair_obsidian', 'Stair Obsidian', 'essence', '⬛', 'A chip of the Black Stair. Any place essence finishes a skill.', 230
    UNION ALL SELECT 'sovereign_ember', 'Sovereign Ember', 'essence', '✴️', 'An ember from the Crown. Any place essence finishes a skill.', 240
) v
WHERE NOT EXISTS (SELECT 1 FROM material_templates WHERE code = 'spark_dust');

INSERT INTO place_materials (place_id, material_id)
SELECT p.id, m.id
FROM places p
JOIN material_templates m ON m.code IN ('spark_dust', 'rune_shard')
WHERE p.place_type <> 'rest'
  AND NOT EXISTS (
      SELECT 1 FROM place_materials pm WHERE pm.place_id = p.id AND pm.material_id = m.id
  );

INSERT INTO place_materials (place_id, material_id)
SELECT p.id, m.id
FROM (
    SELECT 'greenwood' AS place_code, 'verdant_sap' AS mat_code
    UNION ALL SELECT 'copper_mine', 'copper_ore'
    UNION ALL SELECT 'sunken_crypt', 'grave_salt'
    UNION ALL SELECT 'frostfang', 'frost_crystal'
    UNION ALL SELECT 'obsidian_depths', 'ember_core'
    UNION ALL SELECT 'brinefen', 'brine_pearl'
    UNION ALL SELECT 'wreckers_cove', 'wreck_nail'
    UNION ALL SELECT 'tidevault', 'tideglass'
    UNION ALL SELECT 'saltglass_spire', 'saltglass'
    UNION ALL SELECT 'sunken_court', 'court_pearl'
    UNION ALL SELECT 'cinder_wastes', 'cinder_ash'
    UNION ALL SELECT 'fallen_citadel', 'citadel_cinder'
    UNION ALL SELECT 'ash_monastery', 'monks_ash'
    UNION ALL SELECT 'black_stair', 'stair_obsidian'
    UNION ALL SELECT 'crown_of_embers', 'sovereign_ember'
) v
JOIN places p ON p.code = v.place_code
JOIN material_templates m ON m.code = v.mat_code
WHERE NOT EXISTS (
    SELECT 1 FROM place_materials pm WHERE pm.place_id = p.id AND pm.material_id = m.id
);
