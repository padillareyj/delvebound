<?php

namespace App\Http\Controllers;

use App\Services\AccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function showLogin(): Response
    {
        return Inertia::render('auth/Login', ['allowSignup' => config('delvebound.allow_signup')]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $result = $this->accounts->attempt($data['username'], $data['password']);
        if (is_string($result)) {
            throw ValidationException::withMessages(['username' => $result]);
        }
        Auth::login($result);
        $request->session()->regenerate();

        return redirect()->intended('/')->with('success', "Welcome back, {$result->username}.");
    }

    public function showRegister(): Response|RedirectResponse
    {
        if (! config('delvebound.allow_signup')) {
            return redirect('/login')->with('error', 'Sign-up is closed.');
        }

        return Inertia::render('auth/Register', ['weapons' => $this->accounts->starterWeapons()]);
    }

    public function register(Request $request): RedirectResponse
    {
        abort_unless(config('delvebound.allow_signup'), 403, 'Sign-up is closed.');
        $data = $request->validate([
            'username' => ['required', 'string', 'regex:'.AccountService::USERNAME_RULE],
            'email' => ['nullable', 'email', 'max:190'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)],
            'character_name' => ['required', 'string', 'regex:'.AccountService::CHARACTER_NAME_RULE],
            'weapon' => ['required', 'string', 'max:40'],
        ], [
            'username.regex' => 'Username must be 3–32 letters, numbers, or underscores.',
            'character_name.regex' => 'Character name must be 2–32 characters: letters, numbers, spaces, apostrophes, or hyphens.',
        ]);
        $account = $this->accounts->register($data['username'], $data['email'] ?? null, $data['password'], trim($data['character_name']), $data['weapon']);
        Auth::login($account);
        $request->session()->regenerate();

        return redirect('/')->with('success', "Welcome to Lanternford, {$data['character_name']}! Spend your 5 stat points, then pick a destination on the map.");
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
