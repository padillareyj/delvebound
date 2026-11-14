-- Daily-quest upgrade materials (DESIGN.md §17). Dropped by quests/chests, not by places.

ALTER TABLE material_templates MODIFY kind ENUM('dust','shard','essence','system') NOT NULL;

INSERT INTO material_templates (code, name, kind, glyph, description, sort_order)
SELECT v.code, v.name, v.kind, v.glyph, v.description, v.sort_order
FROM (
    SELECT 'jewel_dust' AS code, 'Jewel Dust' AS name, 'system' AS kind, '💎' AS glyph,
           'Powder for the five elemental jewels. Daily quests drop it.' AS description, 300 AS sort_order
    UNION ALL SELECT 'excellent_stone', 'Excellent Stone', 'system', '🔶',
           'A stone that raises Excellency. Daily chests and longer quests drop it.', 310
    UNION ALL SELECT 'wing_feather', 'Wing Feather', 'system', '🪶',
           'Feathers that add Wing Stars. Clear places and activity chests drop them.', 320
    UNION ALL SELECT 'sigil_ink', 'Sigil Ink', 'system', '🖌',
           'Ink for the Sigil Circle. Daily kills, clears, arena, and activity chests drop it.', 330
) v
WHERE NOT EXISTS (SELECT 1 FROM material_templates WHERE code = v.code);
