<?php

namespace LaravelMonitor\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaravelMonitor\Facades\Monitor;

/**
 * A statement that throws never fires QueryExecuted, so Recorders\Queries
 * notices it by the beforeExecuting hook that was never followed by one.
 */
class FailedQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_that_throws_is_recorded_as_failed(): void
    {
        $this->runFailing('select * from missing_table');

        Monitor::flush();

        $entries = $this->queryEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('select * from missing_table', $entries[0]->payload['sql']);
        $this->assertTrue($entries[0]->payload['failed']);
        $this->assertNull($entries[0]->duration);
        $this->assertSame('testing', $entries[0]->subtype);
    }

    public function test_successful_statement_is_not_marked_failed(): void
    {
        DB::select('select 1');

        Monitor::flush();

        $entries = $this->queryEntries();

        $this->assertCount(1, $entries);
        $this->assertArrayNotHasKey('failed', $entries[0]->payload);
        $this->assertNotNull($entries[0]->duration);
    }

    public function test_failure_followed_by_other_statements_is_still_recorded_once(): void
    {
        $this->runFailing('select * from missing_table');
        DB::select('select 2');
        DB::select('select 3');

        Monitor::flush();

        $failed = array_filter($this->queryEntries(), static fn ($entry) => $entry->payload['failed'] ?? false);

        $this->assertCount(1, $failed);
        $this->assertCount(3, $this->queryEntries());
    }

    public function test_same_failing_statement_is_recorded_each_time_it_fails(): void
    {
        $this->runFailing('select * from missing_table');
        $this->runFailing('select * from missing_table');

        Monitor::flush();

        $this->assertCount(2, $this->queryEntries());
    }

    public function test_statement_run_from_a_query_listener_does_not_mark_the_outer_one_failed(): void
    {
        $ran = false;

        DB::listen(function () use (&$ran) {
            if (! $ran) {
                $ran = true;
                DB::select('select 99');
            }
        });

        DB::select('select 1');

        Monitor::flush();

        $failed = array_filter($this->queryEntries(), static fn ($entry) => $entry->payload['failed'] ?? false);

        $this->assertCount(0, $failed);
    }

    public function test_failing_statement_on_a_monitor_table_is_ignored(): void
    {
        $this->runFailing('select missing_column from monitor_entries');

        Monitor::flush();

        $this->assertCount(0, $this->queryEntries());
    }

    private function runFailing(string $sql): void
    {
        try {
            DB::select($sql);
        } catch (QueryException) {
            // Expected — the failure is what gets recorded.
        }
    }

    /**
     * @return list<object>
     */
    private function queryEntries(): array
    {
        return DB::table('monitor_entries')
            ->where('type', 'query')
            ->orderBy('id')
            ->get()
            ->map(static function ($row) {
                $row->payload = json_decode($row->payload, true);

                return $row;
            })
            ->all();
    }
}
