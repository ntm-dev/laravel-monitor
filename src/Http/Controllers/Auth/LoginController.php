<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // A single "email or username" field: username is optional per
        // MonitorUser, so most accounts only ever match on email.
        $user = MonitorUser::query()
            ->where('email', $credentials['login'])
            ->orWhere('username', $credentials['login'])
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            event(new Failed(MonitorUser::guardName(), $user, $credentials));

            throw ValidationException::withMessages([
                'login' => 'These credentials do not match our records.',
            ]);
        }

        if ($user->hasTotpEnabled()) {
            $request->session()->put('monitor_2fa_challenge_user_id', $user->id);

            return redirect()->route('monitor.two-factor.challenge');
        }

        Auth::guard(MonitorUser::guardName())->login($user);
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
