<?php

namespace LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LaravelMonitor\Models\MonitorUser;

/**
 * Runs inside the existing Authorize (viewMonitor Gate) check, on every
 * route except setup/login/logout — those have to stay reachable while
 * unauthenticated, since they're how a visitor becomes authenticated.
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
