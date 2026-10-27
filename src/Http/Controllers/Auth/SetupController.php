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
 * First-run flow: creates the first account as owner, gated behind the
 * code from `artisan monitor:setup-code` (verifyCode()).
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

        // Consume on verify so the code can't be replayed within its TTL.
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

        // Land on Settings: outside local, Settings::HEAVY_RECORDERS start disabled.
        return redirect()->route('monitor.dashboard', ['tab' => 'settings']);
    }
}
