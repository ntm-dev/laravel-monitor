<?php

namespace LaravelMonitor\Hooks;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LaravelMonitor\Monitor;
use WeakMap;

/**
 * Marks the middleware → controller boundary (Laravel has no event there) by
 * appending a last middleware to the matched route on RouteMatched. The
 * route's own list is used because the kernel can overwrite group arrays.
 */
class ControllerStartHook
{
    /** Routes already attached to, so a re-matched Route (e.g. under Octane) isn't marked twice. */
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
