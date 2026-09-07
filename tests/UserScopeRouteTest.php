<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use LaravelMonitor\Contracts\AggregateStorage;
use LaravelMonitor\Facades\Monitor;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Support\UserFilter;

/**
 * The dashboard's user scope lives in the URL path (`/{tab}/userId/{userId?}`),
 * not in a `?userId=` query string — see routes/web.php.
 */
class UserScopeRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_scope_is_generated_as_a_path_segment_not_a_query_string(): void
    {
        // ':' stays literal here — RouteUrlGenerator's $dontEncode map exempts
        // it, unlike the old '?userId=store-web%3A1483' query-string form.
        $this->assertStringEndsWith(
            '/monitor/requests/userId/store-web:1483?period=24h',
            route('monitor.dashboard.user', ['tab' => 'requests', 'userId' => 'store-web:1483', 'period' => '24h']),
        );
    }

    public function test_dropping_the_trailing_segment_still_generates_a_valid_url(): void
    {
        $this->assertStringEndsWith(
            '/monitor/exceptions/userId?period=24h',
            route('monitor.dashboard.user', ['tab' => 'exceptions', 'period' => '24h']),
        );
    }

    /**
     * `/requests/{requestId}` carries no where() constraint, so route order in
     * routes/web.php is the only thing stopping it from swallowing this URL
     * and 404ing on an unknown "userId" request id.
     */
    public function test_bare_user_scope_url_reaches_the_dashboard_not_the_request_detail_page(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $this->get('/monitor/requests/userId')->assertOk();
    }

    public function test_scoped_url_narrows_the_route_list_to_that_one_user(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record(RecordType::Request, 'GET /only-mine', ['status' => 200], 50, '2xx', 'web:1');
        Monitor::record(RecordType::Request, 'GET /someone-else', ['status' => 200], 50, '2xx', 'web:2');
        Monitor::flush();

        $this->get('/monitor/requests/userId/web:1')
            ->assertOk()
            ->assertSee('/only-mine')
            ->assertDontSee('/someone-else');
    }

    public function test_bare_user_scope_keeps_authenticated_traffic_and_drops_guests(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record(RecordType::Request, 'GET /signed-in', ['status' => 200], 50, '2xx', 'web:1');
        Monitor::record(RecordType::Request, 'GET /anonymous', ['status' => 200], 50, '2xx');
        Monitor::flush();

        $this->get('/monitor/requests/userId')
            ->assertOk()
            ->assertSee('/signed-in')
            ->assertDontSee('/anonymous');
    }

    public function test_unscoped_dashboard_url_still_shows_guests_and_signed_in_users_alike(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record(RecordType::Request, 'GET /signed-in', ['status' => 200], 50, '2xx', 'web:1');
        Monitor::record(RecordType::Request, 'GET /anonymous', ['status' => 200], 50, '2xx');
        Monitor::flush();

        $this->get('/monitor/requests')
            ->assertOk()
            ->assertSee('/signed-in')
            ->assertSee('/anonymous');
    }

    public function test_authenticated_sentinel_excludes_guests_at_the_storage_layer(): void
    {
        Monitor::record(RecordType::Request, 'GET /signed-in', ['status' => 200], 50, '2xx', 'web:1');
        Monitor::record(RecordType::Request, 'GET /also-signed-in', ['status' => 200], 50, '2xx', 'web:2');
        Monitor::record(RecordType::Request, 'GET /anonymous', ['status' => 200], 50, '2xx');
        Monitor::flush();

        $storage = app(AggregateStorage::class);
        $since = now()->subHour();

        $this->assertSame(3, $storage->stats('request', $since)->count);
        $this->assertSame(2, $storage->stats('request', $since, userId: UserFilter::AUTHENTICATED)->count);
        $this->assertSame(1, $storage->stats('request', $since, userId: 'web:1')->count);
    }
}
