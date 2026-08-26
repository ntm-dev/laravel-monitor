<?php

namespace LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Runs first in the monitor route group, wrapping the entire 'web' group —
 * before StartSession reads config('session.cookie') to resolve the
 * request's session, and around the CSRF middleware's own response cookie.
 * Without this, the dashboard shares two cookies with the host app it's
 * monitoring:
 * - session.cookie: a typical host-app logout invalidates its whole
 *   session (not just its own guard's key within it), which would silently
 *   log the monitor guard out too since both lived in the same session.
 * - XSRF-TOKEN: hardcoded by the framework's CSRF middleware, not derived
 *   from session.cookie — even with separate sessions, both apps still
 *   write their own CSRF token under that one shared cookie name, so
 *   whichever app responded most recently clobbers the other's token,
 *   producing 419s on the next request from the other app.
 *
 * Also prepended (see MonitorServiceProvider::registerLivewireUpdateCookieIsolation())
 * onto the host app's whole 'web' middleware group, because Livewire serves
 * every component's AJAX update through one single, globally-shared route
 * that the monitor route group never sees — without that, the dashboard's
 * own Livewire components would render with an isolated session/CSRF token,
 * but every subsequent wire:click/poll would validate that token against
 * the host app's own (unisolated) session, producing a 419 as soon as the
 * two sessions' tokens drift apart (e.g. a host-app logout regenerating its
 * token). isMonitorRequest() keeps that global registration a no-op for the
 * host app's own pages and its own Livewire components.
 */
class IsolateMonitorCookies
{
    public function handle(Request $request, Closure $next)
    {
        if (! $this->isMonitorRequest($request)) {
            return $next($request);
        }

        config(['session.cookie' => config('monitor.session.cookie', 'monitor_session')]);

        $response = $next($request);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() !== 'XSRF-TOKEN') {
                continue;
            }

            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            $response->headers->setCookie(new Cookie(
                config('monitor.session.xsrf_cookie', 'monitor_xsrf_token'),
                $cookie->getValue(),
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain(),
                $cookie->isSecure(),
                $cookie->isHttpOnly(),
                $cookie->isRaw(),
                $cookie->getSameSite(),
                $cookie->isPartitioned(),
            ));
        }

        return $response;
    }

    /**
     * True for a direct hit on one of the monitor's own routes, and for
     * Livewire's shared update endpoint when the payload it's carrying
     * belongs to one of the dashboard's own components — false for
     * everything else the host app serves under its 'web' group.
     *
     * Deliberately not a path-prefix or route-name guess (e.g. "starts with
     * config('monitor.path')"): a host app route that happens to live under
     * that same prefix — plausible if MONITOR_PATH is set to something
     * generic — would otherwise get its own session cookie silently
     * swapped out from under it.
     */
    private function isMonitorRequest(Request $request): bool
    {
        if ($this->routeIsRegisteredByMonitor($request)) {
            return true;
        }

        return $request->hasHeader('X-Livewire')
            && $this->matchesConfiguredMonitorDomain($request)
            && $this->payloadTargetsMonitorComponent($request);
    }

    /**
     * Every route routes/web.php registers for the dashboard carries this
     * very class in its own, explicit middleware list (see the
     * array_merge() at the top of that file) — not merely inherited via the
     * 'web' group string, which is exactly what a host app's own routes
     * (including ones under the same path prefix) also carry. Route
     * matching always runs before route middleware, so $request->route()
     * is already resolved here regardless of where in the 'web' group this
     * middleware sits.
     */
    private function routeIsRegisteredByMonitor(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        return in_array(self::class, (array) $route->middleware(), true);
    }

    /**
     * Extra guard for the Livewire branch only, when the dashboard is
     * mounted on its own subdomain: a host route's own Livewire component
     * could in principle be named "monitor.something" too, and a page
     * served from the host's own domain would then wrongly get its session
     * cookie swapped. No-op when MONITOR_DOMAIN isn't set, since a
     * path-mounted dashboard shares the host's domain by design.
     */
    private function matchesConfiguredMonitorDomain(Request $request): bool
    {
        $domain = config('monitor.domain');

        return $domain === null || $domain === '' || $request->getHost() === $domain;
    }

    /**
     * Livewire batches one or more components' snapshots into a single
     * update request — each snapshot is a JSON-encoded string whose memo.name
     * is the alias it was registered under (see
     * MonitorServiceProvider::registerLivewireComponents(), always prefixed
     * "monitor."). Treating the whole request as a monitor request the
     * moment any one component matches is correct here: the dashboard's own
     * pages never mix host-app components into the same Livewire payload.
     */
    private function payloadTargetsMonitorComponent(Request $request): bool
    {
        $components = $request->input('components');

        if (! is_array($components)) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = is_array($component) ? ($component['snapshot'] ?? null) : null;

            if (! is_string($snapshot)) {
                continue;
            }

            $name = json_decode($snapshot, true)['memo']['name'] ?? null;

            if (is_string($name) && str_starts_with($name, 'monitor.')) {
                return true;
            }
        }

        return false;
    }
}
