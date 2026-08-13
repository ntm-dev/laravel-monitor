<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use LaravelMonitor\Support\RecordType;
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

        $this->monitor->incrementQueryCount();

        // Persist every query regardless of duration or execution context
        // (request, console command, queue worker) — the dashboard decides
        // what counts as "slow" at render time (see QueryDetail::data()'s
        // $slowThreshold), comparing the live config threshold against
        // each row's actual duration, rather than a fixed tag baked in
        // here at record time. A long-running worker can generate a lot of
        // rows this way; monitor.retention.hours / `monitor:prune` is the
        // backstop, not a per-query filter.
        $this->monitor->record(
            type: RecordType::Query,
            key: Sql::normalizeKey($event->sql),
            payload: [
                'sql' => $event->sql,
                'connection' => $event->connectionName,
                // The actual PDO connection role Laravel routed this query
                // to ('read'/'write'/'direct'), straight from the framework
                // — not guessed from the SQL verb, which only tells you the
                // statement is a SELECT vs a mutation, not which physical
                // connection (e.g. a read replica vs the write primary in a
                // sticky/read-write split config) it ran against. Only
                // available on Laravel >= 12.45 (readWriteType didn't exist
                // on QueryExecuted before that); null everywhere else.
                'connection_type' => property_exists($event, 'readWriteType') ? $event->readWriteType : null,
                'location' => $this->location(),
                // Only meaningful outside a request — inside one, the row
                // already carries request_id and the Query Detail page
                // resolves that back to "METHOD /path" itself.
                'command' => $this->monitor->requestId() === null ? $this->monitor->commandName() : null,
            ],
            duration: $event->time,
        );
    }

    /**
     * Whether the query touches one of Monitor's own tables — not just the
     * entries table, but every table the package's own dashboard reads on
     * every request (monitor_users, for the authenticated actor) or on
     * specific pages (Team's monitor_invitations/monitor_webauthn_credentials,
     * Issues' monitor_issues, ...). Table names are read live from config
     * since they're user-configurable via the Settings page
     * (Support\Settings::apply() overlays a saved override before any
     * request is handled).
     */
    protected function isSelfReferential(string $sql): bool
    {
        $sql = strtolower($sql);

        foreach ($this->ownTables() as $table) {
            if ($table !== '' && str_contains($sql, strtolower($table))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    protected function ownTables(): array
    {
        return [
            (string) config('monitor.storage.database.table', 'monitor_entries'),
            (string) config('monitor.aggregates.table', 'monitor_aggregates'),
            (string) config('monitor.issues.table', 'monitor_issues'),
            (string) config('monitor.auth.table', 'monitor_users'),
            (string) config('monitor.auth.invitations_table', 'monitor_invitations'),
            (string) config('monitor.auth.password_resets_table', 'monitor_password_resets'),
            (string) config('monitor.auth.email_changes_table', 'monitor_email_changes'),
            (string) config('monitor.auth.webauthn_table', 'monitor_webauthn_credentials'),
            (string) config('monitor.auth.oauth_accounts_table', 'monitor_oauth_accounts'),
        ];
    }

    /**
     * First application (non-vendor) frame that triggered the query.
     */
    protected function location(): ?string
    {
        [$file, $line] = $this->monitor->location->forQueryTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50));

        return $file ? ("{$file}:".($line ?? 0)) : null;
    }
}
