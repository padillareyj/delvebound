-- Content sanity. A healthy database returns zero rows.
SELECT issue, detail FROM (
    SELECT 'place_without_monsters' AS issue, p.code AS detail
    FROM places p
    LEFT JOIN place_monsters pm ON pm.place_id = p.id
    WHERE p.place_type <> 'rest' AND p.is_active = 1
    GROUP BY p.id, p.code
    HAVING COUNT(pm.monster_id) = 0

    UNION ALL

    SELECT 'monster_without_loot', m.code
    FROM monsters m
    WHERE m.loot_table_id IS NULL

    UNION ALL

    SELECT 'loot_table_zero_weight', lt.code
    FROM loot_tables lt
    LEFT JOIN loot_table_entries e ON e.loot_table_id = lt.id
    GROUP BY lt.id, lt.code
    HAVING COALESCE(SUM(e.weight), 0) = 0

    UNION ALL

    SELECT 'shop_listing_bad_template', CONCAT('listing#', sl.id)
    FROM shop_listings sl
    LEFT JOIN item_templates it ON it.id = sl.item_template_id
    LEFT JOIN consumable_templates ct ON ct.id = sl.consumable_id
    WHERE sl.is_active = 1
      AND (
            (sl.kind = 'gear' AND (it.id IS NULL OR sl.min_level <> it.req_level))
         OR (sl.kind = 'consumable' AND (ct.id IS NULL OR sl.min_level <> ct.req_level))
      )

    UNION ALL

    SELECT DISTINCT 'legendary_base_missing', CONCAT(it.slot, ':', it.item_type)
    FROM item_templates it
    WHERE NOT EXISTS (
        SELECT 1 FROM legendary_names n
        WHERE n.kind = 'base'
          AND n.item_type = it.item_type
          AND n.slot = it.slot
          AND n.min_ilvl <= it.req_level
    )

    UNION ALL

    SELECT DISTINCT 'legendary_epithet_missing', a.stat_key
    FROM affixes a
    WHERE NOT EXISTS (
        SELECT 1 FROM legendary_names n
        WHERE n.kind = 'epithet' AND n.stat_key = a.stat_key
    )

    UNION ALL

    SELECT 'monster_far_outside_range', CONCAT(p.code, ':', m.code, ' L', m.level)
    FROM place_monsters pm
    JOIN places p ON p.id = pm.place_id
    JOIN monsters m ON m.id = pm.monster_id
    WHERE CAST(m.level AS SIGNED) < CAST(p.rec_min_level AS SIGNED) - 3
       OR CAST(m.level AS SIGNED) > CAST(p.rec_max_level AS SIGNED) + 3

    UNION ALL

    SELECT 'premium_without_bonus', pi.code
    FROM premium_items pi
    WHERE pi.category <> 'bundle' AND (pi.bonuses IS NULL OR JSON_LENGTH(pi.bonuses) = 0)

    UNION ALL

    -- Must match App\Game\Stats::BONUS_KEYS.
    SELECT 'premium_unknown_bonus_key', CONCAT(pi.code, ':', k.bonus_key)
    FROM premium_items pi
    JOIN JSON_TABLE(JSON_KEYS(COALESCE(pi.bonuses, JSON_OBJECT())), '$[*]' COLUMNS (bonus_key VARCHAR(40) PATH '$')) k
    WHERE k.bonus_key NOT IN ('hp', 'attack', 'defense', 'mres', 'crit_pct', 'crit_dmg_pct', 'dodge_pct', 'speed',
                              'magic_find_pct', 'gold_find_pct', 'hp_pct', 'attack_pct', 'defense_pct',
                              'lifesteal_pct', 'penetration_pct', 'dmg_reduction_pct', 'xp_pct')
) problems
ORDER BY issue, detail;
