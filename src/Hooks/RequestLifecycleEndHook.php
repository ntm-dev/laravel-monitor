<?php

namespace LaravelMonitor\Hooks;

use LaravelMonitor\Monitor;

/**
 * Marks the terminating → end boundary for the Request Detail timeline —
 * registered against Kernel::whenRequestLifecycleIsLongerThan(-1, ...) (see
 * MonitorServiceProvider::registerRequestHooks()) rather than the
 * Terminating event markTerminating() already listens for: this one fires
 * last of everything the HTTP kernel runs, after every terminable
 * middleware and the app's own terminating() callbacks, so it's also where
 * the request actually gets flushed — any earlier and End would always
 * measure zero, since it hadn't happened yet.
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
