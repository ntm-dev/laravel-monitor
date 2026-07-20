<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Models\MonitorInvitation;
use LaravelMonitor\Models\MonitorUser;

class InvitationController
{
    public function show(string $token): View
    {
        $invitation = MonitorInvitation::findByPlainToken($token);

        abort_if($invitation === null, 404);

        return view('monitor::auth.accept-invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'expired' => $invitation->isExpired(),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = MonitorInvitation::findByPlainToken($token);

        abort_if($invitation === null, 404);
        abort_if($invitation->isExpired(), 410);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = MonitorUser::create([
            'name' => $validated['name'],
            'email' => $invitation->email,
            'password' => Hash::make($validated['password']),
            'role' => $invitation->role,
        ]);

        $invitation->delete();

        Auth::guard(MonitorUser::guardName())->login($user);

        return redirect()->route('monitor.dashboard');
    }
}
