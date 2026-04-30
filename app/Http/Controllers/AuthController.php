<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Auth\OwnerBootstrapper;

class AuthController extends Controller
{
    public function create(OwnerBootstrapper $bootstrapper): RedirectResponse
    {
        $bootstrapper->ensureOwnerUser();

        return redirect('/admin/login');
    }

    public function store(Request $request, OwnerBootstrapper $bootstrapper): RedirectResponse
    {
        $bootstrapper->ensureOwnerUser();

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt($credentials, true)) {
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        return back()
            ->withErrors(['email' => 'Неверный email или пароль.'])
            ->onlyInput('email');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
