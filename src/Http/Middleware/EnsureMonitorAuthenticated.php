<?php

namespace LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LaravelMonitor\Models\MonitorUser;

/**
 * Guards every dashboard route except the auth flow (setup, login, 2FA,
 * invitations, password reset, ...), which must stay reachable signed out.
 */
class EnsureMonitorAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::guard(MonitorUser::guardName())->check()) {
            return $next($request);
        }

        if (MonitorUser::query()->doesntExist()) {
            return redirect()->route('monitor.setup');
        }

        return redirect()->route('monitor.login');
    }
}
