<?php

namespace LaravelMonitor;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\Storage;
use Throwable;

class Monitor
{
    /** @var Entry[] */
    protected array $entries = [];

    protected bool $recording = true;

    /**
     * State of the HTTP request currently being recorded, or null outside a
     * request (console, queue workers). Mirrors Nightwatch's RequestState:
     * a shared per-request identity plus lifecycle stage boundaries, so every
     * entry recorded during the request can be correlated on the timeline.
     *
     * @var array{
     *     id: string,
     *     start: float,
     *     phases: array<int, array{name: string, start: int, duration: int}>,
     *     middleware_start: int,
     *     controller_start: ?int,
     *     composing: ?int,
     *     response_ready: ?int,
     *     terminating: ?int,
     *     queries: int,
     * }|null
     */
    protected ?array $request = null;

    /** The buffered root `request` entry, finalised (phases, duration) on flush. */
    protected ?Entry $pendingRequest = null;

    public function __construct(protected Application $app)
    {
    }

    /**
     * Buffer a new entry. It is persisted on flush (end of request/job) or
     * as soon as the buffer limit is reached.
     */
    public function record(
        string $type,
        ?string $key = null,
        array $payload = [],
        ?float $duration = null,
        ?string $subtype = null,
        int|string|null $userId = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $entry = new Entry(
            $type,
            $key,
            $payload,
            $duration,
            $subtype,
            $userId,
            $this->request['id'] ?? null,
            $this->startOffsetFor($type, $duration),
        );

        if ($type === 'request' && $this->request !== null) {
            $this->pendingRequest = $entry;
        }

        $this->entries[] = $entry;

        if (count($this->entries) >= (int) $this->app['config']->get('monitor.buffer', 200)) {
            $this->flush();
        }
    }

    /**
     * Where on the request timeline this entry started, in ms: an event's
     * start is "now minus how long it took". The root request itself starts
     * at zero.
     */
    protected function startOffsetFor(string $type, ?float $duration): ?int
    {
        if ($this->request === null) {
            return null;
        }

        if ($type === 'request') {
            return 0;
        }

        return max(0, (int) round($this->elapsedMs() - ($duration ?? 0)));
    }

    public function enabled(): bool
    {
        return $this->recording && (bool) $this->app['config']->get('monitor.enabled', true);
    }

    /**
     * Start tracking the current HTTP request. Called by the RecordTimeline
     * global middleware; offsets are measured from PHP's request start so
     * they line up with the recorded request duration.
     */
    public function beginRequest(): void
    {
        $start = (float) (request()->server('REQUEST_TIME_FLOAT')
            ?: (defined('LARAVEL_START') ? LARAVEL_START : microtime(true)));

        $this->request = [
            'id' => (string) Str::uuid(),
            'start' => $start,
            'phases' => [],
            'middleware_start' => 0,
            'controller_start' => null,
            'composing' => null,
            'response_ready' => null,
            'terminating' => null,
            'queries' => 0,
        ];

        $elapsed = $this->elapsedMs();

        $this->recordPhase('bootstrap', 0, $elapsed);
        $this->request['middleware_start'] = $elapsed;
    }

    public function requestId(): ?string
    {
        return $this->request['id'] ?? null;
    }

    /**
     * Count every query executed during the request, not just the slow ones
     * SlowQueries persists a full row for — mirrors Nightwatch's `queries`
     * counter, which tracks total query count independently of any
     * slow-query threshold.
     */
    public function incrementQueryCount(): void
    {
        if ($this->request !== null && $this->enabled()) {
            $this->request['queries']++;
        }
    }

    public function queryCount(): int
    {
        return $this->request['queries'] ?? 0;
    }

    /** Milliseconds elapsed since the request started, or null outside one. */
    public function elapsedMs(): ?int
    {
        if ($this->request === null) {
            return null;
        }

        return max(0, (int) round((microtime(true) - $this->request['start']) * 1000));
    }

    /**
     * Milliseconds elapsed since the request started, to 2 decimal places.
     * Used wherever a recorded `duration` is derived from wall-clock time —
     * phase boundaries/offsets stay whole milliseconds, they're only used
     * for ordering and layout.
     */
    public function elapsedMsPrecise(): ?float
    {
        if ($this->request === null) {
            return null;
        }

        return max(0.0, round((microtime(true) - $this->request['start']) * 1000, 2));
    }

    /**
     * Marks the middleware → controller boundary. Called by the
     * MarkControllerStart route-group middleware; first call wins.
     */
    public function markControllerStart(): void
    {
        if ($this->request === null || $this->request['controller_start'] !== null) {
            return;
        }

        $elapsed = $this->elapsedMs();

        $this->request['controller_start'] = $elapsed;
        $this->recordPhase('middleware', $this->request['middleware_start'], $elapsed - $this->request['middleware_start']);
    }

    public function controllerStartOffset(): ?int
    {
        return $this->request['controller_start'] ?? null;
    }

    /**
     * Marks the first view composition after the controller took over —
     * the best available signal that rendering has started.
     */
    public function markComposing(): void
    {
        if ($this->request === null
            || $this->request['controller_start'] === null
            || $this->request['composing'] !== null) {
            return;
        }

        $this->request['composing'] = $this->elapsedMs();
    }

    public function firstComposingOffset(): ?int
    {
        return $this->request['composing'] ?? null;
    }

    /** Marks the point where the response left the router (RequestHandled). */
    public function markResponseReady(): void
    {
        if ($this->request !== null && $this->request['response_ready'] === null) {
            $this->request['response_ready'] = $this->elapsedMs();
        }
    }

    /** Marks the start of the terminating phase (response already sent). */
    public function markTerminating(): void
    {
        if ($this->request !== null && $this->request['terminating'] === null) {
            $this->request['terminating'] = $this->elapsedMs();
        }
    }

    /** Append a named lifecycle phase (offsets/durations in ms). */
    public function recordPhase(string $name, int $start, int $duration): void
    {
        if ($this->request === null) {
            return;
        }

        $this->request['phases'][] = [
            'name' => $name,
            'start' => max(0, $start),
            'duration' => max(0, $duration),
        ];
    }

    /**
     * Complete the buffered root `request` entry right before it is stored:
     * add the sending/terminating phases (which only end at flush time),
     * attach the collected phases and extend the duration to cover the full
     * lifecycle — mirroring Nightwatch, whose request duration is the sum of
     * all execution stages including Terminating.
     */
    protected function finalizePendingRequest(): void
    {
        $entry = $this->pendingRequest;

        if ($entry === null || $this->request === null) {
            return;
        }

        $this->pendingRequest = null;

        $elapsed = $this->elapsedMs();
        $ready = $this->request['response_ready'];

        if ($ready !== null) {
            $sendingEnd = $this->request['terminating'] ?? $elapsed;
            $this->recordPhase('sending', $ready, $sendingEnd - $ready);
        }

        if ($this->request['terminating'] !== null) {
            $this->recordPhase('terminating', $this->request['terminating'], $elapsed - $this->request['terminating']);
        }

        $entry->payload['phases'] = $this->request['phases'];
        $entry->payload['query_count'] = $this->request['queries'];
        $entry->duration = max($entry->duration ?? 0.0, $this->elapsedMsPrecise() ?? 0.0);
    }

    /**
     * Persist all buffered entries through the configured storage driver.
     * Recording is paused while flushing so the storage writes themselves
     * (e.g. database queries) are never captured.
     */
    public function flush(): void
    {
        $this->finalizePendingRequest();

        if ($this->entries === []) {
            return;
        }

        $entries = $this->entries;
        $this->entries = [];

        $this->recording = false;

        try {
            $this->storage()->store($entries);
        } catch (Throwable $e) {
            // Monitoring must never take the application down, but a storage
            // failure (e.g. schema drift after an update whose migration
            // wasn't run) should still be diagnosable instead of silently
            // dropping every entry — mirrors Nightwatch reporting its own
            // internal exceptions rather than swallowing them outright.
            $this->reportStorageFailure($e);
        } finally {
            $this->recording = true;
        }
    }

    /**
     * Report a storage failure through the app's exception handler.
     * Recording is already paused by flush(), so this cannot recurse into
     * the Exceptions recorder.
     */
    protected function reportStorageFailure(Throwable $e): void
    {
        try {
            $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report($e);
        } catch (Throwable) {
            // The exception handler itself is unavailable; nothing more we can do.
        }
    }

    /**
     * Run a callback without recording anything it triggers.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function ignore(callable $callback): mixed
    {
        $previous = $this->recording;
        $this->recording = false;

        try {
            return $callback();
        } finally {
            $this->recording = $previous;
        }
    }

    public function stopRecording(): void
    {
        $this->recording = false;
    }

    public function startRecording(): void
    {
        $this->recording = true;
    }

    public function storage(): Storage
    {
        return $this->app->make(Storage::class);
    }
}
