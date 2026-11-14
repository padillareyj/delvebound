<?php

namespace App\Http\Controllers;

use App\Services\DailyService;
use App\Services\GameQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DailyController extends Controller
{
    public function __construct(private DailyService $daily) {}

    private function version(Request $request): int
    {
        return (int) $request->validate(['row_version' => ['required', 'integer', 'min:0']])['row_version'];
    }

    public function index(Request $request, GameQueries $queries): Response
    {
        $this->daily->touchLogin((int) $request->user()->id);

        return Inertia::render('Quests', $queries->questsPage((int) $request->user()->id));
    }

    public function claimQuest(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/']]);
        $gained = $this->daily->claimQuest((int) $request->user()->id, $this->version($request), $data['code']);

        return back()->with('success', self::rewardLine('Quest claimed', $gained));
    }

    public function claimChest(Request $request): RedirectResponse
    {
        $data = $request->validate(['at' => ['required', 'integer', 'min:1']]);
        $gained = $this->daily->claimChest((int) $request->user()->id, $this->version($request), (int) $data['at']);

        return back()->with('success', self::rewardLine('Activity chest opened', $gained));
    }

    public function raiseJewel(Request $request): RedirectResponse
    {
        $data = $request->validate(['element' => ['required', 'string', 'in:fire,water,thunder,earth,wind']]);
        $level = $this->daily->raiseJewel((int) $request->user()->id, $this->version($request), $data['element']);

        return back()->with('success', ucfirst($data['element']).' jewel is now level '.$level.'.');
    }

    public function raiseExcellency(Request $request): RedirectResponse
    {
        $level = $this->daily->raiseExcellency((int) $request->user()->id, $this->version($request));

        return back()->with('success', "Excellency is now level {$level}.");
    }

    public function raiseWing(Request $request): RedirectResponse
    {
        $level = $this->daily->raiseWing((int) $request->user()->id, $this->version($request));

        return back()->with('success', "Wing Stars are now level {$level}.");
    }

    public function raiseSigil(Request $request): RedirectResponse
    {
        $data = $request->validate(['element' => ['required', 'string', 'in:fire,water,thunder,earth,wind']]);
        $level = $this->daily->raiseSigil((int) $request->user()->id, $this->version($request), $data['element']);

        return back()->with('success', ucfirst($data['element']).' sigil is now level '.$level.'.');
    }

    public function raiseNexus(Request $request): RedirectResponse
    {
        $level = $this->daily->raiseNexus((int) $request->user()->id, $this->version($request));

        return back()->with('success', "Sigil Nexus is now level {$level}.");
    }

    /** @param array<string, int> $gained */
    private static function rewardLine(string $prefix, array $gained): string
    {
        if ($gained === []) {
            return $prefix.'.';
        }
        $bits = [];
        foreach ($gained as $code => $qty) {
            $bits[] = "+{$qty} {$code}";
        }

        return $prefix.': '.implode(', ', $bits).'.';
    }
}
