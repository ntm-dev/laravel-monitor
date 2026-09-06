<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaravelMonitor\Contracts\CacheAndQueryStorage;

/**
 * The read/write badge on the Queries list. The same statement really can run
 * under both PDO roles of a read/write split, so the list reports every role
 * it saw rather than collapsing a mixed query to "no role" — which used to
 * make it look identical to one with nothing recorded, while the detail page's
 * per-occurrence rows went on showing badges.
 */
class QueryConnectionTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_running_under_one_role_reports_just_that_role(): void
    {
        $now = CarbonImmutable::now();

        $this->insertQuery('select * from `users`', $now->subMinutes(3), 'read');
        $this->insertQuery('select * from `users`', $now->subMinutes(2), 'read');

        $this->assertSame(['read'], $this->statsFor('select * from `users`')->connection_types);
    }

    public function test_query_running_under_both_roles_reports_both(): void
    {
        $now = CarbonImmutable::now();

        // A SELECT routed to the write connection — inside a transaction, or
        // after a write while the split is sticky.
        $this->insertQuery('select * from `stores`', $now->subMinutes(3), 'read');
        $this->insertQuery('select * from `stores`', $now->subMinutes(2), 'write');
        $this->insertQuery('select * from `stores`', $now->subMinute(), 'read');

        $this->assertSame(['read', 'write'], $this->statsFor('select * from `stores`')->connection_types);
    }

    public function test_query_with_no_recorded_role_reports_none(): void
    {
        $this->insertQuery('select * from `jobs`', CarbonImmutable::now()->subMinute(), null);

        $this->assertSame([], $this->statsFor('select * from `jobs`')->connection_types);
    }

    public function test_roles_are_tracked_per_connection_not_across_them(): void
    {
        $now = CarbonImmutable::now();

        $this->insertQuery('select * from `carts`', $now->subMinutes(2), 'read', 'mysql');
        $this->insertQuery('select * from `carts`', $now->subMinute(), 'write', 'reporting');

        $this->assertSame(['read'], $this->statsFor('select * from `carts`', 'mysql')->connection_types);
        $this->assertSame(['write'], $this->statsFor('select * from `carts`', 'reporting')->connection_types);
    }

    protected function statsFor(string $key, string $connection = 'mysql'): object
    {
        return app(CacheAndQueryStorage::class)
            ->queryStats(CarbonImmutable::now()->subHour())
            ->firstWhere(fn (object $row) => $row->key === $key && $row->connection === $connection);
    }

    protected function insertQuery(
        string $sql,
        CarbonImmutable $createdAt,
        ?string $connectionType,
        string $connection = 'mysql',
    ): void {
        DB::table('monitor_entries')->insert([
            'type' => 'query',
            'subtype' => null,
            'key' => $sql,
            'payload' => json_encode(['connection' => $connection, 'connection_type' => $connectionType]),
            'duration' => 1.5,
            'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
        ]);
    }
}
