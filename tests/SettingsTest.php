<?php

namespace LaravelMonitor\Tests;

use LaravelMonitor\Support\Settings;

class SettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        // Settings persists to a real file outside the database, so it must
        // be cleaned up by hand — RefreshDatabase doesn't touch it.
        Settings::reset();

        parent::tearDown();
    }

    public function test_saved_query_and_outgoing_request_thresholds_overlay_the_config_defaults(): void
    {
        $this->assertSame(500, config('monitor.thresholds.query'));
        $this->assertSame(1000, config('monitor.thresholds.outgoing_request'));

        Settings::save([
            'enabled' => true,
            'storage_driver' => 'database',
            'database_table' => 'monitor_entries',
            'dashboard_path' => 'monitor',
            'retention_hours' => 168,
            'refresh' => 10,
            'request_threshold' => 1000,
            'job_threshold' => 1000,
            'query_threshold' => 250,
            'outgoing_request_threshold' => 2000,
            'periods' => ['1h' => 1],
            'recorders' => [],
        ]);

        Settings::apply();

        $this->assertSame(250, config('monitor.thresholds.query'));
        $this->assertSame(2000, config('monitor.thresholds.outgoing_request'));
    }

    public function test_current_reports_query_and_outgoing_request_thresholds(): void
    {
        $current = Settings::current();

        $this->assertSame(500, $current['query_threshold']);
        $this->assertSame(1000, $current['outgoing_request_threshold']);
    }
}
