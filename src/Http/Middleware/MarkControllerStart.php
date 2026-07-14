<?php

namespace LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelMonitor\Monitor;

/**
 * Marks the boundary between route middleware and the controller for the
 * Request Detail timeline. Pushed onto the "web"/"api" route middleware
 * groups by MonitorServiceProvider, as the last middleware before the
 * controller runs.
 */
class MarkControllerStart
{
    public function __construct(protected Monitor $monitor)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $this->monitor->markControllerStart();

        return $next($request);
    }
}
