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
        $this->registerPublishing();
        $this->registerResources();
        $this->registerRecorders();
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
    }

    protected function registerResources(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'monitor');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'monitor');
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
