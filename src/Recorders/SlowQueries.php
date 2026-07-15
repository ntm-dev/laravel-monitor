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
        // Count every query for the request's total, regardless of the
        // slow-query threshold below — otherwise a request whose queries
        // all ran under threshold looks like it made zero queries at all.
        $this->monitor->incrementQueryCount();

        $threshold = (float) ($this->config['threshold'] ?? 100);
        $isSlow = $event->time >= $threshold;

        // Outside a request (console command, queue worker), only persist
        // queries over the threshold — a long-running worker can run far
        // more queries than a request ever will, and nothing outside a
        // request needs a full per-query timeline. Inside a request,
        // persist every query so it shows up on that request's timeline
        // (mirrors Nightwatch, which records every query), tagging it
        // slow/fast so the dedicated Slow Queries digest can still filter
        // down to just the slow ones.
        if (! $isSlow && $this->monitor->requestId() === null) {
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
            duration: round($event->time, 2),
            subtype: $isSlow ? 'slow' : 'fast',
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
