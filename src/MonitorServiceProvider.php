<?php

namespace LaravelMonitor;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Livewire as Cards;
use Livewire\Livewire;

class MonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitor.php', 'monitor');

        $this->app->singleton(Monitor::class);
        $this->app->singleton(StorageManager::class);
        $this->app->bind(Storage::class, fn ($app) => $app[StorageManager::class]->driver());
    }

    public function boot(): void
    {
        Support\Settings::apply();

        $this->registerPublishing();
        $this->registerResources();
        $this->registerRecorders();
        $this->registerRequestTimeline();
        $this->registerLivewireComponents();
        $this->registerAuthorization();
        $this->registerCommands();

        $this->app->terminating(fn () => $this->app->make(Monitor::class)->flush());
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/monitor.php' => config_path('monitor.php'),
        ], 'monitor-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'monitor-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/monitor'),
        ], 'monitor-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/monitor'),
        ], 'monitor-lang');
    }

    protected function registerResources(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'monitor');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'monitor');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'monitor');
        Blade::componentNamespace('LaravelMonitor\\View\\Components', 'monitor');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }

    protected function registerRecorders(): void
    {
        if (! $this->app['config']->get('monitor.enabled', true)) {
            return;
        }

        $monitor = $this->app->make(Monitor::class);
        $events = $this->app->make(Dispatcher::class);

        foreach ($this->app['config']->get('monitor.recorders', []) as $recorder => $config) {
            if (! ($config['enabled'] ?? true)) {
                continue;
            }

            (new $recorder($monitor, $config))->register($events);
        }
    }

    /**
     * Hook the request-lifecycle markers used by the Request Detail timeline,
     * following Nightwatch's approach: a global middleware brackets the whole
     * request, a route-group middleware marks the controller boundary, and
     * framework events refine the render/terminating phases — all without
     * requiring the host app to edit its HTTP kernel.
     */
    protected function registerRequestTimeline(): void
    {
        if (! $this->app['config']->get('monitor.enabled', true)) {
            return;
        }

        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        if ($kernel instanceof \Illuminate\Foundation\Http\Kernel) {
            $kernel->pushMiddleware(Http\Middleware\RecordTimeline::class);
        }

        $router = $this->app['router'];

        foreach (['web', 'api'] as $group) {
            $router->pushMiddlewareToGroup($group, Http\Middleware\MarkControllerStart::class);
        }

        $monitor = $this->app->make(Monitor::class);
        $events = $this->app->make(Dispatcher::class);

        $events->listen('composing:*', fn () => $monitor->markComposing());

        if (class_exists(\Illuminate\Foundation\Events\Terminating::class)) {
            $events->listen(\Illuminate\Foundation\Events\Terminating::class, fn () => $monitor->markTerminating());
        }
    }

    protected function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('monitor.overview', Cards\Overview::class);
        Livewire::component('monitor.requests', Cards\Requests::class);
        Livewire::component('monitor.slow-queries', Cards\SlowQueries::class);
        Livewire::component('monitor.exceptions', Cards\Exceptions::class);
        Livewire::component('monitor.jobs', Cards\Jobs::class);
        Livewire::component('monitor.schedule', Cards\Schedule::class);
        Livewire::component('monitor.cache', Cards\CacheStats::class);
        Livewire::component('monitor.outgoing-requests', Cards\OutgoingRequests::class);
        Livewire::component('monitor.mail', Cards\MailAndNotifications::class);
        Livewire::component('monitor.logs', Cards\Logs::class);
        Livewire::component('monitor.users', Cards\Users::class);
        Livewire::component('monitor.application', Cards\Application::class);
        Livewire::component('monitor.issues', Cards\Issues::class);
        Livewire::component('monitor.notifications', Cards\Notifications::class);
        Livewire::component('monitor.request-detail', Cards\RequestDetail::class);
        Livewire::component('monitor.job-detail', Cards\JobDetail::class);
        Livewire::component('monitor.exception-detail', Cards\ExceptionDetail::class);
    }

    protected function registerAuthorization(): void
    {
        if (! Gate::has('viewMonitor')) {
            Gate::define('viewMonitor', fn ($user = null) => $this->app->environment('local'));
        }
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\PruneCommand::class,
                Commands\ClearCommand::class,
            ]);
        }
    }
}
