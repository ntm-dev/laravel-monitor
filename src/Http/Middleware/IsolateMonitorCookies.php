<?php

namespace LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelMonitor\Support\LivewireSnapshot;
use Symfony\Component\HttpFoundation\Cookie;

use function str_starts_with;

/**
 * Gives the dashboard its own session and XSRF cookies so host-app logouts and
 * CSRF tokens don't clobber it. Runs before the 'web' group; also prepended to
 * 'web' for Livewire's shared update route (see
 * MonitorServiceProvider::registerLivewireUpdateCookieIsolation()).
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
     * True for monitor routes and Livewire updates for monitor components. Not a
     * path-prefix check: host routes may share the monitor prefix.
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
     * Monitor routes list this class explicitly in their middleware (routes/web.php),
     * unlike host routes that only get it via 'web'.
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
     * Livewire-branch guard: when monitor.domain is set, the request host must match.
     */
    private function matchesConfiguredMonitorDomain(Request $request): bool
    {
        $domain = config('monitor.domain');

        return $domain === null || $domain === '' || $request->getHost() === $domain;
    }

    /**
     * Any snapshot named "monitor.*" (see MonitorServiceProvider::registerLivewireComponents());
     * dashboard pages never batch host components alongside.
     */
    private function payloadTargetsMonitorComponent(Request $request): bool
    {
        foreach (LivewireSnapshot::names($request) as $name) {
            if (str_starts_with($name, 'monitor.')) {
                return true;
            }
        }

        return false;
    }
}
