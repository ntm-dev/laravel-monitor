<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use LaravelMonitor\Monitor;
use LaravelMonitor\Support\RecordType;
use ReflectionProperty;

class Models extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen('eloquent.retrieved: *', fn () => $this->monitor->incrementModelCount());

        $this->registerLazyLoadingViolations();
    }

    /**
     * Only fires for apps that already enabled Model::preventLazyLoading();
     * this recorder never turns it on. Wraps any existing handler. Not guarded
     * to once per process: Testbench resets the static callback between tests.
     */
    protected function registerLazyLoadingViolations(): void
    {
        $previous = $this->currentViolationHandler();

        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use ($previous) {
            // Resolved per call: Eloquent's static callback can outlive this
            // app instance (e.g. across Testbench tests).
            app(Monitor::class)->record(
                type: RecordType::LazyLoading,
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

            // Eloquent's default behaviour (HasAttributes::handleLazyLoadingViolation()).
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
