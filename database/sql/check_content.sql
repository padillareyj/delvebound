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

    SELECT 'monster_far_outside_range', CONCAT(p.code, ':', m.code, ' L', m.level)
    FROM place_monsters pm
    JOIN places p ON p.id = pm.place_id
    JOIN monsters m ON m.id = pm.monster_id
    WHERE CAST(m.level AS SIGNED) < CAST(p.rec_min_level AS SIGNED) - 3
       OR CAST(m.level AS SIGNED) > CAST(p.rec_max_level AS SIGNED) + 3
) problems
ORDER BY issue, detail;
