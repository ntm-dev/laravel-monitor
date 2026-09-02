<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LaravelMonitor\Contracts\HashResolver;
use LaravelMonitor\Livewire\UserDetail;
use LaravelMonitor\Support\KeyHash;
use Livewire\Livewire;

class UserDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_and_entries_are_scoped_to_the_given_user_only(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEntry('request', '2xx', $now->subMinutes(5), 'GET /users', userId: 'web:1');
        $this->insertEntry('request', '4xx', $now->subMinutes(4), 'GET /users/1', userId: 'web:1');
        // Another user's activity must never leak into web:1's own numbers.
        $this->insertEntry('request', '2xx', $now->subMinutes(3), 'GET /posts', userId: 'web:2');
        // Guest activity (no user_id) must never leak in either.
        $this->insertEntry('request', '2xx', $now->subMinutes(2), 'GET /home');

        $component = Livewire::test(UserDetail::class, ['userId' => 'web:1']);

        $this->assertSame('web:1', $component->viewData('userId'));
        $this->assertSame(2, $component->viewData('stats')->count);
        $this->assertSame(1, $component->viewData('okRequests'));
        $this->assertSame(1, $component->viewData('clientErrors'));
        $this->assertSame(0, $component->viewData('serverErrors'));
        $this->assertSame(2, $component->viewData('totalEntries'));
        $this->assertCount(2, $component->viewData('entries'));
    }

    public function test_last_seen_reflects_the_most_recent_activity_of_any_type(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEntry('request', '2xx', $now->subMinutes(10), 'GET /users', userId: 'web:1');
        $lastSeen = $now->subMinutes(1);
        $this->insertEntry('job', 'queued', $lastSeen, 'App\\Jobs\\SendReport', userId: 'web:1');

        $component = Livewire::test(UserDetail::class, ['userId' => 'web:1']);

        $this->assertTrue($lastSeen->equalTo($component->viewData('lastSeen')));
    }

    public function test_top_and_slowest_routes_are_ranked_and_scoped_to_the_given_user(): void
    {
        $now = CarbonImmutable::now();

        // /users: most-hit for web:1 (3 calls), but fast.
        $this->insertEntry('request', '2xx', $now->subMinutes(9), 'GET /users', 10.0, 'web:1');
        $this->insertEntry('request', '2xx', $now->subMinutes(8), 'GET /users', 10.0, 'web:1');
        $this->insertEntry('request', '2xx', $now->subMinutes(7), 'GET /users', 10.0, 'web:1');
        // /reports: only 1 call, but slow — should top the slowest list, not the top-routes one.
        $this->insertEntry('request', '2xx', $now->subMinutes(6), 'GET /reports', 500.0, 'web:1');
        // Another user's traffic on the same slow route must never leak into web:1's ranking.
        $this->insertEntry('request', '2xx', $now->subMinutes(5), 'GET /reports', 900.0, 'web:2');

        $component = Livewire::test(UserDetail::class, ['userId' => 'web:1']);

        $topRoutes = $component->viewData('topRoutes');
        $this->assertSame('GET /users', $topRoutes->first()->key);
        $this->assertSame(3, $topRoutes->first()->count);

        $slowestRoutes = $component->viewData('slowestRoutes');
        $this->assertSame('GET /reports', $slowestRoutes->first()->key);
        $this->assertSame(1, $slowestRoutes->first()->count);
    }

    public function test_status_filter_narrows_entries_to_just_that_status_group_within_the_user(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEntry('request', '2xx', $now->subMinutes(5), 'GET /users', userId: 'web:1');
        $this->insertEntry('request', '4xx', $now->subMinutes(4), 'GET /users/1', userId: 'web:1');
        $this->insertEntry('request', '5xx', $now->subMinutes(3), 'GET /users/2', userId: 'web:1');
        // Another user's 4xx entry must never count toward web:1's own badge.
        $this->insertEntry('request', '4xx', $now->subMinutes(2), 'GET /users/1', userId: 'web:2');

        $component = Livewire::test(UserDetail::class, ['userId' => 'web:1'])
            ->call('setStatusFilter', '4xx');

        $this->assertSame('4xx', $component->get('statusFilter'));
        $this->assertSame(1, $component->viewData('totalEntries'));
        $this->assertCount(1, $component->viewData('entries'));
        $this->assertSame('4xx', $component->viewData('entries')->first()->subtype);

        $counts = $component->viewData('statusFilterCounts');
        $this->assertSame(3, $counts['all']);
        $this->assertSame(1, $counts['4xx']);
        $this->assertSame(1, $counts['5xx']);
    }

    public function test_duration_filter_keeps_only_entries_at_or_above_the_configured_threshold(): void
    {
        $now = CarbonImmutable::now();

        config(['monitor.thresholds.request' => 200]);

        $this->insertEntry('request', '2xx', $now->subMinutes(5), 'GET /fast', 50.0, 'web:1');
        $this->insertEntry('request', '2xx', $now->subMinutes(4), 'GET /slow', 300.0, 'web:1');

        $component = Livewire::test(UserDetail::class, ['userId' => 'web:1'])
            ->call('setDurationFilter', 'threshold');

        $this->assertSame('threshold', $component->get('durationFilter'));
        $this->assertSame(1, $component->viewData('totalEntries'));
        $this->assertSame('GET /slow', $component->viewData('entries')->first()->key);
    }

    public function test_user_id_hash_resolves_back_to_the_original_user_id(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEntry('request', '2xx', $now->subMinutes(5), 'GET /users', userId: 'web:1');

        $hash = KeyHash::for('web:1');

        $this->assertSame('web:1', app(HashResolver::class)->resolveUserIdHash($hash));
    }

    public function test_dashboard_route_renders_the_user_detail_page(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEntry('request', '2xx', $now->subMinutes(5), 'GET /users', userId: 'web:1');

        Gate::define('viewMonitor', fn ($user = null) => true);

        $this->get('/monitor/users/'.KeyHash::for('web:1'))
            ->assertOk()
            ->assertSee('web:1');
    }

    protected function insertEntry(
        string $type,
        ?string $subtype,
        CarbonImmutable $createdAt,
        ?string $key = null,
        ?float $duration = null,
        int|string|null $userId = null,
    ): void {
        DB::table('monitor_entries')->insert([
            'type' => $type,
            'subtype' => $subtype,
            'key' => $key,
            'payload' => '[]',
            'duration' => $duration,
            'user_id' => $userId,
            'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
        ]);
    }
}
