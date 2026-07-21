<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use LaravelMonitor\Models\MonitorOauthAccount;
use LaravelMonitor\Models\MonitorUser;

class OAuthController
{
    public function redirect(string $provider): RedirectResponse
    {
        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $socialiteUser = Socialite::driver($provider)->user();

        $user = MonitorUser::query()->where('email', $socialiteUser->getEmail())->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => 'No dashboard account uses this email — ask an owner/admin to invite you.',
            ]);
        }

        MonitorOauthAccount::query()->updateOrCreate(
            ['provider' => $provider, 'provider_user_id' => $socialiteUser->getId()],
            ['user_id' => $user->id],
        );

        \Illuminate\Support\Facades\Auth::guard(MonitorUser::guardName())->login($user);
        request()->session()->regenerate();

        return redirect()->route('monitor.dashboard');
    }
}
