<?php

namespace LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelMonitor\Monitor;

/**
 * Starts the request lifecycle used by the Request Detail timeline.
 *
 * Pushed onto the *end* of the global middleware stack by
 * MonitorServiceProvider, so it runs last among global middleware — as
 * close to routing as possible — without requiring the host app to edit
 * its HTTP kernel. Every other lifecycle boundary (controller, render,
 * unwinding, sending, terminating) is marked by framework events and the
 * matching route-group marker — see
 * MonitorServiceProvider::registerRequestTimeline().
 */
class RecordTimeline
{
    public function __construct(protected Monitor $monitor)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $this->monitor->beginRequest();

        return $next($request);
    }
}
