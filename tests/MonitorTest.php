<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Facades\Monitor;

class MonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_entries_and_flushes_to_storage(): void
    {
        Monitor::record('request', 'GET /users', ['status' => 200], 120, '2xx', 1);
        Monitor::record('request', 'GET /users', ['status' => 200], 80, '2xx', 1);
        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 2);
        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'request',
            'key' => 'GET /users',
            'subtype' => '2xx',
            'user_id' => 1,
        ]);
    }

    public function test_aggregates_by_key(): void
    {
        Monitor::record('request', 'GET /users', [], 100, '2xx');
        Monitor::record('request', 'GET /users', [], 300, '2xx');
        Monitor::record('request', 'GET /posts', [], 50, '2xx');
        Monitor::flush();

        $groups = app(Storage::class)->aggregateByKey('request', CarbonImmutable::now()->subHour());

        $this->assertCount(2, $groups);
        $this->assertSame('GET /users', $groups->first()->key);
        $this->assertSame(2, $groups->first()->count);
        $this->assertSame(200.0, $groups->first()->avg_duration);
        $this->assertSame(300, $groups->first()->max_duration);
    }

    public function test_stats_and_recent_and_purge(): void
    {
        Monitor::record('exception', 'RuntimeException', ['message' => 'boom']);
        Monitor::flush();

        $storage = app(Storage::class);
        $since = CarbonImmutable::now()->subHour();

        $this->assertSame(1, $storage->stats('exception', $since)->count);
        $this->assertSame('boom', $storage->recent('exception', $since)->first()->payload['message']);

        $storage->purge();
        $this->assertDatabaseCount('monitor_entries', 0);
    }

    public function test_slow_query_recorder_captures_queries_over_threshold(): void
    {
        config(['monitor.recorders.'.\LaravelMonitor\Recorders\SlowQueries::class.'.threshold' => 100]);

        event(new QueryExecuted('select * from users', [], 250.0, DB::connection()));
        event(new QueryExecuted('select * from posts', [], 5.0, DB::connection()));

        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 1);
        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'slow_query',
            'key' => 'select * from users',
            'duration' => 250,
        ]);
    }

    public function test_recording_can_be_disabled(): void
    {
        config(['monitor.enabled' => false]);

        Monitor::record('request', 'GET /users');
        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 0);
    }

    public function test_dashboard_is_protected_by_gate(): void
    {
        $this->get('/monitor')->assertForbidden();
    }

    public function test_dashboard_renders_when_authorized(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record('request', 'GET /users', ['status' => 200], 120, '2xx');
        Monitor::flush();

        $this->get('/monitor')
            ->assertOk()
            ->assertSee('Monitor')
            ->assertSee('Requests');
    }

    public function test_every_tab_renders(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record('request', 'GET /users', ['status' => 200], 120, '2xx', 1);
        Monitor::record('exception', 'RuntimeException', ['class' => 'RuntimeException', 'message' => 'boom', 'file' => 'app/X.php', 'line' => 1]);
        Monitor::record('slow_query', 'select * from users', ['sql' => 'select * from users'], 250);
        Monitor::record('job', 'App\\Jobs\\SendEmail', ['queue' => 'default'], 40, 'processed');
        Monitor::record('scheduled_task', 'inspire', ['command' => 'inspire'], 12, 'finished');
        Monitor::record('cache', 'users:1', [], null, 'hit');
        Monitor::record('outgoing_request', 'GET https://api.example.com', ['status' => 200], 90, 'success');
        Monitor::record('mail', 'Welcome', ['subject' => 'Welcome', 'to' => 'a@b.c']);
        Monitor::record('notification', 'App\\Notifications\\Invoice', ['channel' => 'mail'], null, 'mail');
        Monitor::record('log', 'Something happened', ['message' => 'Something happened', 'level' => 'warning'], null, 'warning');
        Monitor::record('auth', 'a@b.c', ['guard' => 'web'], null, 'login', 1);
        Monitor::flush();

        foreach (['overview', 'requests', 'exceptions', 'queries', 'jobs', 'schedule', 'cache', 'outgoing', 'mail', 'users', 'logs'] as $tab) {
            $response = $this->get('/monitor?tab='.$tab)->assertOk();

            if (($dir = getenv('MONITOR_DUMP_HTML')) !== false) {
                file_put_contents($dir.'/'.$tab.'.html', $response->getContent());
            }
        }
    }
}
