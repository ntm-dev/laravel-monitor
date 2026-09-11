<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Support\SetupCode;

/**
 * First-run flow: monitor_users starts empty, and store() always creates
 * that first account as role=owner (later invites pick a role explicitly).
 * verifyCode() must confirm the visitor has shell access — paste the code
 * from `artisan monitor:setup-code` — before show()/store() unlock the form.
 */
class SetupController
{
    protected const SESSION_VERIFIED_KEY = 'monitor_setup_verified';

    public function show(Request $request): View|RedirectResponse
    {
        if (MonitorUser::query()->exists()) {
            return redirect()->route('monitor.login');
        }

        if (! $request->session()->get(self::SESSION_VERIFIED_KEY, false)) {
            return view('monitor::auth.setup-verify');
        }

        return view('monitor::auth.setup');
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        if (MonitorUser::query()->exists()) {
            return redirect()->route('monitor.login');
        }

        $validated = $request->validate(['code' => ['required', 'string']]);

        if (! SetupCode::verify(trim($validated['code']))) {
            throw ValidationException::withMessages([
                'code' => __('monitor::messages.auth.setup_code_invalid'),
            ]);
        }

        // Consumed the moment it's verified, not left valid until store()
        // succeeds — otherwise the same code stays replayable for up to its
        // full 10-minute TTL after a visitor has already used it once.
        SetupCode::clear();

        $request->session()->put(self::SESSION_VERIFIED_KEY, true);

        return redirect()->route('monitor.setup');
    }

    public function store(Request $request): RedirectResponse
    {
        if (MonitorUser::query()->exists()) {
            return redirect()->route('monitor.login');
        }

        if (! $request->session()->get(self::SESSION_VERIFIED_KEY, false)) {
            return redirect()->route('monitor.setup');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $owner = MonitorUser::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'owner',
        ]);

        Auth::guard(MonitorUser::guardName())->login($owner);
        $request->session()->regenerate();
        $request->session()->forget(self::SESSION_VERIFIED_KEY);

        // Straight to Settings, not the dashboard: outside local, the
        // heavy recorders (see Settings::HEAVY_RECORDERS) start disabled,
        // so this is where the new owner reviews/enables them.
        return redirect()->route('monitor.dashboard', ['tab' => 'settings']);
    }
}
