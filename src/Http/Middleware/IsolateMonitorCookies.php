<?php

namespace LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

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
 */
class IsolateMonitorCookies
{
    public function handle(Request $request, Closure $next)
    {
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
}
