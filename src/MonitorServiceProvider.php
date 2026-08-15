<?php

namespace LaravelMonitor;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Hooks\CommandLifecycleEndHook;
use LaravelMonitor\Hooks\ControllerStartHook;
use LaravelMonitor\Hooks\RequestLifecycleEndHook;
use LaravelMonitor\Livewire as Cards;
use LaravelMonitor\Models\MonitorUser;
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
                $this->registerResources();
                $this->registerRecorders();
                if (!$this->app->runningInConsole()) {
                    $this->registerRequestHooks();
                } else {
                    $this->registerConsoleHooks();
                }

                // Jobs/scheduled tasks flush explicitly per-attempt/per-run
                // (see Recorders\Jobs, Recorders\ScheduledTasks) and never
                // reach End at all, so this remains their only flush
                // trigger — but a tracked request/command run gets flushed
                // by its own whenRequestLifecycleIsLongerThan/
                // whenCommandLifecycleIsLongerThan hook instead (registered
                // above), which marks End first; flushing here too would
                // run *before* that hook can, persisting the entry before
                // End is ever marked.
                $this->app->terminating(function () {
                    $monitor = $this->app->make(Monitor::class);

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

    private function registerBindings(): void
    {
        $this->app->singleton(Monitor::class);
        $this->app->singleton(ControllerStartHook::class);
        $this->app->singleton(RequestLifecycleEndHook::class);
        $this->app->singleton(CommandLifecycleEndHook::class);
        $this->app->singleton(StorageManager::class);
        $this->app->bind(Storage::class, fn ($app) => $app[StorageManager::class]->driver());
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
        $this->registerAppleOAuthDriver();

        // Livewire 4's smart_wire_keys precompiler auto-instruments @foreach/@forelse/@while
        // with static loop-tracking calls (openLoop/closeLoop) to derive wire:key values. Under
        // certain dependency combinations that static stack gets unbalanced and array_pop() on
        // an empty stack returns null, crashing the next loop with "Trying to access array
        // offset on null" (hit on the dashboard's nested @for/@foreach chart component). The
        // dashboard's lists don't need Livewire's implicit wire:key diffing, so disable it.
        config(['livewire.smart_wire_keys' => false]);

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
        $monitor = $this->app->make(Monitor::class);
        $monitor->timestamp($this->timestamp);
        $events = $this->app->make(Dispatcher::class);

        foreach ($this->app['config']->get('monitor.recorders', []) as $recorder => $config) {
            if (! ($config['enabled'] ?? true)) {
                continue;
            }

            (new $recorder($monitor, $config))->register($events);
        }
    }

    /**
     * Hook the request-lifecycle markers used by the Request Detail
     * timeline: `beginRequest()` fires once the app has finished booting —
     * before any middleware (global or route) runs — so the "middleware"
     * stage covers the *entire* middleware pipeline instead of only the
     * portion after a middleware pushed onto the kernel's stack. The
     * controller boundary is marked by ControllerStartHook, which attaches
     * itself directly onto the matched route (not by pushing into a
     * middleware *group* array) — framework events refine the
     * render/terminating phases. All without requiring the host app to
     * edit its HTTP kernel.
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

        // PreparingResponse/ResponsePrepared fire back to back, from
        // Router::prepareResponse() — still deep inside the route's own
        // middleware pipeline, before it bubbles back out through any
        // middleware's post-`$next()` code. That's what lets "render" mean
        // only the controller/view's own work.
        if (class_exists(\Illuminate\Routing\Events\PreparingResponse::class)) {
            $events->listen(\Illuminate\Routing\Events\PreparingResponse::class, $monitor->markRenderStart(...));
            $events->listen(\Illuminate\Routing\Events\ResponsePrepared::class, $monitor->markUnwinding(...));
        }

        $events->listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, $monitor->markResponseReady(...));
        if (class_exists(\Illuminate\Foundation\Events\Terminating::class)) {
            $events->listen(\Illuminate\Foundation\Events\Terminating::class, $monitor->markTerminating(...));
        }

        // whenRequestLifecycleIsLongerThan(-1, ...) fires last of all — after
        // every terminable middleware and the app's own terminating()
        // callbacks — so marking End here (rather than on the Terminating
        // event above) actually captures that trailing work instead of
        // measuring a zero-length phase. -1 as the threshold means "always",
        // not "only when slow": duration is never negative. Deferred behind
        // callAfterResolving() since the kernel isn't bound yet this early
        // in the boot cycle, and guarded by both an instanceof and a
        // method_exists check — this method only exists on the concrete
        // Illuminate\Foundation\Http\Kernel (not every app swaps in a
        // custom one), and only on a late-enough Laravel 10.x point release
        // (this package's own floor), not necessarily every 10.x install.
        $this->callAfterResolving(\Illuminate\Contracts\Http\Kernel::class, function ($kernel) {
            if (
                ! $kernel instanceof \Illuminate\Foundation\Http\Kernel
                || ! method_exists($kernel, 'whenRequestLifecycleIsLongerThan')
            ) {
                return;
            }

            $kernel->whenRequestLifecycleIsLongerThan(-1, $this->app->make(RequestLifecycleEndHook::class));
        });

        $this->registerLivewireComponents();
        $this->registerAuthorization();
        $this->registerAuth();
        $this->registerOAuth();
    }

    /**
     * Console-side counterpart of registerRequestHooks()'s End-marking
     * hook — see that method's own docs for why whenCommandLifecycleIsLongerThan(-1, ...)
     * is what makes End meaningful instead of a permanently zero-length
     * phase. Every other command-lifecycle stage (Bootstrap, Action,
     * Terminating) is already marked directly by Recorders\Commands itself
     * (beginCommandRun()/markCommandTerminating()), driven off
     * CommandStarting/CommandFinished rather than a service-provider-level
     * hook — this is the one boundary that can only be reached from outside
     * Monitor's own request/command lifecycle.
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
    }

    protected function registerAuthorization(): void
    {
        if (! Gate::has('viewMonitor')) {
            Gate::define('viewMonitor', fn ($user = null) => $this->app->environment('local'));
        }
    }

    /**
     * Register the package's own `monitor` guard/provider pair, unless the
     * host app already defined one under the same name — mirrors
     * registerAuthorization()'s "don't clobber a host override" rule for
     * the viewMonitor Gate.
     */
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
        ]);
    }

    /**
     * Socialite reads driver config from `services.<provider>`, which this
     * package has no business publishing into the host app's own
     * config/services.php — mirror registerAuth()'s approach instead and
     * merge it at runtime from this package's own `monitor.auth.oauth.*`,
     * skipping any provider the host app already configured itself.
     */
    protected function registerOAuth(): void
    {
        foreach (['google', 'apple'] as $provider) {
            if (! $this->app['config']->has("services.{$provider}")) {
                $this->app['config']->set("services.{$provider}", $this->app['config']->get("monitor.auth.oauth.{$provider}", []));
            }
        }
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
