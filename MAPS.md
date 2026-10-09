# Adding a map

A **map** is a row in `regions`. The map page shows one region at a time. Places on that map are `places` rows with that `region_id`. `map_x` and `map_y` are percentages on **that** region's parchment (0–100), not a shared world map.

The Wilds, the Salt Marches, and the Ashen Crown are the authored maps. A new place on an existing map is the shorter checklist at the bottom. A new hand-drawn map is the full list.

Later maps are generated. When any active character reaches the highest recommended level in the world, `delve:run` writes the next region (a theme, five places ending in a dungeon, monsters, loot, and an essence). See DESIGN.md §7.1. You do not add a seeder for those.

Rules and the level-gate formula stay in [DESIGN.md](DESIGN.md) §4 and §7.1. If this file and DESIGN.md disagree, DESIGN.md wins.

## 1. Decide the map

Pick these before writing SQL:

| Field | Where it shows up | Notes |
|---|---|---|
| `code` | `regions.code`, parchment CSS key | Short, snake_case, unique. Example: `ashen_crown`. |
| `name` | Map tab and page title | Example: `The Ashen Crown`. |
| `blurb` | Subtitle under the title | One sentence. |
| `sort_order` | Tab order | Wilds is `1`, Salt Marches `2`, Ashen Crown `3`. Use the next number. |
| Level band | Every place on the map | Overlap the previous map by a couple of levels so there is no dead gap. The level cap is `level_cap` in `config/balance.json` (30). |
| Places | 4–6 pins | One trail per map. The lowest-level place is the start of the path. Town (`place_type = rest`) stays on the Wilds only. |

`place_type` must be one of: `rest`, `forest`, `mine`, `dungeon`, `mountain`, `cave`, `marsh`, `coast`, `ruins`, `temple`. A new type needs a schema change (step 6).

## 2. Add the content SQL

Create `database/seeders/sql/0NN_your_map.sql`. Copy the shape of `012_later_maps.sql`. Insert in this order, and guard every insert with `WHERE NOT EXISTS (SELECT 1 FROM places WHERE code = '<first new place code>')` so a second run does nothing.

1. **Region.** `INSERT INTO regions (code, name, blurb, sort_order)`.
2. **Loot tables.** One `loot_tables` row per place (`lt_…`). Every table needs at least one `loot_table_entries` row or `delve:check-content` fails.
3. **Item templates**, if the map should drop new gear. Set `slot`, `item_type`, `scaling_stat` (weapons only), `two_handed`, base stats, `implicit_mods`, `req_level`, `base_value`. Use the stat-key names in DESIGN.md §2.1. High-level gear stays out of `shop_listings` unless you mean to sell it in town.
4. **Loot entries.** `nothing` plus item weights, in `loot_table_entries`.
5. **Monsters.** Draft stats with the authoring formula in DESIGN.md §7, then store the final numbers. Bosses: `is_boss = 1`, `drop_chance = 1.000`. Give each monster a `loot_table_id`.
6. **Places.** One row per pin. `region_id` comes from a join on `regions.code`. Required columns:

   `min_level` (hard gate) ≤ `rec_min_level` ≤ `rec_level` ≤ `rec_max_level` ≤ `max_level` (or `max_level` is `NULL`).

   `max_level` is only for starter areas that high-level characters must not farm. Later maps leave it `NULL`.

   Also set `encounter_min` / `encounter_max` (`encounter_max` ≥ `encounter_min`), `boss_monster_id`, `boss_chance`, `rarity_bonus`, `gold_mult`, `chest_loot_table_id`, `map_x`, `map_y`, `description`.

7. **Spawns.** `place_monsters` links the regular monsters (not the boss) to the place: `weight`, `min_group`, `max_group` (`1 ≤ min_group ≤ max_group ≤ 3`).

Monster levels must sit inside every place they spawn in, within 3 levels of that place's recommended range. `database/sql/check_content.sql` rejects anything further out. The boss is not in `place_monsters`; it is `places.boss_monster_id`.

Spread pins so labels do not sit on top of each other. `map_y` near 12 is the top of the parchment, near 78 is the bottom. The path is drawn from each place to the nearest lower-level place on the **same** map.

## 3. Load it on a fresh database and on the one you already have

Fresh installs read the seeder. An existing database does not, because `places` already has rows.

- **Tests.** Add the new file to `SEEDS` in `tests/TestCase.php`, after `012_later_maps.sql` and before `015_shop.sql`.
- **`php artisan db:seed`.** In `database/seeders/DatabaseSeeder.php`, run the file when its marker place is missing (same pattern as the `brinefen` check).
- **Existing MySQL.** Add a migration in `database/migrations/` that runs the SQL file only when the marker place is missing and `town` already exists. Do not edit `database/schema/001_schema.sql` or an applied migration.

Then:

```bash
php artisan migrate --database=admin
```

## 4. Tell the map page about it

The tab list is every `regions` row, ordered by `sort_order`. `GameQueries::mapPage` already sends them. You do not add a route.

Optional, for a tint that is not the Wilds parchment:

- Add a class in `resources/css/app.css` (see `.parchment-salt` and `.parchment-ash`).
- Point the region code at it in `PARCHMENT` in `resources/js/pages/Map.tsx`.

Icons come from `place_type`, not from the region. They live in `PLACE_ICONS` in `resources/js/lib/format.ts`. The camp page guesses an icon from the place code in `placeTypeGuess` in `resources/js/pages/Dashboard.tsx`. Add the new codes there.

## 5. Write it down

Update DESIGN.md §7.1: the region table, the place table, and the `place_monsters` list. Bump the seed counts in §13 if you add places, monsters, templates, or loot tables.

Add a case to `tests/fixtures/place_access_cases.json` only when the gate pattern is new (a new `max_level` shape, for example). Ordinary "requires level N" places are already covered.

## 6. A new place type

Only if none of the existing types fit:

1. Extend the `places.place_type` ENUM in a new migration.
2. Put the same ENUM in the `CREATE TABLE places` block in DESIGN.md §2.3.
3. Add an emoji to `PLACE_ICONS`.

## 7. Check

```bash
php artisan test
php artisan delve:check-content
npx tsc --noEmit
npm run build
php artisan delve:run --dry-run --seed=42
```

`delve:check-content` must report no problems. On the map page, confirm the new tab, that locked places name the required level, and that a character who meets `min_level` can set the order.

## Adding a place to a map that already exists

Skip the region row and the parchment class. In a new seeder SQL file plus a migration (step 3):

1. Loot table and entries.
2. Item templates, if the drops are new.
3. Monsters, including the boss.
4. One `places` row whose `region_id` is the existing region.
5. `place_monsters` rows.
6. DESIGN.md §7.1.
7. `placeTypeGuess` if the camp icon would otherwise be wrong.
8. The same checks as step 7.
