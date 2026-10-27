<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\QueryExecuted;
use LaravelMonitor\LazyValue;
use LaravelMonitor\Support\QueryConnection;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Support\Sql;
use LaravelMonitor\Support\Trace;
use WeakMap;

use function array_splice;
use function count;
use function debug_backtrace;

class Queries extends Recorder
{
    /**
     * Statements that have started but not yet fired QueryExecuted. A
     * statement that throws never fires it, so whatever is left at flush
     * is a failed one.
     *
     * @var list<array{connection: string, sql: string, frames: array<int, array<string, mixed>>}>
     */
    protected array $inFlight = [];

    /** @var WeakMap<Connection, true>|null */
    protected ?WeakMap $watched = null;

    public function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, [$this, 'record']);
        $events->listen(ConnectionEstablished::class, fn (ConnectionEstablished $event) => $this->watch($event->connection));
        $this->monitor->flushing($this->recordFailed(...));

        if ($this->monitor->app->resolved('db')) {
            foreach ($this->monitor->app->make('db')->getConnections() as $connection) {
                $this->watch($connection);
            }
        }
    }

    public function record(QueryExecuted $event): void
    {
        $this->finished($event);
        // Covers Laravel versions that don't dispatch ConnectionEstablished.
        $this->watch($event->connection);

        // Skip queries against the package's own tables and from dashboard requests.
        if ($this->isSelfReferential($event->sql) || $this->shouldIgnore()) {
            return;
        }

        $this->monitor->incrementQueryCount();

        // Every query is stored; "slow" is decided at render time against the configured threshold.

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
                // Only outside a request; inside one, request_id already identifies the source.
                'command' => $this->monitor->requestId() === null ? $this->monitor->commandName() : null,
                'trace' => $trace,
            ],
            duration: $event->time,
        );
    }

    protected function watch(Connection $connection): void
    {
        $this->watched ??= new WeakMap();

        if (isset($this->watched[$connection])) {
            return;
        }

        $this->watched[$connection] = true;
        $connection->beforeExecuting($this->started(...));
    }

    /**
     * @param  array<mixed>  $bindings
     */
    protected function started(string $sql, array $bindings, Connection $connection): void
    {
        if (! $this->monitor->enabled() || $this->shouldIgnore()) {
            return;
        }

        $this->inFlight[] = [
            'connection' => $connection->getName(),
            'sql' => $sql,
            'frames' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50),
        ];
    }

    /**
     * Removes the matching started statement, newest first — a query run
     * from inside a QueryExecuted listener finishes before the one that
     * triggered it.
     */
    protected function finished(QueryExecuted $event): void
    {
        for ($i = count($this->inFlight) - 1; $i >= 0; $i--) {
            if ($this->inFlight[$i]['sql'] === $event->sql && $this->inFlight[$i]['connection'] === $event->connectionName) {
                array_splice($this->inFlight, $i, 1);

                return;
            }
        }
    }

    protected function recordFailed(): void
    {
        $failed = $this->inFlight;
        $this->inFlight = [];

        foreach ($failed as $statement) {
            $sql = $statement['sql'];

            if ($this->isSelfReferential($sql)) {
                continue;
            }

            $this->monitor->incrementQueryCount();

            $this->monitor->record(
                type: RecordType::Query,
                key: new LazyValue(static fn () => Sql::normalizeKey($sql)),
                subtype: QueryConnection::pack($statement['connection'], null),
                payload: [
                    'sql' => $sql,
                    'location' => $this->location($statement['frames']),
                    'command' => $this->monitor->requestId() === null ? $this->monitor->commandName() : null,
                    'trace' => null,
                    'failed' => true,
                ],
            );
        }
    }

    /**
     * Whether the query touches any of the package's own tables. Names come
     * from config, which Support\Settings::apply() may override.
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
     * Lower-cased, non-empty table names, resolved once per recorder.
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

    /** Cached shouldIgnore() result, computed once per recorder instance. */
    protected ?bool $shouldIgnoreCache = null;

    /** Whether the current request is the dashboard itself (or matches ignore_paths). */
    protected function shouldIgnore(): bool
    {
        return $this->shouldIgnoreCache ??= $this->monitor->isSelfRequest($this->config['ignore_paths'] ?? []);
    }

    /** First application (non-vendor) frame that triggered the query; resolved at flush. */
    protected function location(?array $frames = null): LazyValue
    {
        $frames ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);
        $location = $this->monitor->location;

        return new LazyValue(static function () use ($location, $frames): ?string {
            [$file, $line] = $location->forQueryTrace($frames);

            return $file ? ("{$file}:".($line ?? 0)) : null;
        });
    }
}
