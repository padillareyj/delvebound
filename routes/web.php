<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\PremiumController;
use Illuminate\Support\Facades\Route;

// There is deliberately no route that starts a simulation run (DESIGN.md §14, decision 4).

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/register', [AuthController::class, 'register'])->middleware('run.guard');
    });
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [GameController::class, 'dashboard'])->name('dashboard');
    Route::get('/character', [GameController::class, 'character'])->name('character');
    Route::get('/pack', [GameController::class, 'pack'])->name('pack');
    Route::get('/market', [MarketController::class, 'index'])->name('market');
    Route::get('/premium', [PremiumController::class, 'index'])->name('premium');
    Route::get('/map', [GameController::class, 'map'])->name('map');
    Route::get('/mail', [GameController::class, 'mail'])->name('mail');
    Route::get('/mail/{id}', [GameController::class, 'mailShow'])->whereNumber('id')->name('mail.show');
    Route::get('/trips/{id}', [GameController::class, 'trip'])->whereNumber('id')->name('trip');
    Route::get('/leaderboard', [GameController::class, 'leaderboard'])->name('leaderboard');
    Route::get('/leaderboard/{id}', [GameController::class, 'adventurer'])->whereNumber('id')->name('leaderboard.show');
    Route::get('/bots', [GameController::class, 'bots'])->name('bots');
    Route::get('/arena', [GameController::class, 'arena'])->name('arena');
    Route::get('/account', [GameController::class, 'account'])->name('account');
    Route::get('/runs', [GameController::class, 'runs'])->middleware('admin')->name('runs');

    Route::middleware('admin')->group(function () {
        Route::get('/admin', [AdminController::class, 'index'])->name('admin');
        Route::get('/admin/gear/create', [AdminController::class, 'gearCreate'])->name('admin.gear.create');
        Route::get('/admin/gear/{id}/edit', [AdminController::class, 'gearEdit'])->whereNumber('id')->name('admin.gear.edit');
    });

    Route::middleware(['admin', 'run.guard'])->group(function () {
        Route::post('/admin/gear', [AdminController::class, 'gearStore'])->name('admin.gear.store');
        Route::put('/admin/gear/{id}', [AdminController::class, 'gearUpdate'])->whereNumber('id')->name('admin.gear.update');
        Route::delete('/admin/gear/{id}', [AdminController::class, 'gearDestroy'])->whereNumber('id')->name('admin.gear.destroy');
    });

    Route::middleware('run.guard')->group(function () {
        Route::post('/character/points', [GameController::class, 'spendPoints']);
        Route::post('/character/prep', [GameController::class, 'prep']);
        Route::post('/character/respec', [GameController::class, 'respec']);
        Route::post('/character/skills/{code}', [GameController::class, 'trainSkill'])->where('code', '[a-z_]+');
        Route::post('/character/horcrux', [GameController::class, 'feedHorcrux']);

        Route::post('/pack/equip', [GameController::class, 'equip']);
        Route::post('/pack/unequip', [GameController::class, 'unequip']);
        Route::post('/pack/belt', [GameController::class, 'belt']);
        Route::post('/pack/bandage', [GameController::class, 'bandage']);
        Route::post('/pack/chests/{id}/open', [GameController::class, 'openChest'])->whereNumber('id');
        Route::post('/pack/forge', [GameController::class, 'forge']);

        Route::post('/market/buy', [MarketController::class, 'buy']);
        Route::post('/market/sell-item', [MarketController::class, 'sellItem']);
        Route::post('/market/sell-consumable', [MarketController::class, 'sellConsumable']);

        Route::post('/premium/buy', [PremiumController::class, 'buy']);
        Route::post('/premium/wear', [PremiumController::class, 'wear']);
        Route::post('/premium/take-off', [PremiumController::class, 'takeOff']);

        Route::post('/map/order', [GameController::class, 'order']);
        Route::delete('/map/order', [GameController::class, 'cancelOrder']);

        Route::post('/arena/enter', [GameController::class, 'enterArena']);
        Route::delete('/arena/enter', [GameController::class, 'leaveArena']);

        Route::post('/mail/read-all', [GameController::class, 'mailReadAll']);
        Route::delete('/mail/{id}', [GameController::class, 'mailDelete'])->whereNumber('id');

        Route::post('/account/password', [GameController::class, 'changePassword']);
    });
});
