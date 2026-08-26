<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

/**
 * Regression coverage for the dashboard sharing the host app's session and
 * CSRF cookies (see IsolateMonitorCookies):
 * - Same session cookie name: a host app logout that invalidates its own
 *   session — a common Laravel logout pattern — would silently log the
 *   monitor guard out too, since both lived in the very same session.
 * - Same XSRF-TOKEN cookie name (hardcoded by the framework, not derived
 *   from session.cookie): once the two apps' sessions were split apart,
 *   each still tried to write its own CSRF token under that one shared
 *   cookie name, so whichever app responded last clobbered the other's
 *   token — producing 419s on the app that didn't.
 */
class SessionCookieIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The "array" session driver (Testbench's own default) never queues
        // a session cookie on the response at all, so cookie-name isolation
        // can't be observed against it — swap in a persistent driver.
        $sessionPath = sys_get_temp_dir().'/laravel-monitor-test-sessions';
        if (! is_dir($sessionPath)) {
            mkdir($sessionPath);
        }

        $app['config']->set('session.driver', 'file');
        $app['config']->set('session.files', $sessionPath);
        $app['config']->set('session.cookie', 'laravel_session');
    }

    public function test_the_dashboard_does_not_reuse_the_host_apps_session_cookie_name(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        // setUp()'s actingAs() call already resolved the default session
        // driver (and cached it on the SessionManager singleton) under the
        // host cookie name configured above — a container-lifetime artifact
        // of the test harness sharing one app across setUp() and the actual
        // request, not something a real per-request boot goes through.
        // Forget it so the request below builds its session fresh, the way
        // a real request does, and actually exercises SetMonitorSessionCookie.
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');

        $response = $this->get('/monitor');

        $response->assertCookie(config('monitor.session.cookie'));
        $response->assertCookieMissing('laravel_session');
    }

    public function test_the_dashboard_does_not_reuse_the_host_apps_xsrf_token_cookie_name(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        // Same container-lifetime artifact as the session-cookie test above.
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');

        $response = $this->get('/monitor');

        $response->assertCookie(config('monitor.session.xsrf_cookie'));
        $response->assertCookieMissing('XSRF-TOKEN');
    }
}
