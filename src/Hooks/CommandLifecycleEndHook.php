<?php

namespace LaravelMonitor\Hooks;

use LaravelMonitor\Monitor;

/**
 * Console counterpart of RequestLifecycleEndHook: marks End for the command
 * run and flushes it, the one place its `command` entry is persisted.
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
