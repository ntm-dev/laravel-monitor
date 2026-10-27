<?php

namespace LaravelMonitor;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LaravelMonitor\Contracts\AggregateStorage;
use LaravelMonitor\Contracts\CacheAndQueryStorage;
use LaravelMonitor\Contracts\EntryWriter;
use LaravelMonitor\Contracts\ExceptionStorage;
use LaravelMonitor\Contracts\HashResolver;
use LaravelMonitor\Contracts\IssueStorage;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Contracts\UserStorage;
use LaravelMonitor\Hooks\CommandLifecycleEndHook;
use LaravelMonitor\Hooks\ControllerStartHook;
use LaravelMonitor\Hooks\RequestLifecycleEndHook;
use LaravelMonitor\Livewire as Cards;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Storage\DatabaseAggregateStorage;
use LaravelMonitor\Storage\DatabaseCacheAndQueryStorage;
use LaravelMonitor\Storage\DatabaseEntryWriter;
use LaravelMonitor\Storage\DatabaseExceptionStorage;
use LaravelMonitor\Storage\DatabaseHashResolver;
use LaravelMonitor\Storage\DatabaseIssueStorage;
use LaravelMonitor\Storage\DatabaseTimelineStorage;
use LaravelMonitor\Storage\DatabaseUserStorage;
use LaravelMonitor\Support\AggregateCatchUp;
use Livewire\Livewire;

class MonitorServiceProvider extends ServiceProvider
{
    private float $timestamp;

    public function register(): void
    {
        try {
            $this->captureTimestamp();
            $this->mergeConfigFrom(__DIR__.'/../config/monitor.php', 'monitor');

            Support\Settings::apply();
            if ($this->app['config']->get('monitor.enabled', false)) {
                $this->registerBindings();
                // Always loaded: host code may generate monitor URLs, and route:cache needs them.
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

                $monitor = $this->app->make(Monitor::class);
                $monitor->timestamp($this->timestamp);

                // The dashboard's own requests record nothing: no recorders, hooks or flush.
                if (! $this->app->runningInConsole() && $monitor->isSelfRequest()) {
                    $this->registerSelfHooks();

                    return;
                }

                $this->registerRecorders();

                if (!$this->app->runningInConsole()) {
                    $this->registerRequestHooks();
                } else {
                    // Console still renders views: queued monitor mail, view:cache, tests.
                    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
                    $this->registerViewResources();
                    $this->registerConsoleHooks();
                }

                // Safety-net flush; a tracked request/command flushes later from
                // its lifecycle-end hook, after End is marked.
                $this->app->terminating(function () use ($monitor) {
                    if ($monitor->hasTrackedExecution()) {
                        return;
                    }

                    $monitor->flush();
                });
            }
        } catch (\Throwable $th) {
            report($th);
        }
    }

    /**
     * Each storage contract is a separate singleton, so a page loads only the
     * classes it uses; singletons because some memoize within a request.
     */
    private function registerBindings(): void
    {
        $this->app->singleton(Monitor::class);
        $this->app->singleton(AggregateCatchUp::class);
        $this->app->singleton(ControllerStartHook::class);
        $this->app->singleton(RequestLifecycleEndHook::class);
        $this->app->singleton(CommandLifecycleEndHook::class);

        $config = fn ($app) => $app['config']->get('monitor.storage.database', []);

        $this->app->singleton(EntryWriter::class, static fn ($app) => new DatabaseEntryWriter($app['db'], $config($app)));
        $this->app->singleton(AggregateStorage::class, static fn ($app) => new DatabaseAggregateStorage($app['db'], $config($app)));
        $this->app->singleton(TimelineStorage::class, static fn ($app) => new DatabaseTimelineStorage($app['db'], $config($app)));
        $this->app->singleton(UserStorage::class, static fn ($app) => new DatabaseUserStorage($app['db'], $config($app)));
        $this->app->singleton(CacheAndQueryStorage::class, static fn ($app) => new DatabaseCacheAndQueryStorage($app['db'], $config($app)));
        $this->app->singleton(ExceptionStorage::class, static fn ($app) => new DatabaseExceptionStorage($app['db'], $config($app)));
        $this->app->singleton(IssueStorage::class, static fn ($app) => new DatabaseIssueStorage($app['db'], $config($app)));
        $this->app->singleton(HashResolver::class, static fn ($app) => new DatabaseHashResolver($app['db'], $config($app)));
    }

    private function captureTimestamp(): void
    {
        $this->timestamp = match (true) {
            \defined('LARAVEL_START') => LARAVEL_START,
            default => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
        };
    }

    public function boot(): void
    {
        try {
            if (!$this->app['config']->get('monitor.enabled', false)) {
                return;
            }
            // Both only concern the dashboard itself: the Apple driver backs
            // monitor's own OAuth login, and smart_wire_keys must stay untouched
            // for the host app's own Livewire components.
            if (! $this->app->runningInConsole() && $this->app->make(Monitor::class)->isSelfRequest()) {
                $this->registerAppleOAuthDriver();

                // Livewire 4's smart_wire_keys loop tracking can unbalance and crash nested
                // loops ("Trying to access array offset on null"); the dashboard doesn't need it.
                config(['livewire.smart_wire_keys' => false]);
            }

            if ($this->app->runningInConsole()) {
                $this->registerPublications();
                $this->registerCommands();
            }
        } catch (\Throwable $th) {
            report($th);
        }
    }

    protected function registerPublications(): void
    {
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

    /** Translations, views and Blade components — only needed where monitor pages or mail render. */
    protected function registerViewResources(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'monitor');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'monitor');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'monitor');
        Blade::componentNamespace('LaravelMonitor\\View\\Components', 'monitor');
    }

    protected function registerRecorders(): void
    {
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
     * Hook the request-lifecycle stage markers without the host app editing
     * its HTTP kernel; beginRequest() runs on booted, before any middleware.
     */
    protected function registerRequestHooks(): void
    {
        $monitor = $this->app->make(Monitor::class);

        $this->app->booted($monitor->beginRequest(...));
        $events = $this->app->make(Dispatcher::class);

        $events->listen(
            RouteMatched::class,
            fn (RouteMatched $event) => $this->app->make(ControllerStartHook::class)->attachMiddlewareToRoute($event->route)
        );

        // Both fire inside the route's middleware pipeline, so "render" excludes post-$next() work.
        if (class_exists(\Illuminate\Routing\Events\PreparingResponse::class)) {
            $events->listen(\Illuminate\Routing\Events\PreparingResponse::class, $monitor->markRenderStart(...));
            $events->listen(\Illuminate\Routing\Events\ResponsePrepared::class, $monitor->markUnwinding(...));
        }

        $events->listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, $monitor->markResponseReady(...));
        if (class_exists(\Illuminate\Foundation\Events\Terminating::class)) {
            $events->listen(\Illuminate\Foundation\Events\Terminating::class, $monitor->markTerminating(...));
        }

        // Runs after every terminating callback, so End captures that work; -1
        // means "always". Guarded: the method is missing on custom kernels and
        // early Laravel 10.x releases.
        $this->callAfterResolving(\Illuminate\Contracts\Http\Kernel::class, function ($kernel) {
            if (
                ! $kernel instanceof \Illuminate\Foundation\Http\Kernel
                || ! method_exists($kernel, 'whenRequestLifecycleIsLongerThan')
            ) {
                return;
            }

            $kernel->whenRequestLifecycleIsLongerThan(-1, $this->app->make(RequestLifecycleEndHook::class));
        });
    }

    private function registerSelfHooks(): void
    {
        $this->registerViewResources();
        $this->registerLivewireComponents();
        $this->registerAuthorization();
        $this->registerAuth();
        $this->registerOAuth();
        $this->registerLivewireUpdateCookieIsolation();
    }

    /**
     * Console counterpart of registerRequestHooks()'s End hook; the other
     * command stages are marked by Recorders\Commands.
     */
    protected function registerConsoleHooks(): void
    {
        $this->callAfterResolving(\Illuminate\Contracts\Console\Kernel::class, function ($kernel) {
            if (
                ! $kernel instanceof \Illuminate\Foundation\Console\Kernel
                || ! method_exists($kernel, 'whenCommandLifecycleIsLongerThan')
            ) {
                return;
            }

            $kernel->whenCommandLifecycleIsLongerThan(-1, $this->app->make(CommandLifecycleEndHook::class));
        });
    }

    protected function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('monitor.overview', Cards\Overview::class);
        Livewire::component('monitor.requests', Cards\Requests::class);
        Livewire::component('monitor.queries', Cards\Queries::class);
        Livewire::component('monitor.exceptions', Cards\Exceptions::class);
        Livewire::component('monitor.jobs', Cards\Jobs::class);
        Livewire::component('monitor.commands', Cards\Commands::class);
        Livewire::component('monitor.schedule', Cards\Schedule::class);
        Livewire::component('monitor.cache', Cards\CacheStats::class);
        Livewire::component('monitor.outgoing-requests', Cards\OutgoingRequests::class);
        Livewire::component('monitor.mail', Cards\MailAndNotifications::class);
        Livewire::component('monitor.logs', Cards\Logs::class);
        Livewire::component('monitor.team', Cards\Team::class);
        Livewire::component('monitor.users', Cards\Users::class);
        Livewire::component('monitor.application', Cards\Application::class);
        Livewire::component('monitor.issues', Cards\Issues::class);
        Livewire::component('monitor.issue-manage-panel', Cards\IssueManagePanel::class);
        Livewire::component('monitor.open-issue-badge', Cards\OpenIssueBadge::class);
        Livewire::component('monitor.notifications', Cards\Notifications::class);
        Livewire::component('monitor.request-detail', Cards\RequestDetail::class);
        Livewire::component('monitor.job-detail', Cards\JobDetail::class);
        Livewire::component('monitor.command-detail', Cards\CommandDetail::class);
        Livewire::component('monitor.schedule-detail', Cards\ScheduleDetail::class);
        Livewire::component('monitor.exception-detail', Cards\ExceptionDetail::class);
        Livewire::component('monitor.query-detail', Cards\QueryDetail::class);
        Livewire::component('monitor.notification-detail', Cards\NotificationDetail::class);
        Livewire::component('monitor.mail-detail', Cards\MailDetail::class);
        Livewire::component('monitor.notification-class-detail', Cards\NotificationClassDetail::class);
        Livewire::component('monitor.mail-class-detail', Cards\MailClassDetail::class);
        Livewire::component('monitor.outgoing-detail', Cards\OutgoingDetail::class);
        Livewire::component('monitor.outgoing-domain-detail', Cards\OutgoingDomainDetail::class);
        Livewire::component('monitor.user-detail', Cards\UserDetail::class);
    }

    protected function registerAuthorization(): void
    {
        if (! Gate::has('viewMonitor')) {
            Gate::define('viewMonitor', fn ($user = null) => $this->app->environment('local'));
        }
    }

    /** Register the package's guard and `monitor_users` provider unless the host app defines them. */
    protected function registerAuth(): void
    {
        $guard = MonitorUser::guardName();

        if (! $this->app['config']->has("auth.guards.{$guard}")) {
            $this->app['config']->set("auth.guards.{$guard}", [
                'driver' => 'session',
                'provider' => 'monitor_users',
            ]);
        }

        if (! $this->app['config']->has('auth.providers.monitor_users')) {
            $this->app['config']->set('auth.providers.monitor_users', [
                'driver' => 'eloquent',
                'model' => MonitorUser::class,
            ]);
        }
    }

    private function registerCommands(): void
    {
        $this->commands([
            Commands\PruneCommand::class,
            Commands\ClearCommand::class,
            Commands\AggregateCommand::class,
            Commands\SetupCodeCommand::class,
        ]);
    }

    /** Fill Socialite's `services.<provider>` from `monitor.auth.oauth.*` unless the host app sets it. */
    protected function registerOAuth(): void
    {
        foreach (['google', 'apple'] as $provider) {
            if (! $this->app['config']->has("services.{$provider}")) {
                $this->app['config']->set("services.{$provider}", $this->app['config']->get("monitor.auth.oauth.{$provider}", []));
            }
        }
    }

    /**
     * Livewire's shared update route sits outside the monitor route group, so
     * IsolateMonitorCookies is prepended to 'web'; it no-ops for host requests.
     */
    protected function registerLivewireUpdateCookieIsolation(): void
    {
        $this->callAfterResolving(\Illuminate\Contracts\Http\Kernel::class, static function ($kernel) {
            if (
                ! $kernel instanceof \Illuminate\Foundation\Http\Kernel
                || ! isset($kernel->getMiddlewareGroups()['web'])
            ) {
                return;
            }

            $kernel->prependMiddlewareToGroup('web', \LaravelMonitor\Http\Middleware\IsolateMonitorCookies::class);
        });
    }

    protected function registerAppleOAuthDriver(): void
    {
        if (! class_exists(\SocialiteProviders\Apple\Provider::class)) {
            return;
        }

        $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class)->listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            fn (\SocialiteProviders\Manager\SocialiteWasCalled $event) => $event->extendSocialite('apple', \SocialiteProviders\Apple\Provider::class),
        );
    }
}
