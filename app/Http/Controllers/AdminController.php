<?php

namespace App\Http\Controllers;

use App\Game\ItemTemplates;
use App\Services\AdminContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin content tools. Middleware: auth + admin; writes also use run.guard. */
class AdminController extends Controller
{
    public function __construct(private AdminContentService $admin) {}

    public function index(): Response
    {
        return Inertia::render('admin/Index', $this->admin->gearIndex());
    }

    public function gearCreate(): Response
    {
        return Inertia::render('admin/GearForm', $this->admin->gearForm(null));
    }

    public function gearEdit(int $id): Response
    {
        return Inertia::render('admin/GearForm', $this->admin->gearForm($id));
    }

    public function gearStore(Request $request): RedirectResponse
    {
        $id = $this->admin->saveGear(null, $this->gearInput($request));

        return redirect("/admin/gear/{$id}/edit")->with('success', 'Gear template created. It applies on the next expedition.');
    }

    public function gearUpdate(Request $request, int $id): RedirectResponse
    {
        $this->admin->saveGear($id, $this->gearInput($request));

        return back()->with('success', 'Gear template saved. Already-dropped items keep their rolled power/armor; implicits follow this template.');
    }

    public function gearDestroy(int $id): RedirectResponse
    {
        $this->admin->deleteGear($id);

        return redirect('/admin')->with('success', 'Gear template deleted.');
    }

    private function gearInput(Request $request): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'string', Rule::in(ItemTemplates::SLOTS)],
            'item_type' => ['required', 'string', Rule::in(ItemTemplates::ITEM_TYPES)],
            'scaling_stat' => ['nullable', 'string', Rule::in(['str', 'dex', 'int'])],
            'two_handed' => ['sometimes', 'boolean'],
            'base_power' => ['required', 'integer', 'min:0', 'max:65535'],
            'base_armor' => ['required', 'integer', 'min:0', 'max:65535'],
            'base_mres' => ['required', 'integer', 'min:0', 'max:65535'],
            'req_level' => ['required', 'integer', 'min:1', 'max:30'],
            'base_value' => ['required', 'integer', 'min:1', 'max:1000000'],
            'description' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            'implicit' => ['nullable', 'array'],
            'implicit.*.key' => ['nullable', 'string', Rule::in(ItemTemplates::STAT_KEYS)],
            'implicit.*.value' => ['nullable', 'numeric'],
            'loot' => ['nullable', 'array'],
            'loot.*.loot_table_id' => ['required', 'integer', 'min:1'],
            'loot.*.weight' => ['required', 'integer', 'min:0', 'max:10000'],
            'in_shop' => ['sometimes', 'boolean'],
        ]);
    }
}
