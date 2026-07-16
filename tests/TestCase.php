<?php

namespace LaravelMonitor\Tests;

use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Monitor;
use LaravelMonitor\MonitorServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Keep tests hermetic: ignore any local .env (e.g. the demo-preview file)
     * so the environment comes solely from defineEnvironment().
     */
    protected $loadEnvironmentVariables = false;

    /**
     * The Queries recorder records every query regardless of duration or
     * context, so RefreshDatabase's own migration queries (run during
     * setUp, before the test body) get buffered too. Flush and purge them
     * here so each test starts from a clean monitor_entries table instead
     * of asserting against leftover framework-bootstrap noise.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Monitor::class)->flush();
        $this->app->make(Storage::class)->purge();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            MonitorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // Belt-and-suspenders alongside MonitorServiceProvider::boot(): keep the dashboard
        // tests immune to Livewire's smart_wire_keys bug even if provider boot order changes.
        $app['config']->set('livewire.smart_wire_keys', false);
    }
}
