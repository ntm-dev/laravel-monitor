<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LaravelMonitor\Models\MonitorUser;

class LoginController
{
    public function show(): View
    {
        return view('monitor::auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard(MonitorUser::guardName())->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->route('monitor.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard(MonitorUser::guardName())->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('monitor.login');
    }
}
