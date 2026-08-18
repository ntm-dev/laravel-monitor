<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use LaravelMonitor\Monitor;

abstract class Recorder
{
    public function __construct(
        protected Monitor $monitor,
        protected array $config = [],
    ) {
    }

    abstract public function register(Dispatcher $events): void;
}
