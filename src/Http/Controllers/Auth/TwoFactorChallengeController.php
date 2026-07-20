<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LaravelMonitor\Models\MonitorUser;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallengeController
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('monitor_2fa_challenge_user_id') === null) {
            return redirect()->route('monitor.login');
        }

        return view('monitor::auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('monitor_2fa_challenge_user_id');

        if ($userId === null) {
            return redirect()->route('monitor.login');
        }

        $validated = $request->validate(['code' => ['required', 'string']]);
        $user = MonitorUser::query()->findOrFail($userId);

        if ($this->isValidTotpCode($user, $validated['code']) || $this->isValidRecoveryCode($user, $validated['code'])) {
            $request->session()->forget('monitor_2fa_challenge_user_id');
            Auth::guard(MonitorUser::guardName())->login($user);
            $request->session()->regenerate();

            return redirect()->route('monitor.dashboard');
        }

        throw ValidationException::withMessages([
            'code' => 'That code did not match. Please try again.',
        ]);
    }

    protected function isValidTotpCode(MonitorUser $user, string $code): bool
    {
        return (new Google2FA())->verifyKey($user->totp_secret, $code);
    }

    protected function isValidRecoveryCode(MonitorUser $user, string $code): bool
    {
        $recoveryCodes = $user->totp_recovery_codes ?? [];

        foreach ($recoveryCodes as $index => $hashedCode) {
            if (Hash::check($code, $hashedCode)) {
                unset($recoveryCodes[$index]);
                $user->update(['totp_recovery_codes' => array_values($recoveryCodes)]);

                return true;
            }
        }

        return false;
    }
}
