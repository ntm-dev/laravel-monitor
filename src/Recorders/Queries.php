<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use LaravelMonitor\LazyValue;
use LaravelMonitor\Support\QueryConnection;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Support\Sql;
use LaravelMonitor\Support\Trace;

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
        if ($this->isSelfReferential($event->sql) || $this->shouldIgnore()) {
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

        // PDO role the query ran under; only available on Laravel >= 12.45.
        $connectionType = property_exists($event, 'readWriteType') ? $event->readWriteType : null;

        // With a trace stored, the location is read back from it on display.
        $trace = ($this->config['details']['trace'] ?? false) ? Trace::capture() : null;

        $this->monitor->record(
            type: RecordType::Query,
            // Grouping key is only needed once the entry is stored.
            key: new LazyValue(static fn () => Sql::normalizeKey($event->sql)),
            subtype: QueryConnection::pack($event->connectionName, $connectionType),
            payload: [
                'sql' => $event->sql,
                'location' => $trace === null ? $this->location() : null,
                // Only meaningful outside a request — inside one, the row
                // already carries request_id and the Query Detail page
                // resolves that back to "METHOD /path" itself.
                'command' => $this->monitor->requestId() === null ? $this->monitor->commandName() : null,
                'trace' => $trace,
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
            if (str_contains($sql, $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower-cased, non-empty table names — resolved once per recorder rather
     * than re-read from config on every query.
     *
     * @var list<string>|null
     */
    protected ?array $ownTablesCache = null;

    /** @return list<string> */
    protected function ownTables(): array
    {
        return $this->ownTablesCache ??= array_values(array_filter(array_map(
            static fn (string $table) => strtolower($table),
            $this->configuredTables(),
        ), static fn (string $table) => $table !== ''));
    }

    /** @return list<string> */
    protected function configuredTables(): array
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
     * Cached shouldIgnore() result — computed once per request instead of
     * re-decoding the Livewire snapshot payload (see below) on every single
     * query it fires (a busy dashboard card can run dozens).
     */
    protected ?bool $shouldIgnoreCache = null;

    /**
     * Whether the current request is browsing the Monitor dashboard itself
     * — every query that fires while rendering/polling a dashboard page
     * (session lookups, cache reads, ...) would otherwise be attributed to
     * "app" activity, same reasoning as Recorders\Logs::shouldIgnore().
     */
    protected function shouldIgnore(): bool
    {
        return $this->shouldIgnoreCache ??= $this->monitor->isSelfRequest($this->config['ignore_paths'] ?? []);
    }

    /**
     * First application (non-vendor) frame that triggered the query.
     */
    protected function location(): LazyValue
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);
        $location = $this->monitor->location;

        // Only the backtrace itself has to be taken now; picking the frame
        // runs at flush.
        return new LazyValue(static function () use ($location, $frames): ?string {
            [$file, $line] = $location->forQueryTrace($frames);

            return $file ? ("{$file}:".($line ?? 0)) : null;
        });
    }
}
