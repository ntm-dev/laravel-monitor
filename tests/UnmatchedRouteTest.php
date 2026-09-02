<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelMonitor\Contracts\AggregateStorage;
use LaravelMonitor\Contracts\HashResolver;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Facades\Monitor;
use LaravelMonitor\Livewire\Requests;
use LaravelMonitor\Recorders\Requests as RequestsRecorder;
use LaravelMonitor\Support\KeyHash;
use LaravelMonitor\Support\RecordType;
use Livewire\Livewire;

class UnmatchedRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_stats_merges_unmatched_route_methods_into_one_row(): void
    {
        Monitor::record(RecordType::Request, 'GET '.RequestsRecorder::UNMATCHED_ROUTE, [], 100, '4xx');
        Monitor::record(RecordType::Request, 'POST '.RequestsRecorder::UNMATCHED_ROUTE, [], 50, '4xx');
        Monitor::record(RecordType::Request, 'GET /users', [], 20, '2xx');
        Monitor::flush();

        $routes = app(AggregateStorage::class)->routeStats('request', CarbonImmutable::now()->subHour());

        $this->assertCount(2, $routes);

        $unmatched = $routes->firstWhere('key', RequestsRecorder::UNMATCHED_ROUTE);
        $this->assertNotNull($unmatched);
        $this->assertSame(2, $unmatched->count);
        $this->assertSame(['GET', 'POST'], $unmatched->methods);

        $normal = $routes->firstWhere('key', 'GET /users');
        $this->assertNotNull($normal);
        $this->assertNull($normal->methods);
    }

    public function test_route_list_shows_any_above_three_methods(): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            Monitor::record(RecordType::Request, $method.' '.RequestsRecorder::UNMATCHED_ROUTE, [], 10, '4xx');
        }
        Monitor::flush();

        Livewire::test(Requests::class)
            ->assertSee('ANY')
            ->assertSee(RequestsRecorder::UNMATCHED_ROUTE);
    }

    public function test_unmatched_route_hash_resolves_and_filters_across_every_method(): void
    {
        Monitor::record(RecordType::Request, 'GET '.RequestsRecorder::UNMATCHED_ROUTE, [], 10, '4xx');
        Monitor::record(RecordType::Request, 'POST '.RequestsRecorder::UNMATCHED_ROUTE, [], 20, '4xx');
        Monitor::record(RecordType::Request, 'GET /users', [], 30, '2xx');
        Monitor::flush();

        $hash = KeyHash::for(RequestsRecorder::UNMATCHED_ROUTE);

        $this->assertSame(RequestsRecorder::UNMATCHED_ROUTE, app(HashResolver::class)->resolveKeyHash('request', $hash));

        $entries = app(TimelineStorage::class)->recent('request', CarbonImmutable::now()->subHour(), key: RequestsRecorder::UNMATCHED_ROUTE);
        $this->assertCount(2, $entries);
    }
}
