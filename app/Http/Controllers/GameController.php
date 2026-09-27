<?php

namespace App\Http\Controllers;

use App\Services\AccountService;
use App\Services\CharacterService;
use App\Services\GameQueries;
use App\Services\LootBoxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Read pages plus the character, map, mail, and account writes. Thin: validate, call a service, redirect. */
class GameController extends Controller
{
    public function __construct(private GameQueries $queries, private CharacterService $characters) {}

    private function accountId(Request $request): int
    {
        return (int) $request->user()->id;
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['row_version' => ['required', 'integer', 'min:0']])['row_version'];
    }

    // --------------------------------------------------------------- pages

    public function dashboard(Request $request): Response
    {
        return Inertia::render('Dashboard', $this->queries->dashboard($this->accountId($request)));
    }

    public function character(Request $request): Response
    {
        return Inertia::render('Character', $this->queries->characterPage($this->accountId($request)));
    }

    public function map(Request $request): Response
    {
        return Inertia::render('Map', $this->queries->mapPage($this->accountId($request)));
    }

    public function trip(Request $request, int $id): Response
    {
        return Inertia::render('Trip', ['trip' => $this->queries->trip($this->accountId($request), $id)]);
    }

    public function leaderboard(Request $request): Response
    {
        return Inertia::render('Leaderboard', $this->queries->leaderboard((string) $request->query('sort', 'level')));
    }

    public function bots(): Response
    {
        return Inertia::render('Bots', ['bots' => $this->queries->bots()]);
    }

    public function arena(Request $request): Response
    {
        return Inertia::render('Arena', $this->queries->arenaPage($this->accountId($request)));
    }

    public function enterArena(Request $request): RedirectResponse
    {
        $this->characters->enterArena($this->accountId($request), $this->version($request));

        return back()->with('success', 'You are signed up. The next arena will draw you into the bracket.');
    }

    public function leaveArena(Request $request): RedirectResponse
    {
        $this->characters->leaveArena($this->accountId($request), $this->version($request));

        return back()->with('success', 'You left the arena signup.');
    }

    public function runs(): Response
    {
        return Inertia::render('Runs', ['runs' => $this->queries->runs()]);
    }

    public function account(Request $request): Response
    {
        $account = $request->user();

        return Inertia::render('Account', [
            'account' => [
                'username' => $account->username,
                'email' => $account->email,
                'is_admin' => (bool) $account->is_admin,
                'created_at' => GameQueries::utc($account->created_at?->utc()->format('Y-m-d H:i:s')),
                'last_login_at' => GameQueries::utc($account->last_login_at?->utc()->format('Y-m-d H:i:s')),
            ],
        ]);
    }

    // ----------------------------------------------------------- character

    public function spendPoints(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'points' => ['required', 'array'],
            'points.*' => ['integer', 'min:0', 'max:999'],
        ]);
        $this->characters->spendPoints($this->accountId($request), $this->version($request), $data['points']);

        return back()->with('success', 'Stat points spent.');
    }

    public function prep(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'retreat_threshold_pct' => ['required', 'integer', 'between:0,90'],
            'potion_threshold_pct' => ['required', 'integer', 'between:0,100'],
            'auto_repeat' => ['required', 'boolean'],
        ]);
        $this->characters->updatePrep($this->accountId($request), $this->version($request),
            (int) $data['retreat_threshold_pct'], (int) $data['potion_threshold_pct'], (bool) $data['auto_repeat']);

        return back()->with('success', 'Expedition settings saved.');
    }

    public function respec(Request $request): RedirectResponse
    {
        $cost = $this->characters->respec($this->accountId($request), $this->version($request));

        return back()->with('success', "Stats reset for {$cost} gold. Spend your points again.");
    }

    // ---------------------------------------------------------------- pack

    public function pack(Request $request): Response
    {
        return Inertia::render('Pack', $this->queries->packPage($this->accountId($request)));
    }

    public function equip(Request $request): RedirectResponse
    {
        $data = $request->validate(['item_id' => ['required', 'integer'], 'slot' => ['required', 'string', 'max:10']]);
        $name = $this->characters->equip($this->accountId($request), $this->version($request), (int) $data['item_id'], $data['slot']);

        return back()->with('success', "Equipped {$name}.");
    }

    public function unequip(Request $request): RedirectResponse
    {
        $data = $request->validate(['slot' => ['required', 'string', 'max:10']]);
        $this->characters->unequip($this->accountId($request), $this->version($request), $data['slot']);

        return back()->with('success', 'Moved to your pack.');
    }

    public function belt(Request $request): RedirectResponse
    {
        $data = $request->validate(['belt' => ['present', 'array'], 'belt.*' => ['integer', 'min:0', 'max:99']]);
        $this->characters->saveBelt($this->accountId($request), $this->version($request), array_map('intval', $data['belt']));

        return back()->with('success', 'Belt packed.');
    }

    public function bandage(Request $request): RedirectResponse
    {
        $this->characters->useBandage($this->accountId($request), $this->version($request));

        return back()->with('success', 'Bandaged up. You are healthy again.');
    }

    public function openChest(Request $request, LootBoxService $chests, int $id): RedirectResponse
    {
        $opened = $chests->open($this->accountId($request), $this->version($request), $id);

        return back()->with('opened_chest', $opened)->with('success', "The chest from {$opened['place']} falls open.");
    }

    // ----------------------------------------------------------------- map

    public function order(Request $request): RedirectResponse
    {
        $data = $request->validate(['place_id' => ['required', 'integer']]);
        $name = $this->characters->setOrder($this->accountId($request), (int) $data['place_id']);

        return back()->with('success', "Order placed: {$name} on the next run.");
    }

    public function cancelOrder(Request $request): RedirectResponse
    {
        $this->characters->cancelOrder($this->accountId($request));

        return back()->with('success', 'Order cancelled.');
    }

    // ---------------------------------------------------------------- mail

    public function mail(Request $request): Response
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'outcome' => ['nullable', 'in:cleared,retreated,defeated,rested,blocked_injured,order_rejected,champion,eliminated'],
            'unread' => ['nullable', 'boolean'],
        ]);

        return Inertia::render('mail/Index', $this->queries->mailIndex(
            $this->accountId($request), (int) ($data['page'] ?? 1), $data['outcome'] ?? null, (bool) ($data['unread'] ?? false),
        ));
    }

    public function mailShow(Request $request, int $id): Response
    {
        $letter = $this->queries->mailOne($this->accountId($request), $id);
        if (! $letter['is_read'] && ! $this->queries->runStatus()['running']) {
            $this->characters->markMailRead($this->accountId($request), $id);
            $letter['is_read'] = true;
        }

        return Inertia::render('mail/Show', ['letter' => $letter]);
    }

    public function mailReadAll(Request $request): RedirectResponse
    {
        $this->characters->markAllMailRead($this->accountId($request));

        return back()->with('success', 'All mail marked as read.');
    }

    public function mailDelete(Request $request, int $id): RedirectResponse
    {
        $this->characters->deleteMail($this->accountId($request), $id);

        return redirect('/mail')->with('success', 'Letter deleted.');
    }

    // ------------------------------------------------------------- account

    public function changePassword(Request $request, AccountService $accounts): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)],
        ]);
        $accounts->changePassword($request->user(), $data['current_password'], $data['password']);

        return back()->with('success', 'Password changed.');
    }
}
