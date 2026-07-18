<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use LaravelMonitor\Monitor;
use ReflectionProperty;

class Models extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen('eloquent.retrieved: *', fn () => $this->monitor->incrementModelCount());

        $this->registerLazyLoadingViolations();
    }

    /**
     * Only ever fires for apps that already opted into
     * Model::preventLazyLoading()/shouldBeStrict() themselves — this
     * recorder observes that existing behaviour rather than turning it on,
     * since flipping it on process-wide would be a real runtime behaviour
     * change for a monitoring package to make unasked.
     *
     * Registers fresh every call rather than guarding "once per process":
     * a real process (HTTP request, queue worker, artisan command) only
     * ever calls register() once anyway, since Laravel boots service
     * providers a single time per process regardless of how many
     * requests/jobs it goes on to handle — the only environment that
     * re-invokes register() within one process is Testbench's per-test
     * application rebuild, which also resets Model's static callback slot
     * back to null between tests. A "register once" guard would miss that
     * reset and leave the callback permanently unset after the first test.
     */
    protected function registerLazyLoadingViolations(): void
    {
        $previous = $this->currentViolationHandler();

        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use ($previous) {
            // Resolved dynamically (not $this->monitor) so this single,
            // process-lifetime registration always records onto whichever
            // app/Monitor instance is currently active — matters under
            // Testbench, where a fresh application (and Monitor singleton)
            // boots per test but Eloquent's static callback slot persists.
            app(Monitor::class)->record(
                type: 'lazy_loading',
                key: get_class($model).'::'.$relation,
                payload: [
                    'model' => get_class($model),
                    'relation' => $relation,
                    'id' => $model->getKey(),
                ],
            );

            if ($previous !== null) {
                return call_user_func($previous, $model, $relation);
            }

            // Replicate Eloquent's own default (no custom handler
            // registered) so installing this package never silently
            // swallows an app's existing strict-mode crash-in-dev
            // behaviour — see HasAttributes::handleLazyLoadingViolation().
            if (! $model->exists || $model->wasRecentlyCreated) {
                return null;
            }

            throw new LazyLoadingViolationException($model, $relation);
        });
    }

    protected function currentViolationHandler(): ?callable
    {
        $property = new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback');
        $property->setAccessible(true);

        /** @var callable|null */
        return $property->getValue();
    }
}
