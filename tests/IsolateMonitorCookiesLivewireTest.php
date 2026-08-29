<?php

namespace LaravelMonitor\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use LaravelMonitor\Http\Middleware\IsolateMonitorCookies;

/**
 * Regression coverage for 419s on the dashboard's own Livewire components
 * after SessionCookieIsolationTest's fix split the monitor's session/XSRF
 * cookies from the host app's: Livewire serves every component's AJAX
 * update through one single, globally-shared route (registered outside the
 * monitor route group in routes/web.php), so IsolateMonitorCookies never
 * ran for it — those requests kept validating CSRF against whichever
 * session the host app's own, unisolated session cookie resolved to. A
 * host-app logout (or simply the two sessions' tokens drifting apart) then
 * broke every wire:click/poll on the dashboard with a 419, even though the
 * dashboard's own page loads worked fine.
 */
class IsolateMonitorCookiesLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function livewireUpdateRequest(string $componentName): Request
    {
        return Request::create('/livewire/update', 'POST', [], [], [], [
            'HTTP_X_LIVEWIRE' => '1',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            '_token' => 'irrelevant-for-this-test',
            'components' => [
                [
                    'snapshot' => json_encode(['memo' => ['name' => $componentName]]),
                    'updates' => [],
                    'calls' => [],
                ],
            ],
        ]));
    }

    private function configSeenInsideMiddleware(Request $request): ?string
    {
        $seen = null;

        (new IsolateMonitorCookies)->handle($request, static function () use (&$seen) {
            $seen = config('session.cookie');

            return response('ok');
        });

        return $seen;
    }

    public function test_it_isolates_cookies_for_a_livewire_update_request_targeting_a_monitor_component(): void
    {
        config(['session.cookie' => 'host_session']);

        $seen = $this->configSeenInsideMiddleware($this->livewireUpdateRequest('monitor.requests'));

        $this->assertSame(config('monitor.session.cookie'), $seen);
        $this->assertNotSame('host_session', $seen);
    }

    public function test_it_leaves_a_livewire_update_request_for_a_host_component_untouched(): void
    {
        config(['session.cookie' => 'host_session']);

        $seen = $this->configSeenInsideMiddleware($this->livewireUpdateRequest('app.dashboard'));

        $this->assertSame('host_session', $seen);
    }

    public function test_it_leaves_a_regular_non_livewire_host_request_untouched(): void
    {
        config(['session.cookie' => 'host_session']);

        $seen = $this->configSeenInsideMiddleware(Request::create('/dashboard', 'GET'));

        $this->assertSame('host_session', $seen);
    }

    /**
     * The dashboard's own pages must keep working unconditionally — a real
     * dispatch through the router, exercising the actual middleware chain
     * routes/web.php assembles for a monitor route (rather than a bare
     * Request object standing in for one).
     */
    public function test_it_isolates_cookies_for_an_actual_dispatch_of_a_monitor_route(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        config(['session.cookie' => 'host_session']);

        $this->get('/'.config('monitor.path'));

        $this->assertSame(config('monitor.session.cookie'), config('session.cookie'));
    }

    /**
     * The tightening this test locks in: identifying a monitor request by
     * route-name/path-prefix guesswork would wrongly isolate a host route
     * that happens to live under the very same path prefix (plausible once
     * MONITOR_PATH is set to something generic) — silently swapping out
     * that route's own session cookie. Only a route routes/web.php actually
     * registered (carrying this class in its own middleware list, not
     * merely inherited via the 'web' group) may trigger isolation. Built as
     * a Route the request resolves to directly (rather than a real router
     * dispatch) since monitor's own catch-all `/{tab?}` route already
     * claims every single-segment path under its prefix, making it
     * impossible for a host app to even register a route there in the
     * first place — the scenario this guards against is a host mounting
     * MONITOR_PATH's value under a path pattern monitor doesn't itself
     * claim.
     */
    public function test_a_host_route_sharing_the_monitor_path_prefix_is_left_untouched(): void
    {
        config(['session.cookie' => 'host_session']);

        $hostRoute = new Route('GET', config('monitor.path').'/totally-unrelated-host-route', fn () => 'host response');
        $hostRoute->middleware('web');

        $request = Request::create('/'.config('monitor.path').'/totally-unrelated-host-route', 'GET');
        $request->setRouteResolver(fn () => $hostRoute);

        $seen = $this->configSeenInsideMiddleware($request);

        $this->assertSame('host_session', $seen);
    }

    public function test_the_hosts_web_middleware_group_carries_the_isolation_middleware(): void
    {
        /** @var HttpKernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $this->assertContains(IsolateMonitorCookies::class, $kernel->getMiddlewareGroups()['web']);
    }
}
