<?php

namespace App\Http\Middleware;

use App\Services\GameQueries;
use Illuminate\Http\Request;
use Inertia\Middleware;

/** Props every page receives (DESIGN.md §11.3). */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $account = $request->user();

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'displayTimezone' => config('delvebound.display_timezone'),
            'auth' => [
                'account' => $account ? [
                    'id' => $account->id,
                    'username' => $account->username,
                    'is_admin' => (bool) $account->is_admin,
                ] : null,
            ],
            'hero' => fn () => $account ? app(GameQueries::class)->heroSummary($account->id) : null,
            'run' => fn () => app(GameQueries::class)->runStatus(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'openedChest' => fn () => $request->session()->get('opened_chest'),
            ],
        ];
    }
}
