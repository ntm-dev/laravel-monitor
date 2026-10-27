<?php

namespace LaravelMonitor\Hooks;

use LaravelMonitor\Monitor;

/**
 * Marks the request's terminating → end boundary and flushes it; runs last
 * in the HTTP kernel, after every terminating callback.
 */
class RequestLifecycleEndHook
{
    public function __construct(private Monitor $monitor)
    {
    }

    public function __invoke(): void
    {
        $this->monitor->markEnd();
        $this->monitor->flush();
    }
}
