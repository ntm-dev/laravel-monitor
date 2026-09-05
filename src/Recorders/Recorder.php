<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use LaravelMonitor\Monitor;

use function function_exists;
use function memory_reset_peak_usage;

abstract class Recorder
{
    public function __construct(
        protected Monitor $monitor,
        protected array $config = [],
    ) {
    }

    abstract public function register(Dispatcher $events): void;

    /**
     * Scopes memory_get_peak_usage() to the unit of work about to start —
     * a long-running worker or scheduler never restarts its PHP process
     * between jobs/tasks, so without this the peak reported for one of
     * them is really the cumulative peak of every one that process has
     * ever run.
     *
     * memory_reset_peak_usage() only exists from PHP 8.2 onwards while
     * this package supports ^8.1 (see composer.json), so on 8.1 the peak
     * stays cumulative rather than fataling on an undefined function.
     */
    protected function resetPeakMemoryUsage(): void
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
    }
}
