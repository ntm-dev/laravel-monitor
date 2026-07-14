<?php

namespace LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelMonitor\Monitor;

/**
 * Marks the request lifecycle boundaries used by the Request Detail
 * timeline: bootstrap, controller and (best-effort) render.
 *
 * Pushed onto the *end* of the global middleware stack by
 * MonitorServiceProvider, so it runs last among global middleware — as
 * close to routing as possible — without requiring the host app to edit
 * its HTTP kernel. See Http\Middleware\MarkControllerStart for the
 * matching route-group marker that brackets the "middleware" phase.
 */
class RecordTimeline
{
    public function __construct(protected Monitor $monitor)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $this->monitor->beginRequest();

        $response = $next($request);

        $this->recordControllerAndRenderPhases();
        $this->monitor->markResponseReady();

        return $response;
    }

    /**
     * Best-effort split of "controller" vs "render": if a view started
     * composing after the controller began and before the response was
     * ready, everything from that point on is attributed to rendering.
     */
    protected function recordControllerAndRenderPhases(): void
    {
        $controllerStart = $this->monitor->controllerStartOffset();
        $now = $this->monitor->elapsedMs();

        if ($controllerStart === null || $now === null) {
            return;
        }

        $composing = $this->monitor->firstComposingOffset();
        $renderStart = $composing !== null && $composing > $controllerStart && $composing < $now
            ? $composing
            : null;

        $controllerEnd = $renderStart ?? $now;

        $this->monitor->recordPhase('controller', $controllerStart, $controllerEnd - $controllerStart);

        if ($renderStart !== null) {
            $this->monitor->recordPhase('render', $renderStart, $now - $renderStart);
        }
    }
}
