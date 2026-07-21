<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use LaravelMonitor\Models\MonitorEmailChange;

class EmailChangeController
{
    public function show(string $token): View
    {
        $emailChange = MonitorEmailChange::findByPlainToken($token);

        abort_if($emailChange === null, 404);
        abort_if($emailChange->isExpired(), 404);

        return view('monitor::auth.email-change-verify', [
            'emailChange' => $emailChange,
            'token' => $token,
        ]);
    }

    public function store(string $token): View
    {
        $emailChange = MonitorEmailChange::findByPlainToken($token);

        abort_if($emailChange === null, 404);
        abort_if($emailChange->isExpired(), 404);

        $emailChange->forceFill(['verified_at' => now()])->save();

        $requester = $emailChange->user;
        $applied = false;
        $newEmail = $emailChange->new_email;

        if ($requester->isOwner()) {
            $requester->update(['email' => $newEmail]);
            $emailChange->delete();
            $applied = true;
        }

        return view('monitor::auth.email-change-verified', [
            'applied' => $applied,
            'newEmail' => $newEmail,
        ]);
    }
}
