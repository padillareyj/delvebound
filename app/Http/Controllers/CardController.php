<?php

namespace App\Http\Controllers;

use App\Services\CardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function __construct(private CardService $cards) {}

    private function version(Request $request): int
    {
        return (int) $request->validate(['row_version' => ['required', 'integer', 'min:0']])['row_version'];
    }

    public function insert(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'card_id' => ['required', 'integer', 'min:1'],
            'item_id' => ['required', 'integer', 'min:1'],
            'slot_index' => ['required', 'integer', 'min:0', 'max:7'],
        ]);
        $name = $this->cards->insert(
            (int) $request->user()->id,
            $this->version($request),
            (int) $data['card_id'],
            (int) $data['item_id'],
            (int) $data['slot_index'],
        );

        return back()->with('success', "Socketed {$name}.");
    }

    public function remove(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'min:1'],
            'slot_index' => ['required', 'integer', 'min:0', 'max:7'],
        ]);
        $name = $this->cards->remove(
            (int) $request->user()->id,
            $this->version($request),
            (int) $data['item_id'],
            (int) $data['slot_index'],
        );

        return back()->with('success', "Removed {$name}.");
    }

    public function sell(Request $request): RedirectResponse
    {
        $data = $request->validate(['card_id' => ['required', 'integer', 'min:1']]);
        [$name, $gold] = $this->cards->sell((int) $request->user()->id, $this->version($request), (int) $data['card_id']);

        return back()->with('success', "Sold {$name} for {$gold} gold.");
    }
}
