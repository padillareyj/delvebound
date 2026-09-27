-- Consumables and the Lanternford Market. DESIGN.md §7.7.
-- Gear prices follow price = round_to_5(base_value * 4 * (1 + 0.05 * req_level)), minimum 10.

INSERT INTO consumable_templates
  (code, name, effect_type, effect_value, use_trigger, req_level, base_price, sell_price, description)
VALUES
('minor_healing_potion', 'Minor Healing Potion', 'heal_pct', 30, 'auto_low_hp', 1, 25, 6, 'Closes a wound. Restores 30% of max HP.'),
('healing_potion', 'Healing Potion', 'heal_pct', 50, 'auto_low_hp', 6, 80, 20, 'A proper draught. Restores 50% of max HP.'),
('greater_healing_potion', 'Greater Healing Potion', 'heal_pct', 70, 'auto_low_hp', 11, 200, 50, 'Reserved for bad rooms. Restores 70% of max HP.'),
('elixir_of_might', 'Elixir of Might', 'buff_attack_pct', 15, 'trip_start', 3, 60, 15, 'Drink at the gate. +15% attack for the trip.'),
('stoneskin_tonic', 'Stoneskin Tonic', 'buff_defense_pct', 15, 'trip_start', 3, 60, 15, 'Skin goes dull and hard. +15% defense and magic resist.'),
('lucky_charm', 'Lucky Charm', 'luck_bonus', 0.20, 'trip_start', 5, 100, 25, 'A charm for the road. +0.20 luck factor for the trip.'),
('smoke_bomb', 'Smoke Bomb', 'flee_bonus', 30, 'on_flee', 1, 30, 7, 'One extra chance to leave. +30% flee chance on one attempt.'),
('bandage_kit', 'Bandage Kit', 'cure_injury', 1, 'manual', 1, 40, 10, 'Cures Wounded. Does nothing for Badly wounded.');

INSERT INTO shop_listings (kind, item_template_id, consumable_id, price, min_level, stock, sort_order, is_active)
SELECT 'gear', it.id, NULL, v.price, it.req_level, NULL, v.sort_order, 1
FROM (
    SELECT 'rusty_sword' AS code, 20 AS price, 10 AS sort_order
    UNION ALL SELECT 'worn_dagger', 20, 20
    UNION ALL SELECT 'oak_staff', 20, 30
    UNION ALL SELECT 'iron_longsword', 120, 40
    UNION ALL SELECT 'hunters_shortbow', 120, 50
    UNION ALL SELECT 'apprentice_wand', 120, 60
    UNION ALL SELECT 'copper_ring', 40, 70
    UNION ALL SELECT 'leather_gloves', 35, 80
    UNION ALL SELECT 'wooden_buckler', 25, 90
    UNION ALL SELECT 'iron_kite_shield', 235, 100
    UNION ALL SELECT 'cloth_tunic', 15, 110
    UNION ALL SELECT 'leather_jerkin', 90, 120
    UNION ALL SELECT 'chainmail', 260, 130
    UNION ALL SELECT 'leather_cap', 15, 140
    UNION ALL SELECT 'iron_helm', 175, 150
    UNION ALL SELECT 'soft_boots', 15, 160
) v
JOIN item_templates it ON it.code = v.code;

INSERT INTO shop_listings (kind, item_template_id, consumable_id, price, min_level, stock, sort_order, is_active)
SELECT 'consumable', NULL, ct.id, ct.base_price, ct.req_level, NULL, 200 + ct.id, 1
FROM consumable_templates ct;
