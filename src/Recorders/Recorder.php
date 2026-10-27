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
     * Scopes memory_get_peak_usage() to the job/task about to start in a
     * long-running process. No-op on PHP 8.1 (the function is 8.2+), where
     * the peak stays cumulative.
     */
    protected function resetPeakMemoryUsage(): void
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
    }
}
