<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use LaravelMonitor\Support\Sql;

class Queries extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, [$this, 'record']);
    }

    public function record(QueryExecuted $event): void
    {
        // Monitor's own storage writes/reads (INSERT into monitor_entries
        // when flushing, SELECT/aggregate queries when rendering the
        // dashboard) would otherwise show up as "app" queries and dominate
        // the Queries page. The write side was already excluded — flush()
        // pauses recording while it runs its own INSERT — but nothing
        // stopped the read side, since dashboard pages render with
        // recording enabled like any other request.
        if ($this->isSelfReferential($event->sql)) {
            return;
        }

        // Count every query for the request's total, regardless of the
        // slow-query threshold below — otherwise a request whose queries
        // all ran under threshold looks like it made zero queries at all.
        $this->monitor->incrementQueryCount();

        $threshold = (float) ($this->config['threshold'] ?? 100);
        $isSlow = $event->time >= $threshold;

        // Persist every query regardless of duration or execution context
        // (request, console command, queue worker) — tagging it slow/fast
        // so the dedicated Slow Queries digest can still filter down to
        // just the slow ones. A long-running worker can generate a lot of
        // rows this way; monitor.retention.hours / `monitor:prune` is the
        // backstop, not a per-query filter.
        //
        // The stored `type` stays 'slow_query' even though this recorder no
        // longer only records slow ones — changing it would orphan every
        // already-recorded row (and every Storage call keyed on that
        // string) for what's otherwise a same-behavior rename.
        $this->monitor->record(
            type: 'slow_query',
            key: Sql::normalizeKey($event->sql),
            payload: [
                'sql' => $event->sql,
                'connection' => $event->connectionName,
                'location' => $this->location(),
                // Only meaningful outside a request — inside one, the row
                // already carries request_id and the Query Detail page
                // resolves that back to "METHOD /path" itself.
                'command' => $this->monitor->requestId() === null ? $this->monitor->commandName() : null,
            ],
            duration: round($event->time, 2),
            subtype: $isSlow ? 'slow' : 'fast',
        );
    }

    /**
     * Whether the query touches Monitor's own storage table — read live
     * from config since it's user-configurable via the Settings page
     * (Support\Settings::apply() overlays a saved override before any
     * request is handled).
     */
    protected function isSelfReferential(string $sql): bool
    {
        $table = (string) config('monitor.storage.database.table', 'monitor_entries');

        return $table !== '' && str_contains(strtolower($sql), strtolower($table));
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
