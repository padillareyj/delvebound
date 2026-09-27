<?php

namespace App\Http\Controllers;

use App\Services\GameQueries;
use App\Services\ShopService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketController extends Controller
{
    public function __construct(private ShopService $shop) {}

    private function version(Request $request): int
    {
        return (int) $request->validate(['row_version' => ['required', 'integer', 'min:0']])['row_version'];
    }

    public function index(Request $request, GameQueries $queries): Response
    {
        return Inertia::render('Market', $queries->marketPage((int) $request->user()->id));
    }

    public function buy(Request $request): RedirectResponse
    {
        $data = $request->validate(['listing_id' => ['required', 'integer'], 'quantity' => ['nullable', 'integer', 'min:1', 'max:20']]);
        $qty = (int) ($data['quantity'] ?? 1);
        $name = $this->shop->buy((int) $request->user()->id, $this->version($request), (int) $data['listing_id'], $qty);

        return back()->with('success', 'Bought '.($qty > 1 ? "{$qty} × " : '')."{$name}.");
    }

    public function sellItem(Request $request): RedirectResponse
    {
        $data = $request->validate(['item_id' => ['required', 'integer']]);
        [$name, $gold] = $this->shop->sellItem((int) $request->user()->id, $this->version($request), (int) $data['item_id']);

        return back()->with('success', "Sold {$name} for {$gold} gold.");
    }

    public function sellConsumable(Request $request): RedirectResponse
    {
        $data = $request->validate(['consumable_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1', 'max:99']]);
        [$name, $gold] = $this->shop->sellConsumable((int) $request->user()->id, $this->version($request), (int) $data['consumable_id'], (int) $data['quantity']);

        return back()->with('success', "Sold {$data['quantity']} × {$name} for {$gold} gold.");
    }
}
