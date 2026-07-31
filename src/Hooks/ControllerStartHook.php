<?php

namespace LaravelMonitor\Hooks;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LaravelMonitor\Monitor;
use WeakMap;

/**
 * Marks the middleware → controller boundary for the Request Detail
 * timeline.
 *
 * Laravel fires no event at exactly that boundary — the pipeline's
 * terminal callback (which calls Route::run()) is internal to
 * Router::runRouteWithinStack() — so this attaches itself as the matched
 * route's own last middleware entry instead, appended at RouteMatched
 * time (see MonitorServiceProvider::registerRequestTimeline()).
 *
 * Mutating the resolved Route's own middleware list directly (rather than
 * pushing onto a middleware *group* array, e.g. Router::pushMiddlewareToGroup)
 * is deliberate: Illuminate\Foundation\Http\Kernel::syncMiddlewareToRouter()
 * overwrites the router's group arrays wholesale whenever any other package
 * calls e.g. $kernel->appendMiddlewareToGroup() afterwards (commonly from
 * another provider's boot(), which runs after this package's register()),
 * silently dropping our addition. A Route's own middleware list at
 * RouteMatched time is immune to that.
 *
 * Which routes it has already attached to is tracked in a WeakMap keyed
 * by the Route object itself, kept on this hook — not written onto the
 * route's own action array. A Route object matched more than once (e.g.
 * repeated dispatch under Octane, or in tests) is never marked twice, and
 * entries fall out on their own once a Route is garbage collected.
 */
class ControllerStartHook
{
    private WeakMap $attached;

    public function __construct()
    {
        $this->attached = new WeakMap();
    }

    public function attachMiddlewareToRoute(Route $route): void
    {
        if (isset($this->attached[$route])) {
            return;
        }

        $middleware = (array) ($route->action['middleware'] ?? []);

        $middleware[] = static function (Request $request, Closure $next) {
            app(Monitor::class)->markControllerStart();

            return $next($request);
        };

        $route->action['middleware'] = $middleware;

        $this->attached[$route] = true;
    }
}
