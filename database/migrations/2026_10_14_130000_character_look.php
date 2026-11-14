<?php

use App\Game\ItemArt;
use App\Services\HeroLook;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cosmetic hero look for the 3D preview and battle replay (DESIGN.md §2.3, "Hero look").
 * Existing heroes are backfilled with the weapon-based look they already had, so nothing changes on screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE characters ADD COLUMN look JSON NULL AFTER auto_place');

        $rows = DB::table('characters as c')
            ->leftJoin('character_equipment as ce', function ($join) {
                $join->on('ce.character_id', '=', 'c.id')->where('ce.slot', '=', 'weapon');
            })
            ->leftJoin('item_instances as ii', 'ii.id', '=', 'ce.item_instance_id')
            ->leftJoin('item_templates as it', 'it.id', '=', 'ii.template_id')
            ->get(['c.id', 'ii.id as item_id', 'ii.display_name', 'ii.rarity', 'it.code', 'it.name as template_name', 'it.slot',
                'it.item_type', 'it.two_handed', 'it.icon as template_icon']);
        foreach ($rows as $row) {
            $family = null;
            if ($row->item_id) {
                $family = ItemArt::resolve([
                    'icon' => $row->template_icon, 'code' => $row->code, 'name' => $row->template_name,
                    'display_name' => $row->display_name, 'slot' => $row->slot, 'item_type' => $row->item_type,
                    'two_handed' => (bool) $row->two_handed, 'rarity' => $row->rarity,
                ])['family'];
            }
            DB::table('characters')->where('id', $row->id)->update(['look' => json_encode(HeroLook::presetForFamily($family))]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE characters DROP COLUMN look');
    }
};
