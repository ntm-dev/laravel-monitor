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
        // MonitorServiceProvider::captureTimestamp() falls back to
        // $_SERVER['REQUEST_TIME_FLOAT'] as the request's start time (no
        // LARAVEL_START constant here, since nothing goes through
        // public/index.php). Under a real per-request PHP process that value
        // is fresh every time, but the CLI SAPI sets it once for the whole
        // `phpunit` process — left untouched, every test after the first
        // would compute its request "duration" against however long the
        // entire suite has been running rather than just this test, growing
        // without bound the more tests ran before it (see
        // Monitor::finalizePendingRequest()). Resetting it here keeps every
        // test's request start time realistic.
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        parent::setUp();

        $this->registerRequestHooks();

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

    /**
     * PHPUnit always runs via the CLI SAPI, so Application::runningInConsole()
     * reports true even for tests simulating an HTTP request — which means
     * MonitorServiceProvider::register() never calls registerRequestHooks()
     * (guard/OAuth/Livewire component/gate registration), since that's
     * correctly gated behind `!runningInConsole()` for real console usage.
     * Invoke it directly here instead of loosening that production-side
     * check: this mirrors what actually happens on a real HTTP request
     * without touching Laravel's own runningInConsole()-gated behavior
     * elsewhere (e.g. VerifyCsrfToken's test-bypass relies on the same flag
     * staying true).
     */
    protected function registerRequestHooks(): void
    {
        $provider = $this->app->getProvider(MonitorServiceProvider::class);

        (new \ReflectionMethod($provider, 'registerRequestHooks'))->invoke($provider);

        // registerRequestHooks() also registers Monitor::beginRequest() as an
        // app->booted() callback — since the app has already finished
        // booting by the time we call this (parent::setUp() already ran),
        // Laravel fires it immediately, tagging $this->request for the rest
        // of the test even if it never makes an HTTP call. That's wrong for
        // tests driving command/job/scheduled-task tracking directly: with
        // $this->request non-null, record()'s correlation fallback picks it
        // over $this->command['id']/currentJob()['id'], mis-tagging those
        // entries with a phantom request id. Reset it here; call() below
        // re-establishes it right before an actual HTTP-style test request,
        // mirroring how a real HTTP process only ever calls beginRequest()
        // once, for the one request it's handling.
        (new \ReflectionProperty(Monitor::class, 'request'))->setValue($this->app->make(Monitor::class), null);
    }

    /** @return \Illuminate\Testing\TestResponse */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->make(Monitor::class)->beginRequest();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
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
