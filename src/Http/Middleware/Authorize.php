<?php

namespace LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LaravelMonitor\Support\Preferences;

class Authorize
{
    public function handle(Request $request, Closure $next)
    {
        // Applied before the gate check (and before any label/heading is resolved)
        // so unauthenticated auth-flow pages (login, invitations, ...) translate too.
        app()->setLocale(Preferences::locale());

        abort_unless(Gate::allows('viewMonitor', [$request->user()]), 403);

        return $next($request);
    }
}
