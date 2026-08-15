<?php

namespace LaravelMonitor\Hooks;

use LaravelMonitor\Monitor;

/**
 * Console counterpart of RequestLifecycleEndHook — marks the terminating →
 * end boundary for a standalone command run and flushes it, registered
 * against Kernel::whenCommandLifecycleIsLongerThan(-1, ...) (see
 * MonitorServiceProvider::registerConsoleHooks()). Recorders\Commands
 * deliberately stops short of flushing its own `command` entry (see
 * recordFinished()), so this hook is the one place that entry actually gets
 * persisted — any earlier and End would always measure zero.
 */
class CommandLifecycleEndHook
{
    public function __construct(private Monitor $monitor)
    {
    }

    public function __invoke(): void
    {
        $this->monitor->markCommandEnd();
        $this->monitor->flush();
        $this->monitor->endCommandRun();
    }
}
