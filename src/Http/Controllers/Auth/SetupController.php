<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Models\MonitorUser;

/**
 * First-run flow: when monitor_users is empty, the dashboard is
 * unreachable until someone creates the owner account here. That first
 * account always gets role=owner — every account created afterwards
 * (sub-project 2's invite flow) picks its role explicitly instead.
 */
class SetupController
{
    public function show(): View|RedirectResponse
    {
        if (MonitorUser::query()->exists()) {
            return redirect()->route('monitor.login');
        }

        return view('monitor::auth.setup');
    }

    public function store(Request $request): RedirectResponse
    {
        if (MonitorUser::query()->exists()) {
            return redirect()->route('monitor.login');
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

        return redirect()->route('monitor.dashboard');
    }
}
