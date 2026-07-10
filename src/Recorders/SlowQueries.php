<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;

class SlowQueries extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, [$this, 'record']);
    }

    public function record(QueryExecuted $event): void
    {
        $threshold = (float) ($this->config['threshold'] ?? 100);

        if ($event->time < $threshold) {
            return;
        }

        $this->monitor->record(
            type: 'slow_query',
            key: $event->sql,
            payload: [
                'sql' => $event->sql,
                'connection' => $event->connectionName,
                'location' => $this->location(),
            ],
            duration: (int) round($event->time),
        );
    }

    /**
     * First application (non-vendor) frame that triggered the query.
     */
    protected function location(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            if (str_starts_with($file, base_path()) && ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                return str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).':'.($frame['line'] ?? 0);
            }
        }

        return null;
    }
}
