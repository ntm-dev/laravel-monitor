<?php

namespace LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Runs first in the monitor route group, before the 'web' group's own
 * StartSession middleware reads config('session.cookie') to resolve the
 * request's session cookie name. Without this, the dashboard shares the
 * host app's session cookie: a typical logout flow invalidates the whole
 * session (not just its own guard's key within it), which would silently
 * log the monitor guard out too since both lived in the same session.
 * Overriding the cookie name here gives the dashboard its own session,
 * decoupled from the host app's.
 */
class SetMonitorSessionCookie
{
    public function handle(Request $request, Closure $next)
    {
        config(['session.cookie' => config('monitor.session.cookie', 'monitor_session')]);

        return $next($request);
    }
}
