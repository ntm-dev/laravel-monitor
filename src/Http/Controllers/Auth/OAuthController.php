<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use LaravelMonitor\Models\MonitorOauthAccount;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Support\OptionalAuthMethod;

class OAuthController
{
    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(OptionalAuthMethod::oauthAvailable($provider), 404);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        abort_unless(OptionalAuthMethod::oauthAvailable($provider), 404);

        try {
            $socialiteUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            // The state/code query params are attacker/client-controlled and can fail in ways
            // that throw before reaching validation — e.g., InvalidStateException when state
            // is tampered/missing, or Guzzle exceptions for network failures. Treat as
            // validation error to show a clean error message instead of a 500.
            throw ValidationException::withMessages([
                'email' => 'Failed to authenticate with ' . $provider . '. Please try again.',
            ]);
        }

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
