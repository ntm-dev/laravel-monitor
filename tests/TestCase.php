<?php

namespace LaravelMonitor\Tests;

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
    }
}
