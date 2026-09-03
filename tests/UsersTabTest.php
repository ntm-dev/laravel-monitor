<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaravelMonitor\Contracts\UserStorage;
use LaravelMonitor\Livewire\Users;
use Livewire\Livewire;

class UsersTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_counts_per_bucket_counts_distinct_users_only(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEntry('request', '2xx', $now->subMinutes(5), userId: 'web:1');
        $this->insertEntry('request', '2xx', $now->subMinutes(4), userId: 'web:1');
        $this->insertEntry('request', '2xx', $now->subMinutes(3), userId: 'web:2');
        $this->insertEntry('request', '2xx', $now->subMinutes(2));

        $counts = app(UserStorage::class)->authenticatedUserCountsPerBucket($now->subHour(), 1);

        $this->assertSame([2], $counts);
    }

    public function test_request_auth_counts_per_bucket_splits_authenticated_from_guest(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEntry('request', '2xx', $now->subMinutes(5), userId: 'web:1');
        $this->insertEntry('request', '2xx', $now->subMinutes(4), userId: 'web:2');
        $this->insertEntry('request', '2xx', $now->subMinutes(3));

        $buckets = app(UserStorage::class)->requestAuthCountsPerBucket($now->subHour(), 1);

        $this->assertSame(['authenticated' => [2], 'guest' => [1]], $buckets);
    }

    public function test_user_stats_aggregates_activity_across_request_job_and_exception_types(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEntry('request', '2xx', $now->subMinutes(10), userId: 'web:1');
        $this->insertEntry('request', '4xx', $now->subMinutes(9), userId: 'web:1');
        $this->insertEntry('job', 'queued', $now->subMinutes(8), userId: 'web:1');
        // A non-'queued' job outcome shouldn't be double-counted as another queued dispatch.
        $this->insertEntry('job', 'processed', $now->subMinutes(7), userId: 'web:1');
        $this->insertEntry('exception', null, $now->subMinutes(6), userId: 'web:1');

        $this->insertEntry('request', '5xx', $now->subMinutes(5), userId: 'web:2');

        // Guest activity (no user_id) must never surface in the per-user list.
        $this->insertEntry('request', '2xx', $now->subMinutes(4));

        $lastSeen = $now->subMinutes(2);
        $this->insertEntry('request', '2xx', $lastSeen, userId: 'web:1');

        $stats = app(UserStorage::class)->userStats($now->subHour())->keyBy('user_id');

        $this->assertCount(2, $stats);

        $user1 = $stats->get('web:1');
        $this->assertSame(2, $user1->success);
        $this->assertSame(1, $user1->client_errors);
        $this->assertSame(0, $user1->server_errors);
        $this->assertSame(3, $user1->requests);
        $this->assertSame(1, $user1->queued_jobs);
        $this->assertSame(1, $user1->exceptions);
        $this->assertTrue($lastSeen->equalTo($user1->last_seen));

        $user2 = $stats->get('web:2');
        $this->assertSame(1, $user2->requests);
        $this->assertSame(1, $user2->server_errors);
    }

    public function test_standalone_users_tab_defaults_to_sorting_by_requests_descending(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEntry('request', '2xx', $now->subMinutes(5), userId: 'web:1');
        $this->insertEntry('request', '2xx', $now->subMinutes(4), userId: 'web:2');
        $this->insertEntry('request', '2xx', $now->subMinutes(3), userId: 'web:2');

        $users = Livewire::test(Users::class)->viewData('users');

        $this->assertSame('web:2', $users->first()->user_id);
        $this->assertSame(2, $users->first()->requests);
    }

    public function test_sort_toggles_direction_on_repeated_click(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEntry('request', '2xx', $now->subMinutes(5), userId: 'web:1');
        $this->insertEntry('request', '2xx', $now->subMinutes(4), userId: 'web:2');
        $this->insertEntry('request', '2xx', $now->subMinutes(3), userId: 'web:2');

        $component = Livewire::test(Users::class)->call('sort', 'requests');

        $this->assertSame('asc', $component->get('sortDirection'));
        $this->assertSame('web:1', $component->viewData('users')->first()->user_id);
    }

    public function test_users_tab_view_data_exposes_chart_buckets(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEntry('request', '2xx', $now->subMinutes(5), userId: 'web:1');
        $this->insertEntry('request', '2xx', $now->subMinutes(4));

        $component = Livewire::test(Users::class);

        $this->assertSame(1, array_sum($component->viewData('authenticatedUserBuckets')));
        $this->assertSame(1, $component->viewData('authenticatedRequests'));
        $this->assertSame(1, $component->viewData('guestRequests'));
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
            // Microsecond precision, same as DatabaseEntryWriter::store() — a bare
            // CarbonImmutable binding loses it to the grammar's default
            // 'Y-m-d H:i:s' format, which would make an exact last_seen
            // equality assertion flaky.
            'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
        ]);
    }
}
