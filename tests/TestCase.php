<?php

namespace LaravelMonitor\Tests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Models\MonitorUser;
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
     *
     * Also seeds and logs in a default `owner` MonitorUser: every existing
     * route now requires a monitor-guard session (see
     * EnsureMonitorAuthenticated), and almost no test in this suite is
     * actually testing the auth system itself — tests that need an
     * unauthenticated state call withoutMonitorAuth() to opt out, or log
     * in a differently-privileged user explicitly.
     *
     * The owner is created (and logged in) *before* flush()/purge() run,
     * not after: MonitorUser::create() issues an INSERT that the Queries
     * recorder buffers like any other query (see the class docblock on
     * Recorders\Queries), so if flush()/purge() ran first that leftover
     * buffered entry would survive into the test body and pollute
     * monitor_entries counts/assertions.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $owner = MonitorUser::create([
            'name' => 'Test Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
            'role' => 'owner',
        ]);

        $this->actingAs($owner, MonitorUser::guardName());

        $this->app->make(Monitor::class)->flush();
        $this->app->make(Storage::class)->purge();
    }

    protected function withoutMonitorAuth(): static
    {
        Auth::guard(MonitorUser::guardName())->logout();

        return $this;
    }

    protected function getPackageProviders($app): array
    {
        return array_filter([
            LivewireServiceProvider::class,
            MonitorServiceProvider::class,
            // laravel/socialite ships its own service provider (binding
            // Socialite::class's Factory contract); this package has no
            // Testbench package auto-discovery, so register it explicitly
            // whenever the (require-dev/suggested) package is installed —
            // OAuthLoginTest resolves the Socialite facade directly.
            class_exists(\Laravel\Socialite\SocialiteServiceProvider::class) ? \Laravel\Socialite\SocialiteServiceProvider::class : null,
        ]);
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
