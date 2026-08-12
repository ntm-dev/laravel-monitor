<?php

namespace LaravelMonitor;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Support\Location;
use Throwable;

class Monitor
{
    private float $timestamp;

    /** @var Entry[] */
    protected array $entries = [];

    protected bool $recording = true;

    /**
     * State of the HTTP request currently being recorded, or null outside a
     * request (console, queue workers): a shared per-request identity plus
     * a *live* lifecycle stage pointer
     * (`stage`) — not a set of independent timestamp markers — so every
     * entry recorded during the request can be tagged with the stage it
     * actually happened in (see `record()`), instead of being classified
     * after the fact by comparing its timestamp against stored phase
     * intervals (see `Support\Timeline::containingPhase()`, still used only
     * as a fallback for rows stored before this stage tag existed).
     *
     * @var array{
     *     id: string,
     *     start: float,
     *     phases: array<int, array{name: string, start: float, duration: float}>,
     *     stage: string,
     *     stage_start: float,
     *     queries: int,
     *     models: int,
     * }|null
     */
    protected ?array $request = null;

    /** The buffered root `request` entry, finalised (phases, duration) on flush. */
    protected ?Entry $pendingRequest = null;

    /**
     * Stack of queued job attempts currently being processed, outermost
     * first — same shape/purpose as $request but for jobs, so everything a
     * job's handle() triggers (queries, mail, notifications) can be
     * correlated onto a job attempt timeline the same way request children
     * are. A worker process handles jobs from the queue one at a time and
     * never concurrently with an HTTP request, so $request and this stack
     * are never both non-empty — but a job's own handle() can still dispatch
     * another job synchronously (e.g. dispatchSync(), or any queue
     * connection like 'sync' that fires JobProcessing/JobProcessed
     * in-process instead of through a separate worker), nesting a second
     * attempt inside the first. A stack (rather than a single nullable
     * frame) keeps the outer attempt's id/start/models intact while the
     * inner one is active, so entries recorded after the inner attempt ends
     * still correlate back onto the outer one instead of losing correlation
     * entirely.
     *
     * @var list<array{id: string, start: float, models: int}>
     */
    protected array $jobStack = [];

    /**
     * State of the artisan command currently running, or null outside one —
     * same shape/purpose as $job but for console commands, so anything a
     * command triggers (queries, mail, notifications, jobs it dispatches)
     * correlates onto its own timeline the same way. A console process runs
     * one command at a time, never concurrently with a request or a queued
     * job attempt, so at most one of $request/$job/$command is ever set.
     *
     * @var array{
     *     id: string,
     *     name: string,
     *     start: float,
     *     phases: array<int, array{name: string, start: float, duration: float}>,
     *     stage: string,
     *     stage_start: float,
     *     models: int,
     *     pid: ?int,
     *     scheduled_task_run_id: ?string,
     * }|null
     */
    protected ?array $command = null;

    /** The buffered root `command` entry, finalised (phases, duration) on flush — mirrors $pendingRequest. */
    protected ?Entry $pendingCommand = null;

    /**
     * State of the scheduled task currently running, or null outside one —
     * same shape/purpose as $command, but for `Schedule::call()`/closure
     * tasks executing directly in the scheduler's own process. Command-based
     * tasks (`Schedule::command()`) always run as a *separate* `php artisan`
     * subprocess even when "foreground" (see
     * Illuminate\Console\Scheduling\Event::execute()) — that subprocess mints
     * its own, independent `command` entry (own id, own timeline) rather than
     * sharing this one, and only references this run's id in its payload
     * (see beginScheduledTaskRun(), Recorders\Commands::recordStarting()):
     * everything the subprocess triggers happened well after the scheduler
     * itself finished dispatching it, so it belongs on the command's own
     * timeline, not this one.
     *
     * @var array{id: string, start: float, models: int}|null
     */
    protected ?array $scheduledTask = null;

    /**
     * Context key a scheduled task's own id rides under across the process
     * boundary a command-based task's own `php artisan` subprocess creates,
     * so that subprocess's own `command` entry can reference which task
     * dispatched it. Laravel's scheduler already dehydrates the whole
     * Context into that subprocess's `__LARAVEL_CONTEXT` env var and
     * rehydrates it there before any application code runs (see
     * Illuminate\Log\Context\ContextServiceProvider) — riding on that
     * existing, framework-native mechanism instead of a bespoke env var means
     * no changes are needed to how the subprocess is spawned at all.
     */
    protected const SCHEDULED_TASK_CONTEXT_KEY = 'monitor_scheduled_task_id';

    public function __construct(
        protected Application $app,
        public Location $location,
    ) {
    }

    public function timestamp(float $timestamp): float
    {
        return $this->timestamp ??= $timestamp;
    }

    public function laravelVersion(): ?string
    {
        try {
            return $this->app->version();
        } catch (Throwable) {
            return null;
        }
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

        // Live-tag the entry with the request's (or command's) current stage
        // — the fix for queries that run while a middleware is unwinding
        // after `$next()` (e.g. session persistence) getting swept into
        // whatever phase happened to be open the longest, purely because
        // their stored start_offset fell inside that phase's interval.
        if ($type !== 'request' && $this->request !== null) {
            $payload['phase'] = $this->request['stage'];
        } elseif ($type !== 'command' && $this->command !== null) {
            $payload['phase'] = $this->command['stage'];
        }

        $entry = new Entry(
            $type,
            $key,
            $payload,
            $duration,
            $subtype,
            $userId,
            // scheduledTask before command: a command-based scheduled task's
            // own subprocess starts life already inside the outer
            // schedule:run command's own $command context (its
            // CommandStarting fired first) — a fresh $scheduledTask, begun
            // right before this specific task ran, must win so everything
            // it triggers correlates onto *this* task's run, not onto
            // schedule:run itself.
            $this->request['id'] ?? $this->currentJob()['id'] ?? $this->scheduledTask['id'] ?? $this->command['id'] ?? null,
            $this->startOffsetFor($type, $duration),
        );

        if ($type === 'request' && $this->request !== null) {
            $this->pendingRequest = $entry;
        }

        if ($type === 'command' && $this->command !== null) {
            $this->pendingCommand = $entry;
        }

        $this->entries[] = $entry;

        // `php artisan tinker` forks the whole PsySH REPL loop into a child
        // process (Psy\ExecutionLoop\ProcessForker), so every query/job/mail/
        // notification/... a user triggers interactively runs there — then
        // the child reports back over a socket and SIGKILLs itself. SIGKILL
        // skips PHP shutdown functions entirely, so CommandFinished (and the
        // flush() Commands::recordFinished() triggers from it) only ever
        // fires back in the *parent*, which never executed any of that code
        // and has nothing of it buffered. Detecting the fork — the live pid
        // no longer matches the one recorded when the run began — and
        // flushing immediately closes that window: a doomed child persists
        // its own entries before it can be killed, instead of losing them.
        if (
            $this->command !== null
            && $this->command['pid'] !== null
            && function_exists('posix_getpid')
            && posix_getpid() !== $this->command['pid']
        ) {
            $this->flush();
        } elseif (count($this->entries) >= (int) $this->app['config']->get('monitor.buffer', 200)) {
            $this->flush();
        }
    }

    /**
     * Where on the request timeline this entry started, in ms: an event's
     * start is "now minus how long it took". The root request itself starts
     * at zero. Measured via elapsedMsPrecise() (not elapsedMs()) so the
     * offset keeps microsecond precision instead of being floored to a
     * whole millisecond before it ever reaches storage.
     */
    protected function startOffsetFor(string $type, ?float $duration): ?float
    {
        if ($this->request !== null) {
            if ($type === 'request') {
                return 0.0;
            }

            return max(0.0, round($this->elapsedMsPrecise() - ($duration ?? 0), 3));
        }

        if (($job = $this->currentJob()) !== null) {
            if ($type === 'job') {
                return 0.0;
            }

            $elapsed = max(0.0, round((microtime(true) - $job['start']) * 1000, 3));

            return max(0.0, round($elapsed - ($duration ?? 0), 3));
        }

        if ($this->scheduledTask !== null) {
            if ($type === 'scheduled_task') {
                return 0.0;
            }

            $elapsed = max(0.0, round((microtime(true) - $this->scheduledTask['start']) * 1000, 3));

            return max(0.0, round($elapsed - ($duration ?? 0), 3));
        }

        if ($this->command !== null) {
            if ($type === 'command') {
                return 0.0;
            }

            $elapsed = max(0.0, round((microtime(true) - $this->command['start']) * 1000, 3));

            return max(0.0, round($elapsed - ($duration ?? 0), 3));
        }

        return null;
    }

    public function enabled(): bool
    {
        return $this->recording && (bool) $this->app['config']->get('monitor.enabled', true);
    }

    /**
     * Start tracking the current HTTP request. Called from
     * MonitorServiceProvider's app->booted() callback, once the framework
     * has finished booting but before any middleware has run; offsets are
     * measured from PHP's request start so they line up with the recorded
     * request duration.
     *
     * LARAVEL_START (set on the first line of public/index.php) takes
     * priority over REQUEST_TIME_FLOAT (set by the SAPI before PHP even
     * starts executing the script), so duration doesn't include
     * webserver/PHP bootstrap time outside Laravel's own lifecycle.
     */
    public function beginRequest(): void
    {
        $this->request = [
            'id' => (string) Str::uuid(),
            'start' => $this->timestamp ?? microtime(true),
            'phases' => [],
            'stage' => 'middleware',
            'stage_start' => 0,
            'queries' => 0,
            'models' => 0,
        ];

        $elapsed = $this->elapsedMsPrecise();

        $this->recordPhase('bootstrap', 0, $elapsed);
        $this->request['stage_start'] = $elapsed;
    }

    public function requestId(): ?string
    {
        return $this->request['id'] ?? null;
    }

    /**
     * Starts tracking a queued job attempt. Called by the Jobs recorder on
     * JobProcessing, before the job's own handle() runs — everything it
     * triggers (queries, mail, notifications, cache) picks up this id via
     * record()'s request_id fallback, the same way an HTTP request's
     * children do.
     */
    public function beginJobAttempt(): void
    {
        $this->jobStack[] = [
            'id' => (string) Str::uuid(),
            'start' => microtime(true),
            'models' => 0,
            'attempts' => 1,
        ];
    }

    public function jobAttemptId(): ?string
    {
        return $this->currentJob()['id'] ?? null;
    }

    /**
     * Called by the Jobs recorder once the attempt's own `job` entry has
     * been recorded. Pops just the innermost attempt — if handle() dispatched
     * a nested job synchronously, this restores the outer attempt as current
     * rather than clearing job-tracking state entirely.
     */
    public function endJobAttempt(): void
    {
        array_pop($this->jobStack);
    }

    /** @return array{id: string, start: float, models: int}|null */
    protected function currentJob(): ?array
    {
        return $this->jobStack === [] ? null : $this->jobStack[array_key_last($this->jobStack)];
    }

    /**
     * Starts tracking an artisan command run. Called by the Commands
     * recorder on CommandStarting, before the command's own handle() runs —
     * everything it triggers (queries, mail, notifications, jobs) picks up
     * this id via record()'s request_id fallback, the same way an HTTP
     * request's or a job attempt's children do. Always mints its own fresh
     * id, even for a command-based scheduled task's own subprocess — see
     * $scheduledTaskRunId.
     *
     * @param  ?string  $scheduledTaskRunId  when this run is a command-based
     *                                 scheduled task's own subprocess, the
     *                                 dispatching task's own run id (see
     *                                 inheritedScheduledTaskRunId()) — stamped
     *                                 into this run's own entry (see
     *                                 finalizePendingCommand()) purely as a
     *                                 cross-reference the dashboard uses to
     *                                 link the two runs together. Everything
     *                                 this subprocess triggers still belongs
     *                                 on *its own* timeline: it all happened
     *                                 after the scheduler already finished
     *                                 dispatching it.
     */
    public function beginCommandRun(string $name, ?string $scheduledTaskRunId = null): void
    {
        $this->command = [
            'id' => (string) Str::uuid(),
            'name' => $name,
            // LARAVEL_START (the artisan entry script sets this the same way
            // public/index.php does for requests) rather than "now" — so the
            // 'bootstrap' phase below, and every child entry's start_offset,
            // account for the framework boot that already happened before
            // CommandStarting fired, the same way beginRequest() does.
            'start' => $this->timestamp ?? microtime(true),
            'phases' => [],
            'stage' => 'action',
            'stage_start' => 0.0,
            'models' => 0,
            // The pid this run actually started under — see record()'s fork
            // detection, which this exists solely to support.
            'pid' => function_exists('posix_getpid') ? posix_getpid() : null,
            'scheduled_task_run_id' => $scheduledTaskRunId,
        ];

        $elapsed = $this->commandElapsedMsPrecise() ?? 0.0;

        $this->recordCommandPhase('bootstrap', 0, $elapsed);
        $this->command['stage_start'] = $elapsed;
    }

    /** Milliseconds elapsed since the running command's own process started (LARAVEL_START), or null outside one. */
    public function commandElapsedMsPrecise(): ?float
    {
        if ($this->command === null) {
            return null;
        }

        return max(0.0, round((microtime(true) - $this->command['start']) * 1000, 3));
    }

    /** Append a named lifecycle phase for the running command (offsets/durations in ms, microsecond precision). */
    public function recordCommandPhase(string $name, int|float $start, int|float $duration): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command['phases'][] = [
            'name' => $name,
            'start' => max(0, $start),
            'duration' => max(0, $duration),
        ];
    }

    /**
     * Marks the handle()-done → terminating boundary. Called by the Commands
     * recorder on CommandFinished, before the command's own entry is
     * recorded — mirrors markTerminating(), but a command only ever has the
     * one 'action' phase to close out first (no controller/render/unwinding
     * steps of its own).
     */
    public function markCommandTerminating(): void
    {
        if ($this->command === null || $this->command['stage'] === 'terminating') {
            return;
        }

        $now = $this->commandElapsedMsPrecise();

        $this->recordCommandPhase($this->command['stage'], $this->command['stage_start'], $now - $this->command['stage_start']);

        $this->command['stage'] = 'terminating';
        $this->command['stage_start'] = $now;
    }

    public function commandRunId(): ?string
    {
        return $this->command['id'] ?? null;
    }

    /** The running command's name, e.g. "app:sync-data" — used to label queries recorded outside a request/job. */
    public function commandName(): ?string
    {
        return $this->command['name'] ?? null;
    }

    /** Called by the Commands recorder once the run's own `command` entry has been recorded. */
    public function endCommandRun(): void
    {
        $this->command = null;
    }

    /**
     * Starts tracking a scheduled task run. Called by the ScheduledTasks
     * recorder on ScheduledTaskStarting, before the task itself runs —
     * everything a closure/`Schedule::call()` task triggers in-process picks
     * up this id via record()'s request_id fallback. Also stamped onto
     * Context so a command-based task's own subprocess can reference it —
     * see SCHEDULED_TASK_CONTEXT_KEY.
     */
    public function beginScheduledTaskRun(): void
    {
        $id = (string) Str::uuid();

        $this->scheduledTask = [
            'id' => $id,
            'start' => microtime(true),
            'models' => 0,
        ];

        // Context is Laravel 11+ only (this package supports 10-13) — on 10,
        // a command-based task's subprocess simply won't correlate back to
        // its parent scheduled_task entry (same as "no such correlation
        // exists" elsewhere), rather than fatal on a missing class.
        if (class_exists(Context::class)) {
            Context::add(self::SCHEDULED_TASK_CONTEXT_KEY, $id);
        }
    }

    public function scheduledTaskRunId(): ?string
    {
        return $this->scheduledTask['id'] ?? null;
    }

    /** Called by the ScheduledTasks recorder once the run's own `scheduled_task` entry has been recorded. */
    public function endScheduledTaskRun(): void
    {
        $this->scheduledTask = null;

        if (class_exists(Context::class)) {
            Context::forget(self::SCHEDULED_TASK_CONTEXT_KEY);
        }
    }

    /**
     * The scheduled-task-run id inherited from the parent scheduler process
     * via Laravel's own Context dehydration/hydration, or null when running
     * standalone (a manually-invoked command, or one running outside any
     * scheduled task). Read by the Commands recorder on CommandStarting so a
     * command-based task's own subprocess can stamp which scheduled task
     * dispatched it into its own `command` entry's payload (see
     * beginCommandRun()) — a reference for the dashboard to link the two
     * runs together, not an id this run adopts as its own: everything this
     * subprocess triggers happened after the scheduler already finished
     * dispatching it, so it belongs on *this* run's own timeline instead.
     */
    public function inheritedScheduledTaskRunId(): ?string
    {
        if (! class_exists(Context::class)) {
            return null;
        }

        $id = Context::get(self::SCHEDULED_TASK_CONTEXT_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Correlation id shared between a mail-channel notification's own entry
     * and the `mail` entry its send produces, set while that channel's send
     * is in flight (between `NotificationSending` and `NotificationSent`).
     * One scalar is enough — same reasoning as CacheInteractions'
     * `$startedAt`: Laravel dispatches a notification's channels
     * sequentially within a single request/job, never concurrently, so
     * nothing else can interleave and overwrite it mid-flight.
     */
    protected ?string $pendingNotificationCorrelationId = null;

    /**
     * Starts a new correlation window for a mail-channel notification about
     * to be sent, returning the id both the Notifications and Mail
     * recorders should stamp onto their respective entries.
     */
    public function beginNotificationDispatch(): string
    {
        return $this->pendingNotificationCorrelationId = (string) Str::uuid();
    }

    /** The in-flight correlation id, or null outside a mail-channel notification dispatch. */
    public function pendingNotificationCorrelationId(): ?string
    {
        return $this->pendingNotificationCorrelationId;
    }

    public function endNotificationDispatch(): void
    {
        $this->pendingNotificationCorrelationId = null;
    }

    /**
     * Count every query executed during the request, tracked independently
     * of the Queries recorder's slow/fast tagging.
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

    /**
     * Count every Eloquent model hydrated during the current request, job
     * attempt or command run — mirrors incrementQueryCount(), but across
     * whichever context is active (a job/command has no bootstrap/
     * middleware phases of its own, so unlike queries it can't fall back to
     * "outside a request means uncounted").
     */
    public function incrementModelCount(): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($this->request !== null) {
            $this->request['models']++;
        } elseif ($this->jobStack !== []) {
            $this->jobStack[array_key_last($this->jobStack)]['models']++;
        } elseif ($this->scheduledTask !== null) {
            $this->scheduledTask['models']++;
        } elseif ($this->command !== null) {
            $this->command['models']++;
        }
    }

    public function modelCount(): int
    {
        return $this->request['models'] ?? $this->currentJob()['models'] ?? $this->scheduledTask['models'] ?? $this->command['models'] ?? 0;
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
     * Milliseconds elapsed since the request started, to 3 decimal places
     * (microsecond precision). Used wherever a recorded `duration` or
     * `start_offset` is derived from wall-clock time, including lifecycle
     * phase boundaries (bootstrap/middleware/...) — a whole-millisecond
     * elapsedMs() would round phases shorter than 1ms (e.g. "sending") down
     * to a reported 0ms.
     */
    public function elapsedMsPrecise(): ?float
    {
        if ($this->request === null) {
            return null;
        }

        return max(0.0, round((microtime(true) - $this->request['start']) * 1000, 3));
    }

    /**
     * Marks the middleware → controller boundary. Called from a closure
     * middleware attached directly onto the matched route at RouteMatched
     * time (see Hooks\ControllerStartHook), as the route's own last
     * middleware entry — i.e. after every other route middleware's
     * `handle()` has run, right before the controller.
     */
    public function markControllerStart(): void
    {
        $this->transitionStage('controller', from: 'middleware');
    }

    /**
     * Marks the controller → render boundary. Called on
     * Illuminate\Routing\Events\PreparingResponse, dispatched by
     * Router::prepareResponse() while still deep inside the route's own
     * middleware pipeline (Route::run()'s Pipeline `then()` callback) —
     * before bubbling back out through any middleware's post-`$next()` code.
     */
    public function markRenderStart(): void
    {
        $this->transitionStage('render', from: 'controller');
    }

    /**
     * Marks the render → unwinding boundary. Called on
     * Illuminate\Routing\Events\ResponsePrepared, dispatched immediately
     * after PreparingResponse, once the Response object is finalized — from
     * here on, only middleware's own post-`$next()` work remains (e.g.
     * StartSession::handleStatefulRequest() persisting the session), which
     * is genuinely "still middleware", not "still rendering".
     */
    public function markUnwinding(): void
    {
        $this->transitionStage('unwinding', from: 'render');
    }

    /**
     * Marks the point every middleware (route and global alike) has finished
     * unwinding and the response is about to be sent. Called on
     * Illuminate\Foundation\Http\Events\RequestHandled, which fires only
     * after the *entire* middleware pipeline has returned — whatever stage
     * the request is still in gets closed out here, not just 'render' or
     * 'unwinding', so an early return/exception that skipped those still
     * gets a sensible boundary instead of an open-ended phase.
     */
    public function markResponseReady(): void
    {
        $this->transitionStage('sending');
    }

    /** Marks the start of the terminating phase (response already sent). */
    public function markTerminating(): void
    {
        $this->transitionStage('terminating');
    }

    /**
     * Move to a new named lifecycle stage, closing out the phase the
     * request was previously in. Replaces the old approach of recording
     * a handful of independent timestamp markers and matching every entry's
     * stored start_offset against them after the fact (still available as
     * Support\Timeline::containingPhase(), kept only as a fallback for rows
     * stored before this stage tag existed): that approach couldn't tell
     * "still rendering the view" apart from "a middleware doing its own
     * post-`$next()` work" — both looked identical (just elapsed time)
     * once measured from outside. Tagging entries with the *live* stage at
     * record() time (see `record()`) removes that ambiguity entirely.
     *
     * @param  ?string  $from  if given, the transition is ignored unless the
     *                         request is currently in this stage — guards
     *                         against a framework event firing out of order,
     *                         more than once, or not at all.
     */
    protected function transitionStage(string $stage, ?string $from = null): void
    {
        if ($this->request === null || $this->request['stage'] === $stage) {
            return;
        }

        if ($from !== null && $this->request['stage'] !== $from) {
            return;
        }

        $now = $this->elapsedMsPrecise();

        $this->recordPhase($this->request['stage'], $this->request['stage_start'], $now - $this->request['stage_start']);

        $this->request['stage'] = $stage;
        $this->request['stage_start'] = $now;
    }

    /** Append a named lifecycle phase (offsets/durations in ms, microsecond precision). */
    public function recordPhase(string $name, int|float $start, int|float $duration): void
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
     * close out whichever stage the request was still in (mirroring every
     * other stage transition), attach the collected phases and extend the
     * duration to cover the full lifecycle, so the recorded request
     * duration is the sum of every execution stage including Terminating.
     */
    protected function finalizePendingRequest(): void
    {
        $entry = $this->pendingRequest;

        if ($entry === null || $this->request === null) {
            return;
        }

        $this->pendingRequest = null;

        $elapsed = $this->elapsedMsPrecise();

        $this->recordPhase($this->request['stage'], $this->request['stage_start'], $elapsed - $this->request['stage_start']);

        $entry->payload['phases'] = $this->request['phases'];
        $entry->payload['query_count'] = $this->request['queries'];
        $entry->payload['model_count'] = $this->request['models'];
        $entry->duration = max($entry->duration ?? 0.0, $elapsed ?? 0.0);
    }

    /**
     * Complete the buffered root `command` entry right before it is stored —
     * mirrors finalizePendingRequest(): closes out whichever stage the
     * command was still in (always 'terminating' in practice, since
     * Commands::recordFinished() calls markCommandTerminating() just before
     * recording this entry), attaches the collected phases and extends the
     * duration to cover the full lifecycle (bootstrap through terminating),
     * not just the action/handle() portion Commands.php itself measures.
     */
    protected function finalizePendingCommand(): void
    {
        $entry = $this->pendingCommand;

        if ($entry === null || $this->command === null) {
            return;
        }

        $this->pendingCommand = null;

        $elapsed = $this->commandElapsedMsPrecise();

        $this->recordCommandPhase($this->command['stage'], $this->command['stage_start'], $elapsed - $this->command['stage_start']);

        $entry->payload['phases'] = $this->command['phases'];
        // The run's real start (LARAVEL_START), stamped the same way
        // Recorders\Requests/Jobs stamp theirs — created_at is only stored
        // at second precision and marks the run's *end*, so reconstructing
        // "started at" from created_at - duration disagrees with this by up
        // to a whole second (see Support\Format::startedAt()).
        $entry->payload['started_at'] = $this->command['start'];

        // Only set for a command-based scheduled task's own subprocess (see
        // beginCommandRun()'s $scheduledTaskRunId param) — the same
        // correlation_id mechanism Recorders\Mail/Notifications use to pair
        // their own two entries (see Contracts\Storage::findByCorrelationId()),
        // reused here so CommandRunController can look up the dispatching
        // scheduled_task entry to link to, and ScheduleRunController the
        // reverse. Purely a cross-reference — this run's own timeline stays
        // entirely its own regardless (see startOffsetFor()).
        if ($this->command['scheduled_task_run_id'] !== null) {
            $entry->payload['correlation_id'] = $this->command['scheduled_task_run_id'];
        }

        $entry->duration = max($entry->duration ?? 0.0, $elapsed ?? 0.0);
    }

    /**
     * Persist all buffered entries through the configured storage driver.
     * Recording is paused while flushing so the storage writes themselves
     * (e.g. database queries) are never captured.
     */
    public function flush(): void
    {
        $this->finalizePendingRequest();
        $this->finalizePendingCommand();

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
            // dropping every entry — reported rather than swallowed outright.
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
