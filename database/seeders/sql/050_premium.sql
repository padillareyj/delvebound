-- Premium store catalog (DESIGN.md §16). Prices are play-money cents. Bonuses use Stats::BONUS_KEYS.
-- One worn piece per category (title, wings, aura, pet, frame). Bundles are repeatable gold.

INSERT INTO premium_items (code, name, category, tier, glyph, style, description, price_cents, bonuses, power, grant_gold, sort_order) VALUES
('title_wanderer',   'the Wanderer',     'title',  'rare',      '🧭', 'moss',      'Every road is home.',                                   299, '{"hp_pct": 2}',                                                   5, 0, 10),
('title_delver',     'Deep Delver',      'title',  'rare',      '⛏️', 'lantern',   'Knows which tunnel breathes.',                          499, '{"attack_pct": 2, "xp_pct": 5}',                                  8, 0, 11),
('title_goldseeker', 'Goldseeker',       'title',  'epic',      '💰', 'lantern',   'Coins roll toward them.',                               699, '{"gold_find_pct": 15, "magic_find_pct": 5}',                     10, 0, 12),
('title_kingslayer', 'Kingslayer',       'title',  'legendary', '👑', 'blood',     'Crowns have fallen to this blade.',                    1499, '{"attack_pct": 6, "crit_dmg_pct": 10}',                          22, 0, 13),
('title_unbroken',   'the Unbroken',     'title',  'legendary', '🛡️', 'frost',     'Knocked down. Never out.',                             1499, '{"hp_pct": 6, "dmg_reduction_pct": 3}',                          22, 0, 14),
('title_ascendant',  'Ascendant',        'title',  'mythic',    '🌟', 'celestial', 'Spoken of in the same breath as the old heroes.',      2999, '{"hp_pct": 5, "attack_pct": 5, "defense_pct": 5, "xp_pct": 10}', 40, 0, 15),

('wings_feather',    'Feathered Wings',  'wings',  'rare',      '🪶', 'frost',     'Light as a held breath.',                               999, '{"speed": 2, "dodge_pct": 1.5}',                                 12, 0, 20),
('wings_bat',        'Nightwing',        'wings',  'epic',      '🦇', 'blood',     'Leathery, silent, and thirsty.',                       1499, '{"lifesteal_pct": 3, "speed": 2}',                               18, 0, 21),
('wings_phoenix',    'Phoenix Wings',    'wings',  'legendary', '🔥', 'ember',     'Burn down, rise again.',                               3499, '{"hp_pct": 8, "attack_pct": 4, "lifesteal_pct": 2}',             45, 0, 22),
('wings_seraph',     'Seraph Wings',     'wings',  'legendary', '🕊️', 'celestial', 'Six pinions of hammered light.',                       3999, '{"defense_pct": 8, "hp_pct": 6, "dmg_reduction_pct": 3}',        48, 0, 23),
('wings_void',       'Voidrender Wings', 'wings',  'mythic',    '🌌', 'void',      'Where they beat, armour forgets itself.',              4499, '{"attack_pct": 8, "penetration_pct": 10, "crit_pct": 2}',        55, 0, 24),

('aura_ember',       'Ember Aura',       'aura',   'rare',      '☄️', 'ember',     'A heat shimmer that will not leave.',                   799, '{"attack_pct": 3}',                                               9, 0, 30),
('aura_frost',       'Frost Aura',       'aura',   'rare',      '❄️', 'frost',     'Blows slow before they land.',                          799, '{"defense_pct": 4}',                                              9, 0, 31),
('aura_verdant',     'Verdant Aura',     'aura',   'epic',      '🌿', 'moss',      'Moss grows where they stand.',                          999, '{"hp_pct": 4}',                                                  10, 0, 32),
('aura_starlight',   'Starlight Aura',   'aura',   'legendary', '✨', 'arcane',    'The stars point at weak spots.',                       1999, '{"crit_pct": 3, "crit_dmg_pct": 15}',                            26, 0, 33),

('pet_owl',          'Owl Familiar',     'pet',    'rare',      '🦉', 'lantern',   'Remembers every lesson for you.',                       999, '{"xp_pct": 10}',                                                  8, 0, 40),
('pet_cat',          'Lucky Cat',        'pet',    'epic',      '🐈', 'lantern',   'Sits on the best chest in the room.',                  1199, '{"magic_find_pct": 10, "gold_find_pct": 10}',                    12, 0, 41),
('pet_wolf',         'Shadow Wolf',      'pet',    'epic',      '🐺', 'void',      'Goes for the gaps in the plate.',                      1699, '{"attack_pct": 3, "speed": 3, "penetration_pct": 5}',            20, 0, 42),
('pet_dragon',       'Baby Dragon',      'pet',    'legendary', '🐉', 'ember',     'Small. Loud. Very hot.',                               2499, '{"attack_pct": 4, "hp_pct": 4, "penetration_pct": 5}',           32, 0, 43),

('frame_bronze',     'Bronze Frame',     'frame',  'rare',      '🥉', 'ember',     'A portrait worth hanging.',                             199, '{"hp_pct": 1}',                                                   3, 0, 50),
('frame_gilded',     'Gilded Frame',     'frame',  'epic',      '🖼️', 'lantern',   'Gold leaf on oak.',                                     499, '{"hp_pct": 2, "defense_pct": 2}',                                 7, 0, 51),
('frame_celestial',  'Celestial Frame',  'frame',  'legendary', '💠', 'celestial', 'The border moves when no one is looking.',             1299, '{"hp_pct": 3, "attack_pct": 2, "defense_pct": 3}',              16, 0, 52),

('bundle_pouch',     'Pouch of Gold',    'bundle', 'rare',      '👛', 'lantern',   '100 gold, straight into your purse.',                    99, NULL,                                                              0, 100, 60),
('bundle_chest',     'Chest of Gold',    'bundle', 'epic',      '🧰', 'lantern',   '600 gold for a serious shopping trip.',                 499, NULL,                                                              0, 600, 61),
('bundle_hoard',     'Dragon''s Hoard',  'bundle', 'legendary', '🏦', 'ember',     '1,400 gold. The dragon will not miss it.',              999, NULL,                                                              0, 1400, 62);
