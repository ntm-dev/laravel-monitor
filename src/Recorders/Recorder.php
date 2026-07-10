<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use LaravelMonitor\Monitor;

abstract class Recorder
{
    public function __construct(
        protected Monitor $monitor,
        protected array $config = [],
    ) {
    }

    abstract public function register(Dispatcher $events): void;

    protected function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $value)) {
                return true;
            }
        }

        return false;
    }
}
