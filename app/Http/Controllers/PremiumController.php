<?php

namespace App\Http\Controllers;

use App\Services\GameQueries;
use App\Services\PremiumService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PremiumController extends Controller
{
    public function __construct(private PremiumService $premium) {}

    private function version(Request $request): int
    {
        return (int) $request->validate(['row_version' => ['required', 'integer', 'min:0']])['row_version'];
    }

    public function index(Request $request, GameQueries $queries): Response
    {
        return Inertia::render('Premium', $queries->premiumPage((int) $request->user()->id));
    }

    public function buy(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/']]);
        [$name, $gold] = $this->premium->buy((int) $request->user()->id, $this->version($request), $data['code']);

        return back()->with('success', $gold > 0 ? "{$name}: +{$gold} gold." : "Unlocked {$name}. It is worn now.");
    }

    public function wear(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/']]);
        $name = $this->premium->wear((int) $request->user()->id, $this->version($request), $data['code']);

        return back()->with('success', "Now wearing {$name}.");
    }

    public function takeOff(Request $request): RedirectResponse
    {
        $data = $request->validate(['category' => ['required', 'string', 'max:10']]);
        $this->premium->takeOff((int) $request->user()->id, $this->version($request), $data['category']);

        return back()->with('success', 'Taken off.');
    }
}
