-- Table-level grants for the website user. Applied after the schema exists.
-- Run as MySQL root.

GRANT SELECT ON delvebound.* TO 'delve_web'@'localhost';
GRANT SELECT ON delvebound.* TO 'delve_web'@'127.0.0.1';

GRANT INSERT ON delvebound.accounts TO 'delve_web'@'localhost';
GRANT INSERT ON delvebound.characters TO 'delve_web'@'localhost';
GRANT INSERT ON delvebound.item_instances TO 'delve_web'@'localhost';
GRANT INSERT ON delvebound.character_equipment TO 'delve_web'@'localhost';
GRANT INSERT ON delvebound.character_consumables TO 'delve_web'@'localhost';
GRANT INSERT ON delvebound.trip_orders TO 'delve_web'@'localhost';
GRANT INSERT ON delvebound.shop_transactions TO 'delve_web'@'localhost';
GRANT INSERT ON delvebound.item_instance_affixes TO 'delve_web'@'localhost';
GRANT INSERT ON delvebound.trip_loot TO 'delve_web'@'localhost';

GRANT INSERT ON delvebound.accounts TO 'delve_web'@'127.0.0.1';
GRANT INSERT ON delvebound.characters TO 'delve_web'@'127.0.0.1';
GRANT INSERT ON delvebound.item_instances TO 'delve_web'@'127.0.0.1';
GRANT INSERT ON delvebound.character_equipment TO 'delve_web'@'127.0.0.1';
GRANT INSERT ON delvebound.character_consumables TO 'delve_web'@'127.0.0.1';
GRANT INSERT ON delvebound.trip_orders TO 'delve_web'@'127.0.0.1';
GRANT INSERT ON delvebound.shop_transactions TO 'delve_web'@'127.0.0.1';
GRANT INSERT ON delvebound.item_instance_affixes TO 'delve_web'@'127.0.0.1';
GRANT INSERT ON delvebound.trip_loot TO 'delve_web'@'127.0.0.1';

GRANT UPDATE ON delvebound.accounts TO 'delve_web'@'localhost';
GRANT UPDATE ON delvebound.characters TO 'delve_web'@'localhost';
GRANT UPDATE ON delvebound.item_instances TO 'delve_web'@'localhost';
GRANT UPDATE ON delvebound.character_consumables TO 'delve_web'@'localhost';
GRANT UPDATE ON delvebound.trip_orders TO 'delve_web'@'localhost';
GRANT UPDATE ON delvebound.mail TO 'delve_web'@'localhost';
GRANT UPDATE ON delvebound.loot_boxes TO 'delve_web'@'localhost';

GRANT UPDATE ON delvebound.accounts TO 'delve_web'@'127.0.0.1';
GRANT UPDATE ON delvebound.characters TO 'delve_web'@'127.0.0.1';
GRANT UPDATE ON delvebound.item_instances TO 'delve_web'@'127.0.0.1';
GRANT UPDATE ON delvebound.character_consumables TO 'delve_web'@'127.0.0.1';
GRANT UPDATE ON delvebound.trip_orders TO 'delve_web'@'127.0.0.1';
GRANT UPDATE ON delvebound.mail TO 'delve_web'@'127.0.0.1';
GRANT UPDATE ON delvebound.loot_boxes TO 'delve_web'@'127.0.0.1';

GRANT DELETE ON delvebound.character_equipment TO 'delve_web'@'localhost';
GRANT DELETE ON delvebound.character_consumables TO 'delve_web'@'localhost';
GRANT DELETE ON delvebound.character_equipment TO 'delve_web'@'127.0.0.1';
GRANT DELETE ON delvebound.character_consumables TO 'delve_web'@'127.0.0.1';

FLUSH PRIVILEGES;
