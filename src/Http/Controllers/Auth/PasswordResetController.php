<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use LaravelMonitor\Mail\PasswordResetMail;
use LaravelMonitor\Models\MonitorPasswordReset;
use LaravelMonitor\Models\MonitorUser;

class PasswordResetController
{
    public function showRequestForm(): View
    {
        return view('monitor::auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email']]);

        $user = MonitorUser::query()->where('email', $validated['email'])->first();

        if ($user !== null) {
            ['plainToken' => $plainToken] = MonitorPasswordReset::createFor($validated['email']);

            Mail::to($validated['email'])->send(new PasswordResetMail($plainToken));
        }

        return back()->with('status', 'If that email has an account, we’ve sent a password reset link.');
    }

    public function showResetForm(string $token): View
    {
        $reset = MonitorPasswordReset::findByPlainToken($token);

        abort_if($reset === null, 404);
        abort_if($reset->isExpired(), 404);

        return view('monitor::auth.reset-password', ['token' => $token]);
    }

    public function resetPassword(Request $request, string $token): RedirectResponse
    {
        $reset = MonitorPasswordReset::findByPlainToken($token);

        abort_if($reset === null, 404);
        abort_if($reset->isExpired(), 404);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $claimed = MonitorPasswordReset::query()->where('id', $reset->id)->delete();

        abort_if($claimed === 0, 404);

        $user = MonitorUser::query()->where('email', $reset->email)->firstOrFail();
        $user->update(['password' => Hash::make($validated['password'])]);

        Auth::guard(MonitorUser::guardName())->login($user);

        return redirect()->route('monitor.dashboard');
    }
}
